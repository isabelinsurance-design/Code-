#!/usr/bin/env node
// Herramientas del empleado de Marketing de Isabel. Las usan las rutinas programadas (ver agent/TRABAJO-DIARIO.md).
//
//   node agent/empleado.cjs hoy [--date AAAA-MM-DD] [--dias 2] [--live HH:MM]
//       JSON con lo que toca hoy (y los días siguientes) según el calendario AEP de index.html, con las mismas
//       instrucciones que usa el botón "Crear con IA" de la app. Sin --live, la hora de los Lives sale como [hora].
//   node agent/empleado.cjs revisar <archivo|->
//       Alertas CMS de un texto (las mismas expresiones que usa la app). Sale con código 3 si hay alguna.
//   node agent/empleado.cjs compilar [--check]
//       Genera los textos que se instalan en las rutinas (agent/generado/prompt-diario.txt y prompt-radar.txt) a partir
//       de index.html, agent/*.md y agent/config.json. Las rutinas programadas no pueden abrir este repositorio, así
//       que llevan TODO dentro de su prompt. Con --check solo verifica que lo generado esté al día (código 4 si no).
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

const DESDE = '2026-10-12', HASTA = '2026-12-31';
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const corto = (ymd) => Number(ymd.slice(8)) + ' ' + MESES[Number(ymd.slice(5, 7)) - 1];
const seccion = (titulo, cuerpo) => '\n\n=== ' + titulo + ' ===\n' + String(cuerpo).trim();

// Todo lo que la app sabe y las rutinas necesitan, leído de la propia app para que no se desfase.
async function datosDeLaApp(cfg) {
  return withApp(null, (page) => page.evaluate(({ cfg, DESDE, HASTA }) => {
    if (cfg.live) saveSettings({ liveTime: cfg.live }); else window.aepLiveLabel = () => '[hora]';
    if (cfg.tpmoOrgs && cfg.tpmoPlans) saveSettings({ tpmoOrgs: cfg.tpmoOrgs, tpmoPlans: cfg.tpmoPlans });
    const dow = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    const dias = [];
    for (const d = _parseYmd(DESDE); _ymd(d) <= HASTA; d.setDate(d.getDate() + 1)) {
      const ymd = _ymd(d), p = aepPeriodFor(ymd);
      // Las llamadas y reuniones post-AEP (📞, 📊) vienen tipadas como "post" en la app (allí solo ofrece un botón de más);
      // al empleado se le marcan como tareas para que no escriba un post de Facebook sobre una reunión interna.
      const items = aepItemsFor(ymd).filter((it) => it.kind !== 'goal').map((it) => {
        let tipo = aepKindOf(it) || (it.kind === 'ms' ? 'hito' : 'tarea');
        if (tipo === 'post' && /^(📞|📊)/.test(it.text)) tipo = 'tarea';
        return { tipo, texto: it.text };
      });
      if (items.length) dias.push({ ymd, dow: dow[d.getDay()], fase: p ? p.id : '—', items });
    }
    return {
      fases: AEP_PERIODS.filter((p) => p.end >= DESDE).map((p) => ({ id: p.id, nombre: p.name, desde: p.start, hasta: p.end, tema: p.theme, cms: p.cms })),
      dias,
      sistema: ISABEL_SYSTEM,
      voces: { reel: COACHES[AEP_COACH.reel].voice, live: COACHES[AEP_COACH.live].voice, post: COACHES[AEP_COACH.post].voice, ad: COACHES[AEP_COACH.ad].voice },
      specs: { reel: AEP_SPECS.reel, live: AEP_SPECS.live, post: AEP_SPECS.post },
      regla: buildAepPrompt('2026-10-12', { text: 'x' }, 'reel').split('\n\n').pop(),
      disclaimers: aepDisclaimerBlock(),
      alertas: CMS_FLAGS.map((f) => f.msg),
    };
  }, { cfg, DESDE, HASTA }));
}

async function textosGenerados() {
  const cfg = Object.assign({ live: null, tpmoOrgs: '', tpmoPlans: '' }, JSON.parse(fs.readFileSync(process.env.AGENT_CONFIG || path.join(__dirname, 'config.json'), 'utf8')));
  const d = await datosDeLaApp(cfg);
  const md = (f) => fs.readFileSync(path.join(__dirname, f), 'utf8').trim();
  const diario = md('TRABAJO-DIARIO.md')
    + seccion('SISTEMA (voz y reglas CMS de Isabel; síguelas al pie de la letra)', d.sistema)
    + seccion('REGLAS PARA CADA BORRADOR', d.regla.replace('NO escribas los disclaimers legales: el sistema los agrega al final.', 'No inventes los disclaimers legales: copia el bloque DISCLAIMERS.'))
    + seccion('VOZ POR TIPO', Object.entries(d.voces).map(([k, v]) => '[' + k + '] ' + v).join('\n\n'))
    + seccion('ESPECIFICACIÓN POR TIPO (qué entregar)', Object.entries(d.specs).map(([k, v]) => '[' + k + '] ' + v).join('\n\n'))
    + seccion('FASES DEL PLAN', d.fases.map((f) => f.id + ' · ' + f.nombre + ' · ' + corto(f.desde) + ' al ' + corto(f.hasta) + ' · tema: «' + f.tema + '» · regla CMS: ' + f.cms).join('\n'))
    + seccion('CALENDARIO (fecha, día, fase, elementos [tipo])', d.dias.map((x) => x.ymd + ' ' + x.dow + ' ' + x.fase + ' | ' + x.items.map((i) => '[' + i.tipo + '] ' + i.texto).join(' || ')).join('\n'))
    + seccion('DISCLAIMERS (cópialos tal cual al final de cada pieza que se publique)', d.disclaimers)
    + seccion('ALERTAS CMS (reescribe cualquier frase que caiga en una de estas)', d.alertas.map((a) => '- ' + a).join('\n'))
    + '\n';
  return { 'prompt-diario.txt': diario, 'prompt-radar.txt': md('RADAR-SEMANAL.md') + '\n' };
}

async function compilar() {
  const check = args.includes('--check');
  const dir = process.env.AGENT_OUT || path.join(__dirname, 'generado');
  const textos = await textosGenerados();
  const viejos = [];
  for (const [nombre, texto] of Object.entries(textos)) {
    const f = path.join(dir, nombre);
    if (check) { if (!fs.existsSync(f) || fs.readFileSync(f, 'utf8') !== texto) viejos.push(nombre); continue; }
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(f, texto);
    console.log(nombre + ': ' + Buffer.byteLength(texto) + ' bytes');
  }
  if (check) {
    console.log(viejos.length ? 'DESACTUALIZADO: ' + viejos.join(', ') + ' (corre: node agent/empleado.cjs compilar)' : 'OK: agent/generado está al día');
    process.exitCode = viejos.length ? 4 : 0;
  }
}

(async () => {
  if (cmd === 'compilar') await compilar();
  else if (cmd === 'hoy') await hoy();
  else if (cmd === 'revisar') await revisar();
  else {
    console.error('Uso:\n  node agent/empleado.cjs hoy [--date AAAA-MM-DD] [--dias 2] [--live HH:MM]\n  node agent/empleado.cjs revisar <archivo|->\n  node agent/empleado.cjs compilar [--check]');
    process.exitCode = 2;
  }
})().catch((e) => { console.error('Error: ' + (e && e.message || e)); process.exitCode = 1; });
