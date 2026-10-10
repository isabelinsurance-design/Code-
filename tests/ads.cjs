// Anuncios de Facebook e Instagram (ads/): textos, reglas CMS/Meta, imágenes y Excel.
const { spawnSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const ADS = path.join(ROOT, 'ads');
let pass = 0, fail = 0;
const ok = (cond, msg) => { console.log((cond ? '  ✓ ' : '  ✗ FAIL: ') + msg); cond ? pass++ : fail++; };

const datos = JSON.parse(fs.readFileSync(path.join(ADS, 'anuncios.json'), 'utf8'));
const A = datos.anuncios;
const textoDe = (a) => [a.imagen.kicker, a.imagen.titulo, a.imagen.sub, a.imagen.cta, ...a.titulares, a.descripcion, a.boton, ...a.textos.A, ...a.textos.B].join('\n');
const todo = A.map(textoDe).join('\n') + '\n' + datos.legal.licencia + '\n' + datos.legal.tpmo + '\n' + datos.legal.pie.join('\n');

console.log('\n— estructura de los 9 anuncios');
{
  ok(A.length === 9 && new Set(A.map((a) => a.id)).size === 9, '9 anuncios con id distinto');
  ok(A.every((a) => a.enciende >= '2026-10-14' && a.apaga === '2026-12-07' && a.enciende <= a.apaga), 'todos se encienden desde el 14 de oct y se apagan el 7 de dic');
  ok(A.filter((a) => a.enciende === '2026-10-14').length === 3, 'lanzamiento con 3 anuncios (carta de cambios, español claro, primer Medicare)');
  ok(A.every((a, i) => i === 0 || a.enciende >= A[i - 1].enciende), 'ordenados por fecha de encendido');
  ok(A.every((a) => a.titulares.length === 2 && a.textos.A.length && a.textos.B.length && a.imagen.kicker && a.imagen.titulo && a.imagen.sub && a.imagen.cta && a.por_que && a.fase), 'cada anuncio trae 2 titulares, textos A y B, imagen, fase y motivo');
  ok(A.every((a) => ['suave', 'cielo', 'navy'].includes(a.tema)), 'cada anuncio usa uno de los 3 fondos de la marca');
}

console.log('\n— límites de Meta');
{
  ok(A.every((a) => a.titulares.every((t) => t.length <= 40)), 'titulares de 40 caracteres o menos');
  ok(A.every((a) => a.descripcion.length <= 30), 'descripciones de 30 caracteres o menos');
  const primera = (lineas) => lineas[0].split(/(?<=[.?!])\s/)[0];
  ok(A.every((a) => ['A', 'B'].every((v) => primera(a.textos[v]).length <= 125)), 'la primera frase de cada texto cabe en los 125 caracteres que se ven antes de «Ver más»');
  ok(A.every((a) => ['A', 'B'].every((v) => a.textos[v].join('\n').length + datos.legal.licencia.length + datos.legal.tpmo.length < 2200)), 'cada texto con su aviso legal cabe en los 2,200 caracteres de Meta');
  ok(A.every((a) => a.boton === 'Más información'), 'el botón es «Más información» (no «Registrarte»: no parece una inscripción)');
}

console.log('\n— reglas CMS (plan 2027)');
{
  const prohibidas = [/gratis|gratuit/i, /\bel mejor\b|\bla mejor\b|\blo mejor\b/i, /garantiz/i, /barato/i, /\boferta/i, /sin costo alguno/i, /100\s*%/, /\bSHIP\b/, /AEP 2027/i,
    /no te pierdas|estás perdiendo|pierdes/i, /última oportunidad|ultima oportunidad/i, /ahorra/i, /mejor que\s+(humana|scan|anthem|alignment|united|aetna|blue shield|l\.?a\.? care)/i];
  ok(prohibidas.every((re) => !re.test(todo)), 'ningún texto usa «gratis», «el mejor», «garantizado», «barato», «oferta», «ahorra», «última oportunidad»…');
  const r = spawnSync(process.execPath, [path.join(ROOT, 'agent', 'empleado.cjs'), 'revisar', '-'], { cwd: ROOT, encoding: 'utf-8', input: todo, timeout: 120000 });
  ok(r.status === 0 && /sin alertas CMS/.test(r.stdout), 'las alertas CMS de la propia app no marcan nada (' + (r.stdout || r.stderr || '').trim().slice(0, 80) + ')');
  ok(!/\$\s?\d/.test(A.map(textoDe).join('\n')), 'los anuncios generales no traen precios ni cifras de un plan');
  ok(A.every((a) => !/\b(sobre|de) tu[s]? (medicina|diabetes|salud|ingreso|edad)/i.test(textoDe(a))) && !/medi-?cal/i.test(A.map(textoDe).join('\n')), 'sin «tus medicinas / tu salud / tu edad» ni «Medi-Cal» dirigidos al lector');
  ok(!/cumples|tienes 65|tienes diabetes|tomas medicinas/i.test(todo), 'sin «¿Cumples 65?» ni preguntas sobre salud (Meta: atributos personales)');
  const urgentes = A.filter((a) => /pocos días|últimos días|todavía hay tiempo/i.test(textoDe(a)));
  ok(urgentes.length >= 1 && urgentes.every((a) => a.enciende >= '2026-11-30'), 'la urgencia («últimos días») solo se enciende desde el 30 de nov (' + urgentes.map((a) => a.id).join(', ') + ')');
  ok(A.filter((a) => /7 de diciembre/.test(textoDe(a))).every((a) => !/oferta|solo hoy|última/i.test(textoDe(a))), 'la fecha del 7 de diciembre aparece sin presión falsa');
}

console.log('\n— aviso legal');
{
  const h = spawnSync(process.execPath, [path.join(ROOT, 'agent', 'empleado.cjs'), 'hoy', '--date', '2026-10-12', '--dias', '1'], { cwd: ROOT, encoding: 'utf-8', timeout: 120000 });
  let disc = '';
  try { disc = JSON.parse(h.stdout).disclaimers; } catch (_) {}
  const tpmoApp = (disc.match(/TPMO \(antes de hablar de cualquier beneficio\): (.+)/) || [])[1];
  const licApp = (disc.match(/- Licencia: (.+)/) || [])[1];
  const tpmoAnuncios = datos.legal.tpmo.replace('{ORG}', '[número de organizaciones]').replace('{PLANES}', '[número de planes]');
  ok(!!tpmoApp && tpmoApp === tpmoAnuncios, 'el aviso TPMO de los anuncios es el mismo texto que usa la app (tpmoText)');
  ok(!!licApp && licApp === datos.legal.licencia, 'la línea de licencia y «no afiliada» es la misma de la app (LICENCIA_LINE)');
  ok(/#0D96598/.test(datos.legal.pie[0]) && /\+1 \(310\) 270-0626/.test(datos.legal.pie[0]) && /No afiliada ni respaldada/.test(datos.legal.pie[1]) && /No ofrecemos todos los planes/.test(datos.legal.pie[1]) && /1-800-MEDICARE/.test(datos.legal.pie[1]), 'el pie de cada imagen lleva licencia, teléfono, «no afiliada», «no ofrecemos todos los planes» y 1-800-MEDICARE');
  ok(!/\bSHIP\b/.test(datos.legal.tpmo), 'el aviso TPMO 2027 no menciona los SHIP');
}

console.log('\n— imágenes (feed 1080x1350 e historias 1080x1920)');
{
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'anuncios-'));
  const r = spawnSync(process.execPath, [path.join(ADS, 'render.cjs'), '--out', tmp, '--solo', 'carta-de-cambios'], { cwd: ROOT, encoding: 'utf-8', timeout: 180000 });
  const dim = (f) => { const b = fs.readFileSync(f); return b.readUInt32BE(0) === 0x89504e47 ? [b.readUInt32BE(16), b.readUInt32BE(20)] : null; };
  const feed = path.join(tmp, '01-carta-de-cambios_feed-1080x1350.png'), hist = path.join(tmp, '01-carta-de-cambios_historia-1080x1920.png');
  ok(r.status === 0 && fs.existsSync(feed) && fs.existsSync(hist), 'render.cjs genera el feed y la historia sin internet');
  ok(fs.existsSync(feed) && JSON.stringify(dim(feed)) === '[1080,1350]' && fs.statSync(feed).size > 30000, 'feed: PNG de 1080x1350 con contenido (' + (fs.existsSync(feed) ? fs.statSync(feed).size : 0) + ' bytes)');
  ok(fs.existsSync(hist) && JSON.stringify(dim(hist)) === '[1080,1920]' && fs.statSync(hist).size > 30000, 'historia: PNG de 1080x1920 con contenido (' + (fs.existsSync(hist) ? fs.statSync(hist).size : 0) + ' bytes)');
  const src = fs.readFileSync(path.join(ADS, 'render.cjs'), 'utf8');
  ok(/250px 72px 340px/.test(src), 'las historias dejan libres 250 px arriba y 340 px abajo (zona que tapa Meta)');
  ok(['Poppins-600.woff2', 'Poppins-700.woff2', 'OpenSans.woff2'].every((f) => fs.existsSync(path.join(ADS, 'fuentes', f))), 'las fuentes de la marca (Poppins y Open Sans) van en el repositorio');
}

