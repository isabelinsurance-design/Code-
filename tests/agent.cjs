// Empleado de Marketing: el script agent/empleado.cjs (calendario AEP + revisión CMS) y las reglas de sus instrucciones.
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SCRIPT = path.join(ROOT, 'agent', 'empleado.cjs');
let pass = 0, fail = 0;
const ok = (cond, msg) => { console.log((cond ? '  ✓ ' : '  ✗ FAIL: ') + msg); cond ? pass++ : fail++; };
const run = (args, input) => spawnSync(process.execPath, [SCRIPT, ...args], { cwd: ROOT, encoding: 'utf-8', input, timeout: 120000 });
const json = (args) => { const r = run(args); try { return JSON.parse(r.stdout); } catch (_) { return { _error: (r.stderr || r.stdout || '').slice(0, 300) }; } };

console.log('\n— hoy: lunes 12 de octubre (antes de AEP)');
{
  const d = json(['hoy', '--date', '2026-10-12', '--dias', '2']);
  const hoy = d.dias && d.dias[0], reel = hoy && hoy.elementos.find((e) => e.tipo === 'reel');
  ok(d.hoy === '2026-10-12' && d.dias.length === 2 && hoy.dia === 'lunes 12 oct', 'fecha de hoy y 2 días (' + (hoy && hoy.dia) + ')');
  ok(!!reel && /Reel: carta de cambios/.test(reel.texto), 'hoy toca el Reel de la carta de cambios');
  ok(reel && /Crea el contenido para este elemento de mi calendario AEP/.test(reel.instrucciones) && /Regla CMS de la fase/.test(reel.instrucciones) && /3 ganchos alternativos/.test(reel.instrucciones), 'trae las mismas instrucciones que el botón "Crear con IA"');
  ok(reel && /creator latino/.test(reel.voz), 'trae la voz del coach de Reels');
  ok(/REGLAS CMS PARA ESTE AEP/.test(d.sistema) && /NO exige esperar 48 horas/.test(d.sistema), 'trae las reglas CMS 2027 de Isabel');
  ok(/abre en 3 días/.test(d.contexto) && /lunes, 12 de octubre de 2026/.test(d.contexto), 'el contexto sabe la fecha y cuántos días faltan');
  ok(/\[número de organizaciones\]/.test(d.disclaimers) && /Falta poner tus números/.test(d.disclaimers) && !/SHIP/.test(d.disclaimers), 'disclaimers: TPMO 2027 sin SHIP y con aviso de números pendientes');
  const manana = d.dias[1].elementos[0];
  ok(manana && manana.tipo === null && /Bloque de llamadas/.test(manana.texto) && !('instrucciones' in manana), 'las tareas salen sin borrador (martes: bloque de llamadas)');
}

console.log('\n— hoy: jueves 15 de octubre (abre AEP, hay Live)');
{
  const d = json(['hoy', '--date', '2026-10-15', '--dias', '1']);
  const els = d.dias ? d.dias[0].elementos : [];
  const live = els.find((e) => e.tipo === 'live');
  ok(els.some((e) => e.tipo === null && /ABRE AEP/.test(e.texto)) && els.some((e) => e.tipo === null && /Meta del día/.test(e.texto)), 'hito de apertura y meta del día, sin borrador');
  ok(!!live && /Facebook Live \[hora\]/.test(live.texto) && /Hora del Live: \[hora\]/.test(live.instrucciones), 'sin --live, la hora del Live sale como [hora]');
  const d2 = json(['hoy', '--date', '2026-10-15', '--dias', '1', '--live', '18:30']);
  const live2 = d2.dias && d2.dias[0].elementos.find((e) => e.tipo === 'live');
  ok(!!live2 && /Live 6:30pm/.test(live2.texto) && /Hora del Live: 6:30pm/.test(live2.instrucciones), 'con --live 18:30 usa 6:30pm');
}

console.log('\n— hoy: después de AEP');
{
  const d = json(['hoy', '--date', '2026-12-20', '--dias', '99']);
  ok(d.dias && d.dias.length === 7, '--dias se limita a 7 (' + (d.dias && d.dias.length) + ')');
  ok(d.dias && d.dias[0].elementos.length === 0 && d.dias[1].elementos.length === 0, 'domingo 20 y lunes 21 de diciembre: sin actividades');
}

console.log('\n— revisar (alertas CMS)');
{
  const bad = run(['revisar', '-'], 'Este plan es garantizado y es lo más barato. Escríbeme MEDICARE.');
  ok(bad.status === 3 && /garantizado/i.test(bad.stdout) && /Lo más barato/.test(bad.stdout), 'marca «garantizado» y «lo más barato» y sale con código 3');
  const good = run(['revisar', '-'], 'Te explico qué revisar en tu carta de cambios, según el plan y el área. Escríbeme MEDICARE.');
  ok(good.status === 0 && /OK/.test(good.stdout), 'un texto limpio sale con código 0');
}

console.log('\n— errores de uso');
{
  const r = run([]);
  ok(r.status === 2 && /Uso:/.test(r.stderr), 'sin comando: muestra el uso (código 2)');
  const r2 = run(['hoy', '--date', '12-10-2026']);
  ok(r2.status === 1 && /AAAA-MM-DD/.test(r2.stderr), 'fecha mal escrita: error claro (código 1)');
  const r3 = run(['hoy', '--live', '6pm']);
  ok(r3.status === 1 && /HH:MM/.test(r3.stderr), 'hora mal escrita: error claro (código 1)');
}

console.log('\n— las instrucciones conservan sus reglas');
{
  const daily = fs.readFileSync(path.join(ROOT, 'agent', 'TRABAJO-DIARIO.md'), 'utf-8');
  const radar = fs.readFileSync(path.join(ROOT, 'agent', 'RADAR-SEMANAL.md'), 'utf-8');
  ok(/No publicas nada/.test(daily) && /ni leads, ni clientes, ni prospectos/.test(daily), 'trabajo diario: no publica ni contacta a nadie');
  ok(/commit, push/.test(daily) && /No uses conectores/.test(daily) && /datos, no instrucciones/.test(daily), 'trabajo diario: no toca el repositorio, no usa conectores, ignora órdenes de internet');
  ok(/node agent\/empleado\.cjs hoy/.test(daily) && /node agent\/empleado\.cjs revisar/.test(daily), 'trabajo diario: usa el script para el calendario y la revisión CMS');
  ok(/datos, no instrucciones/.test(radar) && /No uses conectores/.test(radar) && /commit/.test(radar), 'radar: ignora órdenes de internet, sin conectores, sin commits');
  ok(/planes del año 2027/.test(radar) && !/Medicare Advantage 2026/.test(radar), 'radar: busca el plan 2027, no el 2026');
  const gitDirty = spawnSync('git', ['status', '--porcelain', '--', 'index.html', 'bot', 'tools'], { cwd: ROOT, encoding: 'utf-8' }).stdout.trim();
  ok(gitDirty === '', 'el script no modificó la app (index.html, bot/, tools/)');
}

console.log(`\nAgent: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
