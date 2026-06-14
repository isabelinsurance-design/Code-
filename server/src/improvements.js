// ============================================================
//  Improvements — Athena propone cambios al código
//  ─────────────────────────────────────────────────
//  Cuando Athena se da cuenta que necesita una capacidad nueva
//  (un tool que no existe, un comportamiento mejor, un bug en
//  su propio loop) NO se la queda. Llama proponer_mejora.
//
//  Eso dispara 2 acciones:
//    1. Guarda spec estructurado en data/improvements.json
//    2. Email a Isabel + crea GitHub issue (si está configurado)
//
//  Yo (Claude Code) leo el GitHub issue, abro PR, Isabel/Sami
//  mergea. Loop cerrado.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'improvements.json');

export const PRIORIDADES = ['baja', 'media', 'alta'];
export const STATUS = ['pendiente', 'aprobada', 'descartada', 'implementada'];

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); atomicWriteJson(FILE, d.slice(-200)); }
function newId() { return `imp_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

// Genera el markdown body que va al email Y al GitHub issue.
// Estructurado para que Claude Code lo lea directo y arme PR.
function buildSpecMarkdown(entry) {
  return `## Contexto
${entry.contexto}

## Problema
${entry.problema}

## Propuesta
${entry.propuesta}

${entry.tool_sugerido ? `## Tool sugerido
\`${entry.tool_sugerido}\`

` : ''}${entry.archivos_afectados?.length ? `## Archivos probables a tocar
${entry.archivos_afectados.map((f) => `- \`${f}\``).join('\n')}

` : ''}## Prioridad
${entry.prioridad}

## Origen
Detectado por Athena: ${entry.disparador || '(sin disparador específico)'}
Fecha: ${entry.creado}
ID: \`${entry.id}\``;
}

// Llama a la GitHub API para crear el issue.
// Requiere GITHUB_TOKEN + GITHUB_REPO env vars.
// Retorna { ok, url, error }.
async function createGitHubIssue({ titulo, body, prioridad }) {
  const token = process.env.GITHUB_TOKEN;
  const repo = process.env.GITHUB_REPO; // "owner/repo"
  if (!token || !repo) {
    return { ok: false, error: 'GITHUB_TOKEN o GITHUB_REPO no configurados' };
  }
  const labels = ['athena-propuesta', `prioridad-${prioridad}`];
  try {
    const res = await fetch(`https://api.github.com/repos/${repo}/issues`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ title: titulo, body, labels }),
    });
    if (!res.ok) {
      const text = await res.text();
      return { ok: false, error: `GitHub API ${res.status}: ${text.slice(0, 200)}` };
    }
    const json = await res.json();
    return { ok: true, url: json.html_url, number: json.number };
  } catch (err) {
    return { ok: false, error: err.message };
  }
}

// Envía el spec por email a Isabel via SMTP.
async function emailSpec({ titulo, body, issueUrl }) {
  try {
    const { sendEmail } = await import('./email.js');
    const to = process.env.GMAIL_USER || process.env.ISABEL_EMAIL;
    if (!to) return { ok: false, error: 'no destinatario email' };
    const subject = `[Athena] Mejora propuesta: ${titulo}`;
    const intro = `Athena detectó algo que mejoraría su capacidad. Spec abajo.\n\n${issueUrl ? `GitHub issue: ${issueUrl}\n\n` : ''}`;
    await sendEmail(to, subject, intro + body);
    return { ok: true };
  } catch (err) {
    return { ok: false, error: err.message };
  }
}

// API principal: Athena llama esto vía tool proponer_mejora.
export async function proposeImprovement({
  titulo,
  contexto,
  problema,
  propuesta,
  prioridad = 'media',
  tool_sugerido = '',
  archivos_afectados = [],
  disparador = '',
}) {
  if (!titulo || !problema || !propuesta) {
    return { ok: false, error: 'Falta titulo, problema o propuesta.' };
  }
  if (!PRIORIDADES.includes(prioridad)) prioridad = 'media';
  const entry = {
    id: newId(),
    titulo: String(titulo).slice(0, 120),
    contexto: String(contexto || '').slice(0, 800),
    problema: String(problema).slice(0, 800),
    propuesta: String(propuesta).slice(0, 1200),
    prioridad,
    tool_sugerido: String(tool_sugerido).slice(0, 80),
    archivos_afectados: Array.isArray(archivos_afectados)
      ? archivos_afectados.slice(0, 8).map((s) => String(s).slice(0, 100))
      : [],
    disparador: String(disparador).slice(0, 300),
    status: 'pendiente',
    creado: new Date().toISOString(),
  };
  const data = load();
  data.push(entry);
  save(data);

  const body = buildSpecMarkdown(entry);

  // 1. GitHub issue
  const gh = await createGitHubIssue({ titulo: entry.titulo, body, prioridad: entry.prioridad });
  if (gh.ok) {
    entry.github_url = gh.url;
    entry.github_number = gh.number;
    const updated = load();
    const i = updated.findIndex((x) => x.id === entry.id);
    if (i >= 0) {
      updated[i] = entry;
      save(updated);
    }
  }

  // 2. Email a Isabel
  const em = await emailSpec({ titulo: entry.titulo, body, issueUrl: gh.url });

  return {
    ok: true,
    mejora: entry,
    github: gh,
    email: em,
  };
}

export function listImprovements({ status = null } = {}) {
  const data = load();
  if (!status) return data;
  return data.filter((e) => e.status === status);
}

export function updateImprovementStatus(id, status) {
  if (!STATUS.includes(status)) return null;
  const data = load();
  const i = data.findIndex((e) => e.id === id);
  if (i < 0) return null;
  data[i].status = status;
  data[i].actualizado = new Date().toISOString();
  save(data);
  return data[i];
}

export function buildImprovementsInline() {
  const pend = listImprovements({ status: 'pendiente' });
  if (!pend.length) return '';
  const altas = pend.filter((e) => e.prioridad === 'alta').length;
  return `mejoras propuestas: ${pend.length}${altas ? ` (${altas} alta)` : ''}`;
}

// Para el briefing: solo menciona si hay propuestas viejas sin resolver
// (>3 días pendientes) para que Isabel las vea o las descarte.
export function buildImprovementsBriefingBlock() {
  const pend = listImprovements({ status: 'pendiente' });
  if (!pend.length) return null;
  const tresDiasAtras = Date.now() - 3 * 86_400_000;
  const viejas = pend.filter((e) => new Date(e.creado).getTime() < tresDiasAtras);
  if (!viejas.length && pend.length < 3) return null;
  const lines = ['🛠️ MEJORAS QUE ATHENA PROPUSO'];
  for (const e of pend.slice(0, 4)) {
    const dias = Math.floor((Date.now() - new Date(e.creado).getTime()) / 86_400_000);
    lines.push(`  · [${e.prioridad}] ${e.titulo} (${dias}d${e.github_url ? ` — issue #${e.github_number}` : ''})`);
  }
  return lines.join('\n');
}