console.log('\n— Excel');
{
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'libro-'));
  const out = path.join(tmp, 'anuncios.xlsx');
  const r = spawnSync('python3', [path.join(ADS, 'libro.py'), out], { cwd: ROOT, encoding: 'utf-8', timeout: 120000 });
  if (r.status !== 0 && /No module named 'openpyxl'|ENOENT/.test((r.stderr || '') + (r.error || ''))) {
    console.log('  (omitido: falta python3 o openpyxl)');
  } else {
    ok(r.status === 0 && fs.existsSync(out), 'libro.py arma el Excel (' + (r.status === 0 ? 'ok' : (r.stderr || '').trim().split('\n').pop()) + ')');
    const py = `
import openpyxl, sys
wb = openpyxl.load_workbook(sys.argv[1])
print('|'.join(wb.sheetnames))
n = sum(1 for ws in wb.worksheets for row in ws.iter_rows() for c in row if isinstance(c.value, str) and c.value.startswith('='))
print(n)
ws = wb['Anuncios']
print(ws['B6'].value[:40])
print(max(len(s) for ws in wb.worksheets for row in ws.iter_rows() for c in row for s in ([c.value] if isinstance(c.value, str) and not c.value.startswith('=') else [])))
`;
    const v = spawnSync('python3', ['-I', '-c', py, out], { encoding: 'utf-8' });
    const [hojas, formulas, tpmo] = (v.stdout || '').split('\n');
    ok(hojas === 'Empieza aquí|Campañas|Anuncios|Formulario|Semana a semana|Cumplimiento|Planes (con aprobación)|Seguimiento|Cómo subirlo', 'las 9 hojas, en este orden: ' + hojas);
    ok(Number(formulas) >= 100, 'las fórmulas (aviso TPMO que se completa solo, largos, totales, costo por lead) están en el archivo: ' + formulas);
    ok(/CONCATENATE/.test(tpmo), 'el aviso TPMO usa CONCATENATE con las casillas de «Empieza aquí»');
  }
}

console.log(`\nAnuncios: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
