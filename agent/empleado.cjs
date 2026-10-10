#!/usr/bin/env node
// Herramientas del empleado de Marketing de Isabel. Las usan las rutinas programadas (ver agent/TRABAJO-DIARIO.md).
//
//   node agent/empleado.cjs hoy [--date AAAA-MM-DD] [--dias 2] [--live HH:MM]
//       JSON con lo que toca hoy (y los días siguientes) según el calendario AEP de index.html, con las mismas
//       instrucciones que usa el botón "Crear con IA" de la app. Sin --live, la hora de los Lives sale como [hora].
//   node agent/empleado.cjs revisar <archivo|->
//       Alertas CMS de un texto (las mismas expresiones que usa la app). Sale con código 3 si hay alguna.
//
// Necesita Playwright (npm i -g playwright). Solo lee index.html: no escribe nada en el repositorio.
const fs = require('fs');
const http = require('http');
const path = require('path');

let chromium;
try { ({ chromium } = require('playwright')); }
catch (_) { ({ chromium } = require(process.env.PLAYWRIGHT_PATH || '/opt/node22/lib/node_modules/playwright')); }

const ROOT = path.resolve(__dirname, '..');
const args = process.argv.slice(2);
const cmd = args.shift();
const opt = (name, dflt) => {
  const i = args.indexOf('--' + name);
  if (i < 0) return dflt;
  const v = args[i + 1];
  args.splice(i, 2);
  return v;
};

function startServer() {
  return new Promise((resolve) => {
    const srv = http.createServer((req, res) => {
      if (req.url.split('?')[0] !== '/index.html') { res.writeHead(404); return res.end(); }
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      fs.createReadStream(path.join(ROOT, 'index.html')).pipe(res);
    });
    srv.listen(0, '127.0.0.1', () => resolve({ srv, base: `http://127.0.0.1:${srv.address().port}/` }));
  });
}

// Abre index.html sin internet y con la hora de California; date (opcional) congela el día.
async function withApp(date, fn) {
  const { srv, base } = await startServer();
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ timezoneId: 'America/Los_Angeles', locale: 'es-US' });
    await ctx.route('**/*', (r) => (r.request().url().startsWith(base) ? r.continue() : r.abort()));
    const page = await ctx.newPage();
    if (date) await page.clock.install({ time: new Date(date + 'T19:00:00Z') });
    await page.goto(base + 'index.html', { waitUntil: 'load' });
    await page.waitForFunction(() => typeof aepItemsFor === 'function');
    return await fn(page);
  } finally {
    await browser.close();
    srv.close();
  }
}

async function hoy() {
  const date = opt('date', null);
  const dias = Math.max(1, Math.min(7, Number(opt('dias', '2')) || 2));
  const live = opt('live', null);
  if (date && !/^\d{4}-\d{2}-\d{2}$/.test(date)) throw new Error('--date debe ser AAAA-MM-DD');
  if (live && !/^\d{1,2}:\d{2}$/.test(live)) throw new Error('--live debe ser HH:MM (24 horas)');
  const out = await withApp(date, (page) => page.evaluate(({ dias, live }) => {
    if (live) saveSettings({ liveTime: live });
    else window.aepLiveLabel = () => '[hora]';   // la hora real la fija Isabel en su calendario
    const nombres = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    const hoyYmd = aepToday();
    const dates = [];
    for (let i = 0; i < dias; i++) { const d = _parseYmd(hoyYmd); d.setDate(d.getDate() + i); dates.push(_ymd(d)); }
    return {
      hoy: hoyYmd,
      contexto: buildContextBlock(),
      sistema: ISABEL_SYSTEM,
      disclaimers: aepDisclaimerBlock(),
      dias: dates.map((ymd) => {
        const p = aepPeriodFor(ymd), d = _parseYmd(ymd);
        return {
          fecha: ymd,
          dia: nombres[d.getDay()] + ' ' + _fmtShort(ymd),
          fase: p ? { nombre: p.name, tema: p.theme, regla_cms: p.cms } : null,
          elementos: aepItemsFor(ymd).map((it) => {
            const tipo = aepKindOf(it);
            return tipo
              ? { texto: it.text, tipo, voz: COACHES[AEP_COACH[tipo]].voice, instrucciones: buildAepPrompt(ymd, it, tipo) }
              : { texto: it.text, tipo: null };   // tareas, hitos y metas: no llevan borrador
          }),
        };
      }),
    };
  }, { dias, live }));
  console.log(JSON.stringify(out, null, 2));
}

async function revisar() {
  const file = args[0];
  const text = !file || file === '-' ? fs.readFileSync(0, 'utf8') : fs.readFileSync(file, 'utf8');
  const issues = await withApp(null, (page) => page.evaluate((t) => cmsRegexIssues(t), text));
  console.log(issues.length ? issues.map((m) => '- ' + m).join('\n') : 'OK: sin alertas CMS');
  process.exitCode = issues.length ? 3 : 0;
}

(async () => {
  if (cmd === 'hoy') await hoy();
  else if (cmd === 'revisar') await revisar();
  else {
    console.error('Uso:\n  node agent/empleado.cjs hoy [--date AAAA-MM-DD] [--dias 2] [--live HH:MM]\n  node agent/empleado.cjs revisar <archivo|->');
    process.exitCode = 2;
  }
})().catch((e) => { console.error('Error: ' + (e && e.message || e)); process.exitCode = 1; });
