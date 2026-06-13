// ============================================================
//  Backups de la carpeta data/
//  ───────────────────────────
//  Snapshot por hora del estado completo (wiki, CRM, compromisos,
//  tareas, historial, etc.) como tarball comprimido. Rotación local
//  de 24 snapshots (1 día). Si BACKUP_SYNC_CMD está definido, se
//  ejecuta después de cada snapshot — úsalo para sincronizar a
//  S3/R2/B2/Dropbox vía rclone, restic, awscli, etc.
//
//  Ejemplo BACKUP_SYNC_CMD:
//    rclone copy {file} r2:athena-backups/
//    aws s3 cp {file} s3://athena-backups/
//
//  El token {file} se sustituye por el path del snapshot recién creado.
// ============================================================
import { exec } from 'node:child_process';
import { promisify } from 'node:util';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync, statSync, unlinkSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { S3Client, PutObjectCommand, ListObjectsV2Command, GetObjectCommand } from '@aws-sdk/client-s3';

const execP = promisify(exec);
const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const BACKUP_DIR = join(__dirname, '..', 'backups');
const KEEP_LOCAL = parseInt(process.env.BACKUP_KEEP_LOCAL || '24', 10);

function timestamp() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}`;
}

export async function snapshot() {
  if (!existsSync(DATA_DIR)) return { ok: false, reason: 'data/ no existe — nada que respaldar.' };
  if (!existsSync(BACKUP_DIR)) mkdirSync(BACKUP_DIR, { recursive: true });
  const file = join(BACKUP_DIR, `snapshot_${timestamp()}.tar.gz`);

  // -C cambia el directorio de trabajo antes de archivar — así el
  // tarball contiene "data/..." sin el prefijo absoluto.
  await execP(`tar czf "${file}" -C "${dirname(DATA_DIR)}" "${basename(DATA_DIR)}"`);
  rotateLocal();

  let synced = null;
  // Modo A: comando externo (rclone, restic, awscli, scp…)
  if (process.env.BACKUP_SYNC_CMD) {
    const cmd = process.env.BACKUP_SYNC_CMD.replace(/\{file\}/g, file);
    try {
      await execP(cmd, { timeout: 60_000 });
      synced = cmd;
    } catch (err) {
      console.warn('[backup] BACKUP_SYNC_CMD falló:', err.message);
    }
  }
  // Modo B: subir directo a S3-compatible (R2, B2, S3, MinIO, etc.)
  //         sin necesidad de instalar binarios en el server.
  if (process.env.BACKUP_S3_BUCKET && process.env.BACKUP_S3_ACCESS_KEY_ID) {
    try {
      await uploadToS3(file);
      synced = synced ? `${synced} + s3` : 's3';
    } catch (err) {
      console.warn('[backup] subida S3/R2 falló:', err.message);
    }
  }
  return { ok: true, file, synced };
}

// ============================================================
//  Restore — si la memoria de Athena arranca VACÍA (volumen recién
//  montado, o disco efímero que se borró en el deploy) pero hay un
//  backup, lo recupera. Candado: SOLO restaura si data/ está vacío —
//  nunca encima de datos vivos.
// ============================================================

// ¿data/ está "vacío" de estado real? El marcador de persistencia no cuenta.
export function isDataEmpty(dir = DATA_DIR) {
  if (!existsSync(dir)) return true;
  const meaningful = readdirSync(dir)
    .filter((f) => f.endsWith('.json') && f !== '.persistence_marker.json');
  return meaningful.length === 0;
}

// De una lista de nombres/keys, devuelve el snapshot más reciente.
// El timestamp va en el nombre (YYYYMMDD_HHMM, cero-padded) → orden léxico = cronológico.
export function pickLatestSnapshotName(names) {
  const snaps = (names || [])
    .filter((n) => /snapshot_\d{8}_\d{4}\.tar\.gz$/.test((n || '').split('/').pop() || ''));
  if (!snaps.length) return null;
  return snaps.sort().pop();
}

function s3Configured() {
  return Boolean(process.env.BACKUP_S3_BUCKET && process.env.BACKUP_S3_ACCESS_KEY_ID);
}

async function downloadLatestFromS3() {
  const Bucket = process.env.BACKUP_S3_BUCKET;
  const prefix = (process.env.BACKUP_S3_PREFIX || 'athena').replace(/\/+$/, '');
  const list = await getS3Client().send(new ListObjectsV2Command({ Bucket, Prefix: `${prefix}/` }));
  const latest = pickLatestSnapshotName((list.Contents || []).map((o) => o.Key));
  if (!latest) throw new Error('sin snapshots en R2');
  const obj = await getS3Client().send(new GetObjectCommand({ Bucket, Key: latest }));
  const bytes = await obj.Body.transformToByteArray();
  if (!existsSync(BACKUP_DIR)) mkdirSync(BACKUP_DIR, { recursive: true });
  const dest = join(BACKUP_DIR, basename(latest));
  writeFileSync(dest, Buffer.from(bytes));
  return dest;
}

export async function restoreIfEmpty() {
  if (!isDataEmpty()) return { restored: false, reason: 'data/ ya tiene contenido — no se toca' };

  let tarPath = null;
  let source = null;
  // 1. R2 es la fuente durable (sobrevive aunque el disco sea efímero).
  if (s3Configured()) {
    try { tarPath = await downloadLatestFromS3(); source = 'r2'; }
    catch (e) { console.warn('[restore] R2 no disponible:', e.message); }
  }
  // 2. Fallback: snapshot local más reciente (si el volumen de backups persistió).
  if (!tarPath && existsSync(BACKUP_DIR)) {
    const local = pickLatestSnapshotName(readdirSync(BACKUP_DIR));
    if (local) { tarPath = join(BACKUP_DIR, local); source = 'local'; }
  }
  if (!tarPath) return { restored: false, reason: 'no hay backup disponible (ni R2 ni local)' };

  // 3. Extrae dentro del padre de data/ — el tarball ya contiene "data/...".
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  await execP(`tar xzf "${tarPath}" -C "${dirname(DATA_DIR)}"`);
  return { restored: true, source, file: basename(tarPath) };
}

let _s3 = null;
function getS3Client() {
  if (_s3) return _s3;
  const region = process.env.BACKUP_S3_REGION || 'auto';
  const endpoint = process.env.BACKUP_S3_ENDPOINT; // ej. https://<acct>.r2.cloudflarestorage.com
  _s3 = new S3Client({
    region,
    endpoint,
    credentials: {
      accessKeyId: process.env.BACKUP_S3_ACCESS_KEY_ID,
      secretAccessKey: process.env.BACKUP_S3_SECRET_ACCESS_KEY,
    },
    forcePathStyle: true, // R2/MinIO lo prefieren
  });
  return _s3;
}

async function uploadToS3(filePath) {
  const Bucket = process.env.BACKUP_S3_BUCKET;
  const prefix = (process.env.BACKUP_S3_PREFIX || 'athena').replace(/\/+$/, '');
  const Key = `${prefix}/${basename(filePath)}`;
  const body = readFileSync(filePath);
  await getS3Client().send(new PutObjectCommand({
    Bucket, Key, Body: body, ContentType: 'application/gzip',
  }));
}

function rotateLocal() {
  if (!existsSync(BACKUP_DIR)) return;
  const files = readdirSync(BACKUP_DIR)
    .filter((f) => f.startsWith('snapshot_') && f.endsWith('.tar.gz'))
    .map((f) => ({ name: f, path: join(BACKUP_DIR, f), mtime: statSync(join(BACKUP_DIR, f)).mtimeMs }))
    .sort((a, b) => b.mtime - a.mtime);
  for (const f of files.slice(KEEP_LOCAL)) {
    try { unlinkSync(f.path); } catch { /* ignore */ }
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  console.log(await snapshot());
  process.exit(0);
}
