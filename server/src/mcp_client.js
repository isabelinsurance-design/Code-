// ============================================================
//  MCP Client — Athena se conecta al ecosistema MCP
//  ─────────────────────────────────────────────────
//  Model Context Protocol = el estándar de Anthropic para
//  conectar agentes con apps externas. Athena se vuelve cliente
//  MCP — descubre tools de servidores remotos (Zapier, Notion,
//  Drive, etc.) y las expone a sí misma como si fueran propias.
//
//  Zapier MCP da acceso a 8,000+ apps con una sola conexión.
//
//  Implementación: JSON-RPC 2.0 sobre HTTP. Sin dependencia
//  externa, plain fetch(). Ligero y sin npm install al deploy.
//
//  Configuración (env vars en Railway):
//    MCP_ENABLED=true
//    MCP_ZAPIER_URL=https://mcp.zapier.com/api/mcp/s/<sid>/messages
//    MCP_ZAPIER_TOKEN=<token>
//    MCP_NOTION_URL=...    (opcional)
//    MCP_NOTION_TOKEN=...  (opcional)
//
//  Tools descubiertas se prefijan con el alias del server:
//  Zapier expone 'create_opentable_reservation' →
//    Athena la ve como 'mcp_zapier_create_opentable_reservation'
//
//  Cache de tools/list: 1 hora. Se refresca con cron mcp_refresh.
// ============================================================

const REGISTERED_SERVERS = []; // { alias, url, token, tools: [], ts }

export function mcpEnabled() {
  return process.env.MCP_ENABLED === 'true';
}

// Carga la configuración de servidores desde env vars.
// Soporta cualquier alias: si hay MCP_<ALIAS>_URL + MCP_<ALIAS>_TOKEN, se registra.
function loadServerConfigs() {
  const servers = [];
  const seen = new Set();
  for (const key of Object.keys(process.env)) {
    const m = key.match(/^MCP_([A-Z][A-Z0-9_]*)_URL$/);
    if (!m) continue;
    const alias = m[1].toLowerCase();
    if (seen.has(alias)) continue;
    const url = process.env[key];
    const token = process.env[`MCP_${m[1]}_TOKEN`];
    if (!url || !token) continue;
    servers.push({ alias, url, token });
    seen.add(alias);
  }
  return servers;
}

async function mcpRequest(server, method, params = {}) {
  const res = await fetch(server.url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': `Bearer ${server.token}`,
    },
    body: JSON.stringify({
      jsonrpc: '2.0',
      id: Date.now(),
      method,
      params,
    }),
    signal: AbortSignal.timeout(15_000),
  });
  if (!res.ok) {
    throw new Error(`HTTP ${res.status} from ${server.alias}`);
  }
  const data = await res.json();
  if (data.error) {
    throw new Error(`MCP error from ${server.alias}: ${data.error.message || JSON.stringify(data.error)}`);
  }
  return data.result;
}

// Lista tools de UN server y normaliza con prefijo.
async function discoverTools(server) {
  try {
    const result = await mcpRequest(server, 'tools/list');
    const tools = (result?.tools || []).map((t) => ({
      name: `mcp_${server.alias}_${t.name}`,
      original_name: t.name,
      server_alias: server.alias,
      description: t.description || `(${server.alias}) ${t.name}`,
      input_schema: t.inputSchema || { type: 'object', properties: {}, required: [] },
    }));
    return tools;
  } catch (err) {
    console.warn(`[mcp] ${server.alias} discovery failed:`, err.message);
    return [];
  }
}

// Inicializa todos los servers configurados. Llamado al boot.
export async function initMcpClients() {
  if (!mcpEnabled()) {
    console.log('[mcp] desactivado (MCP_ENABLED!=true).');
    return { servers: 0, tools: 0 };
  }
  REGISTERED_SERVERS.length = 0;
  const servers = loadServerConfigs();
  if (!servers.length) {
    console.log('[mcp] no hay servers configurados (MCP_<ALIAS>_URL/TOKEN).');
    return { servers: 0, tools: 0 };
  }
  let totalTools = 0;
  for (const s of servers) {
    const tools = await discoverTools(s);
    REGISTERED_SERVERS.push({ ...s, tools, ts: Date.now() });
    totalTools += tools.length;
    console.log(`[mcp] ${s.alias}: ${tools.length} tools descubiertas.`);
  }
  return { servers: servers.length, tools: totalTools };
}

// Refresca cache (cron horario).
export async function refreshMcpClients() {
  if (!mcpEnabled() || !REGISTERED_SERVERS.length) return;
  for (const s of REGISTERED_SERVERS) {
    s.tools = await discoverTools(s);
    s.ts = Date.now();
  }
}

// Devuelve todas las tools descubiertas, formato Anthropic.
export function getMcpToolDefinitions() {
  if (!mcpEnabled()) return [];
  const out = [];
  for (const s of REGISTERED_SERVERS) {
    for (const t of s.tools) {
      out.push({
        name: t.name,
        description: t.description,
        input_schema: t.input_schema,
      });
    }
  }
  return out;
}

// Dispatch — Athena llama una tool MCP por su nombre prefijado.
export async function runMcpTool(prefixedName, input = {}) {
  const m = prefixedName.match(/^mcp_([a-z0-9_]+?)_(.+)$/);
  if (!m) return `Tool MCP mal formada: ${prefixedName}`;
  const alias = m[1];
  const realName = m[2];
  const server = REGISTERED_SERVERS.find((s) => s.alias === alias);
  if (!server) return `Server MCP "${alias}" no registrado o no configurado.`;
  try {
    const result = await mcpRequest(server, 'tools/call', {
      name: realName,
      arguments: input,
    });
    // MCP result.content típico: [{type:'text', text:'...'}, ...]
    if (Array.isArray(result?.content)) {
      return result.content
        .filter((b) => b.type === 'text')
        .map((b) => b.text)
        .join('\n') || JSON.stringify(result.content).slice(0, 500);
    }
    return JSON.stringify(result).slice(0, 1000);
  } catch (err) {
    return `Error ejecutando ${prefixedName}: ${err.message}`;
  }
}

// Para debugging y dashboard
export function getMcpStatus() {
  return REGISTERED_SERVERS.map((s) => ({
    alias: s.alias,
    tools_count: s.tools.length,
    last_refresh: new Date(s.ts).toISOString(),
  }));
}
