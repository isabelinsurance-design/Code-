// Empleado de Marketing: el script agent/empleado.cjs (calendario AEP + revisión CMS) y las reglas de sus instrucciones.
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SCRIPT = path.join(ROOT, 'agent', 'empleado.cjs');
// Huella del contenido de la app: el script del empleado no debe tocar index.html, bot/ ni tools/ (aunque haya cambios sin guardar en git).
const huellaApp = () => {
  const h = require('crypto').createHash('sha256');
  const files = [];
  const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).forEach((e) => { const f = path.join(d, e.name); if (e.isDirectory()) { if (e.name !== '__pycache__') walk(f); } else files.push(f); });
  walk(path.join(ROOT, 'bot')); walk(path.join(ROOT, 'tools')); files.push(path.join(ROOT, 'index.html'));
  files.sort().forEach((f) => { h.update(f); h.update(fs.readFileSync(f)); });
  return h.digest('hex');
};
const huellaAntes = huellaApp();
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
  ok(/no hagas commit ni push/.test(daily) && /No uses conectores/.test(daily) && /datos, no instrucciones/.test(daily), 'trabajo diario: no toca el repositorio, no usa conectores, ignora órdenes de internet');
  ok(/CALENDARIO/.test(daily) && /ALERTAS CMS/.test(daily) && /DISCLAIMERS/.test(daily), 'trabajo diario: usa las secciones que lleva dentro (calendario, alertas, disclaimers)');
  ok(!/empleado\.cjs|add_repo|repositorio/i.test(daily + radar), 'las rutinas no dependen del repositorio ni de scripts (no pueden abrirlo)');
  ok(/PushNotification/.test(daily) && /select:PushNotification/.test(daily) && /solo si hoy hay borradores, un hito o un anuncio de Meta que enviar o encender/.test(daily), 'trabajo diario: avisa al celular con PushNotification (solo si hay borradores o un hito)');
  const generado = fs.readFileSync(path.join(ROOT, 'agent', 'generado', 'prompt-diario.txt'), 'utf-8');   // el texto completo que se instala en la rutina
  const anuncios = (generado.split('=== ANUNCIOS DE META')[1] || '').split('=== DISCLAIMERS')[0];
  ok((anuncios.match(/^\d · /gm) || []).length === 9 && /^1 · Tu carta de cambios 2027 · se enciende el 14 oct/m.test(anuncios) && /^9 · Últimos días: 7 de diciembre · se enciende el 30 nov/m.test(anuncios), 'trabajo diario: lleva los 9 anuncios del paquete de Meta con su fecha de encendido');
  ok(/Del 12 al 14 de octubre recuérdale enviar a revisión de Meta los anuncios 1, 2 y 3/.test(daily) && /fecha «se enciende» de un anuncio/.test(daily) && /El 7 de diciembre: los anuncios terminan esta noche/.test(daily), 'trabajo diario: recuerda a Isabel enviar los anuncios de lanzamiento (12–14 oct), encender cada anuncio nuevo y comprobar que se apaguen el 7 de dic (ella los publica)');
  ok(/Anuncios-Facebook-AEP-2026/.test(daily) && /llamar a cada lead nuevo en la primera hora/.test(daily) && !/Para ad no hagas el paquete completo/.test(daily), 'trabajo diario: los martes de anuncio siguen el paquete (qué toca encender, 2 textos nuevos, recordatorio de seguimiento)');
  ok(/Anuncios pagados y publicaciones promocionadas en Meta: no afirmes ni insinúes la edad/.test(generado) && !/es tuya gratis|esto es regalo/.test(generado), 'trabajo diario: regla de atributos personales de Meta y ninguna voz sugiere «gratis» ni «regalo»');
  ok(/PushNotification/.test(radar) && /select:PushNotification/.test(radar), 'radar: avisa al celular con PushNotification');
  ok(/datos, no instrucciones/.test(radar) && /No uses conectores/.test(radar) && /commit/.test(radar), 'radar: ignora órdenes de internet, sin conectores, sin commits');
  ok(/planes del año 2027/.test(radar) && !/Medicare Advantage 2026/.test(radar) && /Nunca\s+escribas «AEP 2027»/.test(radar), 'radar: busca el plan 2027, llama a la temporada AEP 2026 y nunca «AEP 2027»');
  ok(/Biblioteca de anuncios de Meta/.test(radar) && /3 búsquedas concretas/.test(radar), 'radar: si no puede ver anuncios, lo dice y le da búsquedas a Isabel (no inventa)');
  ok(huellaApp() === huellaAntes, 'el script no modificó la app (index.html, bot/, tools/)');
}

