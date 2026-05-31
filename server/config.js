// Configuracion central de SAMIA. Lee del entorno; nunca hardcodear secretos.
//
// Patron #2 del playbook (model tiers): cada nivel paga solo lo que necesita.
//   - ORCHESTRATOR (Opus)  -> decide / sintetiza  (se usara de lleno en Fase 1 multi-agente)
//   - SPECIALIST   (Sonnet)-> ejecuta / responde   (lo que SAMIA usa hoy)
//   - CLASSIFIER   (Haiku) -> clasifica / taggea    (Fase 4: reflexion + captura)

export const PORT = Number(process.env.PORT) || 8137;

// La API key vive SOLO en el servidor (antes estaba en el localStorage del navegador).
export const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY || '';

export const MODELS = {
  orchestrator: process.env.MODEL_ORCHESTRATOR || 'claude-opus-4-1',
  specialist: process.env.MODEL_SPECIALIST || 'claude-sonnet-4-5',
  classifier: process.env.MODEL_CLASSIFIER || 'claude-haiku-4-5-20251001',
};

export const ANTHROPIC_VERSION = '2023-06-01';
export const MAX_TOKENS = Number(process.env.MAX_TOKENS) || 1400;
// Permite apuntar a un proxy/gateway compatible (default: API publica de Anthropic).
export const ANTHROPIC_BASE_URL = (process.env.ANTHROPIC_BASE_URL || 'https://api.anthropic.com').replace(/\/$/, '');

// Raiz del repo (se sirve estatico: index.html, samia.html, tools/).
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
const __dirname = dirname(fileURLToPath(import.meta.url));
export const REPO_ROOT = resolve(__dirname, '..');
// DATA_DIR es overridable por entorno para apuntar a un volumen persistente en
// produccion (ej. Railway monta un volumen en /data -> DATA_DIR=/data). Sin
// override, usa ./data del repo (bien para local; EFIMERO en la nube sin volumen).
export const DATA_DIR = process.env.DATA_DIR
  ? resolve(process.env.DATA_DIR)
  : resolve(REPO_ROOT, 'data');
