// ───────────────────────────────────────────────────────────────────
//  MCP servers — bridge a la red de Model Context Protocol.
//
//  ⚠ SCAFFOLD ONLY — listo para configurar, no wired al directora todavía.
//
//  Cuando se complete (next session): los MCP servers configurados acá
//  se pasan a anthropic.messages.create({ mcp_servers: [...] }) en
//  directora.js. El modelo descubre las tools dinámicamente y las
//  invoca via el connector Anthropic (server-side, sin que nosotros
//  manejemos el dispatcher local).
//
//  Servidores MCP útiles para Isabel (candidatos a habilitar):
//   - Zapier — 8000+ apps (Canva, Instacart, OpenTable, Slack, etc.)
//     URL: https://mcp.zapier.com (requiere token Zapier)
//   - Notion — base de conocimiento Medicare propia
//   - Google Drive — leer/escribir docs y sheets (broker contracts,
//     comisiones, etc.)
//   - Linear / GitHub — gestión de mejoras técnicas
//
//  ENV var esperada: MCP_SERVERS = JSON array.
//  Ejemplo:
//   MCP_SERVERS='[
//     {"type":"url","url":"https://mcp.zapier.com/v1","name":"zapier",
//      "authorization_token":"sk_zap_..."},
//     {"type":"url","url":"https://mcp.notion.com/v1","name":"notion",
//      "authorization_token":"secret_..."}
//   ]'
// ───────────────────────────────────────────────────────────────────

let _cached = null;

export function getMcpServers() {
  if (_cached !== null) return _cached;
  const raw = process.env.MCP_SERVERS;
  if (!raw) {
    _cached = [];
    return _cached;
  }
  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      console.warn('[mcp] MCP_SERVERS no es array — ignorando.');
      _cached = [];
      return _cached;
    }
    // Validar shape mínimo: { type, url, name }
    _cached = parsed.filter((s) => {
      if (!s || typeof s !== 'object') return false;
      if (s.type !== 'url') {
        console.warn(`[mcp] Solo soportamos type=url por ahora — saltando ${s.name || '(sin nombre)'}.`);
        return false;
      }
      if (!s.url || !s.name) {
        console.warn(`[mcp] MCP server inválido (falta url o name): ${JSON.stringify(s).slice(0, 100)}`);
        return false;
      }
      return true;
    });
    console.log(`[mcp] ${_cached.length} server(s) configurado(s): ${_cached.map((s) => s.name).join(', ') || 'ninguno'}`);
    return _cached;
  } catch (err) {
    console.warn('[mcp] Error parseando MCP_SERVERS:', err.message);
    _cached = [];
    return _cached;
  }
}

export function mcpEnabled() {
  return getMcpServers().length > 0;
}