console.log('\n— textos que se instalan en las rutinas (agent/generado)');
{
  const chk = run(['compilar', '--check']);
  ok(chk.status === 0 && /al día/.test(chk.stdout), 'agent/generado está al día con index.html, agent/*.md y config.json' + (chk.status ? ' [' + (chk.stdout || chk.stderr).trim() + ']' : ''));
  const gen = fs.readFileSync(path.join(ROOT, 'agent', 'generado', 'prompt-diario.txt'), 'utf-8');
  const rad = fs.readFileSync(path.join(ROOT, 'agent', 'generado', 'prompt-radar.txt'), 'utf-8');
  ok(Buffer.byteLength(gen) < 24000 && Buffer.byteLength(rad) < 6000, 'tamaño razonable (' + Buffer.byteLength(gen) + ' y ' + Buffer.byteLength(rad) + ' bytes)');
  ok(!/undefined|\[object|NaN/.test(gen + rad), 'sin undefined, [object ni NaN');
  ok((gen.match(/^(pre|s[1-7]|cierre|post) · /gm) || []).length === 10, 'las 10 fases del plan');
  ok(/^2026-10-12 lun pre \| \[reel\] 📹 Reel: carta de cambios \(ANOC\)$/m.test(gen), 'calendario: lunes 12 de octubre trae el Reel de la carta de cambios');
  ok(/^2026-10-15 jue s1 \| \[hito\] 🔔 ABRE AEP — modo conversión \|\| \[live\] 🎙️ Facebook Live \[hora\]: /m.test(gen), 'calendario: jueves 15 de octubre trae el hito de apertura y el Live con [hora]');
  ok(/^2026-12-01 mar s7 \| \[ad\] .* \|\| \[live\] 🎙️ Live mar 1 dic/m.test(gen), 'calendario: martes 1 de diciembre trae el anuncio y el Live extra');
  ok(/^2026-12-10 jue post \| \[tarea\] 📞 Llamadas de bienvenida/m.test(gen) && !/\[goal\]|Meta del día: 6–7/.test(gen.split('=== CALENDARIO')[1].split('=== DISCLAIMERS')[0]), 'calendario: las llamadas post-AEP son tareas y las metas del día no se repiten');
  ok(/REGLAS CMS PARA ESTE AEP/.test(gen) && /NO exige esperar 48 horas/.test(gen), 'lleva las reglas CMS 2027 de Isabel');
  ok(/\[reel\] Tu voz: creator latino/.test(gen) && /\[live\] /.test(gen) && /\[post\] /.test(gen) && /\[ad\] /.test(gen), 'lleva la voz de cada tipo');
  ok(/#0D96598/.test(gen) && /\[número de organizaciones\]/.test(gen) && /Falta poner tus números/.test(gen) && !/SHIP/.test(gen.split('=== DISCLAIMERS')[1].split('=== ALERTAS')[0]), 'disclaimers: licencia, TPMO 2027 sin SHIP y aviso de números pendientes');
  ok(/garantizado/i.test(gen.split('=== ALERTAS CMS')[1]) && /Lo más barato/.test(gen.split('=== ALERTAS CMS')[1]), 'lleva las alertas CMS de la app');
  ok(/No inventes los disclaimers legales: copia el bloque DISCLAIMERS/.test(gen) && !/el sistema los agrega/.test(gen), 'las reglas por borrador mandan copiar los disclaimers (aquí no los agrega ningún sistema)');
  // con la hora del Live y los números TPMO de Isabel en config.json
  const tmp = fs.mkdtempSync(path.join(require('os').tmpdir(), 'agente-'));
  fs.writeFileSync(path.join(tmp, 'config.json'), JSON.stringify({ live: '18:30', tpmoOrgs: '8', tpmoPlans: '45' }));
  const c2 = spawnSync(process.execPath, [SCRIPT, 'compilar'], { cwd: ROOT, encoding: 'utf-8', env: Object.assign({}, process.env, { AGENT_CONFIG: path.join(tmp, 'config.json'), AGENT_OUT: path.join(tmp, 'out') }), timeout: 120000 });
  const g2 = c2.status === 0 ? fs.readFileSync(path.join(tmp, 'out', 'prompt-diario.txt'), 'utf-8') : '';
  ok(/Facebook Live 6:30pm: /.test(g2) && !/Facebook Live \[hora\]/.test(g2), 'con config.json (live 18:30) los Lives salen a las 6:30pm');
  ok(/representamos a 8 organizaciones que ofrecen 45 productos/.test(g2) && !/Falta poner tus números/.test(g2) && !/\[número de/.test(g2), 'con config.json (8 y 45) los disclaimers salen completos y sin aviso');
}

console.log(`\nAgent: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
