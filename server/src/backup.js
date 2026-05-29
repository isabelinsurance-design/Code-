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
import { existsSync, mkdirSync, readdirSync, statSync, unlinkSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

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
  if (process.env.BACKUP_SYNC_CMD) {
    const cmd = process.env.BACKUP_SYNC_CMD.replace(/\{file\}/g, file);
    try {
      await execP(cmd, { timeout: 60_000 });
      synced = cmd;
    } catch (err) {
      console.warn('[backup] sync command falló:', err.message);
    }
  }
  return { ok: true, file, synced };
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
