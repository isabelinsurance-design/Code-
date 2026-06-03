<?php
/* ════════════════════════════════════════════════════════════════
   LUNA AI — Cerebro compartido para los agentes autónomos (crons)
   Medicare with Isabel

   Da "inteligencia" a los crons de LUNA. Los NÚMEROS siguen saliendo
   del CRM por SQL (fuente de verdad, cero alucinaciones); esta capa le
   pide a Claude que RAZONE sobre esos números: priorizar, explicar y
   redactar. Reutiliza la MISMA key y el MISMO modelo que luna_chat
   en luna_api.php (no inventa una integración nueva).

   Uso (desde luna/cron/):
     require_once __DIR__ . '/../luna_ai.php';
     $texto = lunaAI($system, $user);     // string, o null si no disponible

   Robustez:
   - La key vive SOLO en el servidor (env ANTHROPIC_API_KEY o constante).
   - Si no hay key, o la API falla/responde !=200, devuelve null → el cron
     continúa con su comportamiento determinista de siempre (degradación
     elegante: la inteligencia es un "plus", nunca un punto de fallo).
   ════════════════════════════════════════════════════════════════ */

if (!function_exists('lunaAIKey')) {
  function lunaAIKey(): string {
    return (string)(getenv('ANTHROPIC_API_KEY')
      ?: (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ''));
  }
}

if (!function_exists('lunaAIEnabled')) {
  function lunaAIEnabled(): bool { return lunaAIKey() !== ''; }
}

if (!function_exists('lunaAI')) {
  /**
   * Llama a Claude (sin streaming) y devuelve el texto generado, o null si falla.
   *
   * @param string $system    Rol/instrucciones de LUNA.
   * @param string $user       Mensaje con los datos reales + la petición.
   * @param int    $maxTokens  Límite de tokens de salida (256–4096).
   * @return string|null        Texto de Claude, o null si no se pudo generar.
   */
  function lunaAI(string $system, string $user, int $maxTokens = 1024): ?string {
    $apiKey = lunaAIKey();
    if ($apiKey === '') return null;

    $payload = json_encode([
      'model'      => 'claude-sonnet-4-6',   // mismo modelo que luna_chat
      'max_tokens' => max(256, min(4096, $maxTokens)),
      'system'     => $system,
      'messages'   => [['role' => 'user', 'content' => $user]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
      ],
      CURLOPT_TIMEOUT        => 60,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code !== 200) return null;

    $json = json_decode($res, true);
    $text = trim((string)($json['content'][0]['text'] ?? ''));
    return $text !== '' ? $text : null;
  }
}

if (!function_exists('lunaAIWeb')) {
  /**
   * Igual que lunaAI() pero con BÚSQUEDA WEB EN VIVO (web_search de Anthropic).
   * Anthropic ejecuta las búsquedas del lado del servidor y devuelve el texto
   * final ya redactado; aquí concatenamos todos los bloques de texto.
   *
   * Lo usa el Radar de Tendencias para investigar qué está pegando hoy/esta
   * semana. Degradación elegante: null si no hay key o la API falla.
   *
   * @param string $system     Rol/instrucciones.
   * @param string $user        Petición + contexto del negocio.
   * @param int    $maxTokens   Límite de salida (256–4096).
   * @param int    $maxSearches Máximo de búsquedas web que puede hacer.
   * @return string|null
   */
  function lunaAIWeb(string $system, string $user, int $maxTokens = 2200, int $maxSearches = 6): ?string {
    $apiKey = lunaAIKey();
    if ($apiKey === '') return null;

    $payload = json_encode([
      'model'      => 'claude-sonnet-4-6',
      'max_tokens' => max(256, min(4096, $maxTokens)),
      'system'     => $system,
      'messages'   => [['role' => 'user', 'content' => $user]],
      'tools'      => [[
        'type'     => 'web_search_20250305',
        'name'     => 'web_search',
        'max_uses' => max(1, min(10, $maxSearches)),
      ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
      ],
      CURLOPT_TIMEOUT        => 150,   // la búsqueda web tarda más
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code !== 200) return null;

    $json = json_decode($res, true);
    // Con web search la respuesta trae varios bloques: concatenamos los de texto.
    $out = '';
    foreach (($json['content'] ?? []) as $block) {
      if (($block['type'] ?? '') === 'text') $out .= $block['text'] . "\n";
    }
    $out = trim($out);
    return $out !== '' ? $out : null;
  }
}
