// Shared helpers for the browser tests. Run any test file with:  node tests/<name>.cjs
// (or all of them with:  node tests/run.cjs). Needs Playwright (npm i -g playwright).
const fs = require('fs');
const http = require('http');
const path = require('path');

let chromium;
try { ({ chromium } = require('playwright')); }
catch (_) { ({ chromium } = require(process.env.PLAYWRIGHT_PATH || '/opt/node22/lib/node_modules/playwright')); }

const ROOT = path.resolve(__dirname, '..');
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.json': 'application/json', '.css': 'text/css', '.png': 'image/png', '.md': 'text/plain' };
const TEST_KEY = 'sk-ant-TEST-1234567890abcdefghijklmnop';

function startServer() {
  return new Promise((resolve) => {
    const srv = http.createServer((req, res) => {
      const rel = decodeURIComponent(req.url.split('?')[0]).replace(/^\/+/, '') || 'index.html';
      const file = path.join(ROOT, rel);
      if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); return res.end('not found'); }
      res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
      fs.createReadStream(file).pipe(res);
    });
    srv.listen(0, '127.0.0.1', () => resolve({ srv, base: `http://127.0.0.1:${srv.address().port}/` }));
  });
}

// ── fake Anthropic server bodies ──
function sseStream(blocks, stop = 'end_turn', usage = { in: 120, out: 80 }) {
  const ev = [];
  const push = (type, data) => ev.push(`event: ${type}\ndata: ${JSON.stringify(Object.assign({ type }, data))}\n\n`);
  push('message_start', { message: { id: 'msg_t', type: 'message', role: 'assistant', content: [], model: 'x', usage: { input_tokens: usage.in, output_tokens: 1 } } });
  let i = 0;
  for (const b of blocks) {
    if (b.t === 'thinking') {
      push('content_block_start', { index: i, content_block: { type: 'thinking', thinking: '' } });
      push('content_block_delta', { index: i, delta: { type: 'signature_delta', signature: 'SIG123' } });
      push('content_block_stop', { index: i }); i++;
    } else if (b.t === 'text') {
      push('content_block_start', { index: i, content_block: { type: 'text', text: '' } });
      (b.text.match(/[\s\S]{1,14}/g) || []).forEach(p => push('content_block_delta', { index: i, delta: { type: 'text_delta', text: p } }));
      (b.cites || []).forEach(c => push('content_block_delta', { index: i, delta: { type: 'citations_delta', citation: { type: 'web_search_result_location', url: c.url, title: c.title, cited_text: '…', encrypted_index: 'x' } } }));
      push('content_block_stop', { index: i }); i++;
    } else if (b.t === 'search') {
      const json = JSON.stringify({ query: b.query });
      push('content_block_start', { index: i, content_block: { type: 'server_tool_use', id: 'srvtoolu_1', name: 'web_search', input: {} } });
      push('content_block_delta', { index: i, delta: { type: 'input_json_delta', partial_json: json.slice(0, 9) } });
      push('content_block_delta', { index: i, delta: { type: 'input_json_delta', partial_json: json.slice(9) } });
      push('content_block_stop', { index: i }); i++;
      push('content_block_start', { index: i, content_block: { type: 'web_search_tool_result', tool_use_id: 'srvtoolu_1', content: (b.results || []).map(r => ({ type: 'web_search_result', title: r.title, url: r.url, encrypted_content: 'ENC', page_age: '1 day' })) } });
      push('content_block_stop', { index: i }); i++;
    }
  }
  push('message_delta', { delta: { stop_reason: stop }, usage: { output_tokens: usage.out } });
  push('message_stop', {});
  return ev.join('');
}
const sse = (blocks, stop, usage) => ({ status: 200, headers: { 'content-type': 'text/event-stream' }, body: sseStream(blocks, stop, usage) });
const text = (t) => sse([{ t: 'text', text: t }]);
const err = (status, type, message) => ({ status, headers: { 'content-type': 'application/json' }, body: JSON.stringify({ type: 'error', error: { type, message } }) });
const json = (obj) => ({ status: 200, headers: { 'content-type': 'application/json' }, body: JSON.stringify(obj) });
const EXTRACT = (b) => /extractor de información/.test(b.system || '');
const EMPTY_CAPTURE = () => text('{"capturas":[]}');

async function run(name, fn) {
  const { srv, base } = await startServer();
  const browser = await chromium.launch();
  let pass = 0, fail = 0;
  const t = {
    base,
    ok(cond, msg) { console.log((cond ? '  ✓ ' : '  ✗ FAIL: ') + msg); cond ? pass++ : fail++; },
    section(s) { console.log('\n— ' + s); },
    // new page with a frozen date, API key and optional settings; every anthropic call goes to handler(body, headers, n)
    async page(file, opts = {}) {
      const ctx = await browser.newContext({ timezoneId: 'America/Los_Angeles', acceptDownloads: true, permissions: ['clipboard-read', 'clipboard-write'] });
      const p = await ctx.newPage();
      await p.clock.install({ time: new Date(opts.iso || '2026-10-10T17:00:00Z') });
      const errs = []; p.on('pageerror', e => errs.push(e.message));
      const calls = [];
      await ctx.route('https://api.anthropic.com/**', async (route) => {
        const req = route.request();
        let body = {}; try { body = JSON.parse(req.postData() || '{}'); } catch (_) {}
        calls.push({ body, headers: req.headers() });
        const out = await (opts.handler || (() => text('ok')))(body, req.headers(), calls.length - 1);
        await route.fulfill(out);
      });
      if (opts.key !== false) await ctx.addInitScript(({ key, settings, extra }) => {
        try {
          if (!localStorage.getItem('isabel_anthropic_key')) localStorage.setItem('isabel_anthropic_key', key);
          if (settings) localStorage.setItem('isabel_settings', JSON.stringify(settings));
          Object.entries(extra || {}).forEach(([k, v]) => { if (localStorage.getItem(k) === null) localStorage.setItem(k, typeof v === 'string' ? v : JSON.stringify(v)); });
        } catch (_) {}
      }, { key: opts.key || TEST_KEY, settings: opts.settings, extra: opts.storage });
      await p.goto(base + file, { waitUntil: 'networkidle' });
      return { ctx, p, errs, calls };
    },
    async done() {
      await browser.close(); srv.close();
      console.log(`\n${name}: ${pass} passed, ${fail} failed`);
      process.exit(fail ? 1 : 0);
    },
  };
  try { await fn(t); } catch (e) { console.error('CRASH', e && e.stack || e); fail++; }
  await t.done();
}

module.exports = { run, sse, text, err, json, EXTRACT, EMPTY_CAPTURE, TEST_KEY, ROOT, BUILDS: ['index.html', 'isabel-sistema-completo-UNICO.html'] };
