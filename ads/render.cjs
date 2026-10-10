#!/usr/bin/env node
// Genera las imágenes de los anuncios de Facebook e Instagram a partir de ads/anuncios.json:
//   feed 1080x1350 (4:5) e historias/Reels 1080x1920 (9:16), con la paleta y las fuentes de la marca.
//   node ads/render.cjs [--out carpeta] [--solo id]
// Necesita Playwright (npm i -g playwright). No usa internet: las fuentes están en ads/fuentes/.
const fs = require('fs');
const path = require('path');

let chromium;
try { ({ chromium } = require('playwright')); }
catch (_) { ({ chromium } = require(process.env.PLAYWRIGHT_PATH || '/opt/node22/lib/node_modules/playwright')); }

const DIR = __dirname;
const args = process.argv.slice(2);
const opt = (name, dflt) => { const i = args.indexOf('--' + name); return i < 0 ? dflt : args[i + 1]; };

const FORMATOS = {
  feed: { w: 1080, h: 1350, etiqueta: 'feed' },
  historia: { w: 1080, h: 1920, etiqueta: 'historia' },
};

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
// El titular puede llevar <em>…</em> para resaltar; todo lo demás se escapa.
const sinCortes = (s) => esc(s).replace(/(\+1 \(\d{3}\) \d{3}-\d{4}|1-800-MEDICARE|#0D96598|Medicare\.gov)/g, '<span style="white-space:nowrap">$1</span>');
const titularHtml = (t) => esc(t).replace(/&lt;em&gt;/g, '<em>').replace(/&lt;\/em&gt;/g, '</em>');

function fuentesCss() {
  const b64 = (f) => fs.readFileSync(path.join(DIR, 'fuentes', f)).toString('base64');
  return `
@font-face{font-family:'Poppins';font-weight:600;src:url(data:font/woff2;base64,${b64('Poppins-600.woff2')}) format('woff2')}
@font-face{font-family:'Poppins';font-weight:700;src:url(data:font/woff2;base64,${b64('Poppins-700.woff2')}) format('woff2')}
@font-face{font-family:'Open Sans';font-weight:300 800;src:url(data:font/woff2;base64,${b64('OpenSans.woff2')}) format('woff2')}`;
}

// Mariposa de la marca (símbolo), dibujada en SVG para no depender de la fuente de emojis.
const MARIPOSA = (id) => `
<svg viewBox="-150 -100 300 220" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <defs>
    <linearGradient id="g${id}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3D8FD6"/><stop offset="1" stop-color="#A9D4F0"/></linearGradient>
    <g id="ala${id}">
      <path d="M3,-6 C22,-72 82,-104 128,-78 C162,-56 140,-6 100,10 C68,22 30,16 3,-6 Z" fill="url(#g${id})"/>
      <path d="M3,8 C34,14 78,26 88,60 C96,92 56,108 31,82 C15,66 7,34 3,8 Z" fill="url(#g${id})" opacity=".92"/>
      <circle cx="96" cy="-38" r="15" fill="#fff" opacity=".38"/>
      <circle cx="52" cy="64" r="10" fill="#fff" opacity=".34"/>
    </g>
  </defs>
  <use href="#ala${id}"/>
  <use href="#ala${id}" transform="scale(-1,1)"/>
  <rect x="-5" y="-36" width="10" height="112" rx="5" fill="#333A4D"/>
  <path d="M-3,-34 C-9,-56 -22,-70 -36,-76 M3,-34 C9,-56 22,-70 36,-76" stroke="#333A4D" stroke-width="4" fill="none" stroke-linecap="round"/>
  <circle cx="-37" cy="-77" r="5" fill="#333A4D"/><circle cx="37" cy="-77" r="5" fill="#333A4D"/>
</svg>`;

const FLECHA = `<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h15M13 6l6 6-6 6"/></svg>`;

const TEMAS = {
  suave: { fondo: '#EAF4FB', texto: '#333A4D', sub: '#5C6270', em: 'color:#3D8FD6', kickerBg: '#3D8FD6', kickerFg: '#FFFFFF', kickerBorde: 'transparent',
    c1: 'rgba(169,212,240,.60)', c2: 'rgba(255,255,255,.70)', marca: '#333A4D', pie: '#5C6270', linea: 'rgba(51,58,77,.18)', marcaAgua: .13 },
  cielo: { fondo: '#A9D4F0', texto: '#333A4D', sub: '#333A4D', em: 'background:linear-gradient(transparent 17%,#fff 17%,#fff 91%,transparent 91%);color:#333A4D;padding:0 .12em;-webkit-box-decoration-break:clone;box-decoration-break:clone', kickerBg: '#333A4D', kickerFg: '#FFFFFF', kickerBorde: 'transparent',
    c1: 'rgba(255,255,255,.38)', c2: 'rgba(61,143,214,.22)', marca: '#333A4D', pie: '#333A4D', linea: 'rgba(51,58,77,.28)', marcaAgua: .16 },
  navy: { fondo: '#333A4D', texto: '#FFFFFF', sub: '#D5E4F2', em: 'color:#A9D4F0', kickerBg: 'transparent', kickerFg: '#A9D4F0', kickerBorde: '#A9D4F0',
    c1: 'rgba(61,143,214,.38)', c2: 'rgba(169,212,240,.10)', marca: '#FFFFFF', pie: '#C9D6E6', linea: 'rgba(255,255,255,.22)', marcaAgua: .10 },
};

function html(a, legal, fmt) {
  const t = TEMAS[a.tema], f = FORMATOS[fmt], im = a.imagen;
  const historia = fmt === 'historia';
  // Historias y Reels: Meta tapa ~250 px arriba y ~340 px abajo con su propia interfaz, así que ahí no va nada importante.
  const pad = historia ? '250px 72px 340px' : '72px 72px 0';
  const fsTitulo = (im.fs || 90) * (historia ? 1.08 : 1);
  const largo = im.titulo.replace(/<[^>]+>/g, '').length;
  const fsAuto = largo > 58 ? fsTitulo * 0.86 : largo > 46 ? fsTitulo * 0.93 : fsTitulo;
  return `<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
${fuentesCss()}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:${f.w}px;height:${f.h}px;background:${t.fondo}}
.lienzo{position:relative;width:${f.w}px;height:${f.h}px;overflow:hidden;background:${t.fondo};color:${t.texto};font-family:'Open Sans',sans-serif;display:flex;flex-direction:column;padding:${pad}}
.c1{position:absolute;border-radius:50%;width:${historia ? 700 : 560}px;height:${historia ? 700 : 560}px;right:-230px;top:${historia ? -230 : -250}px;background:${t.c1}}
.c2{position:absolute;border-radius:50%;width:460px;height:460px;left:-240px;bottom:${historia ? 560 : 260}px;background:${t.c2}}
.agua{position:absolute;width:380px;right:-24px;bottom:${historia ? 560 : 240}px;opacity:${t.marcaAgua};transform:rotate(-10deg)}
.agua svg{width:100%;height:auto;display:block}
header{position:relative;display:flex;align-items:center;gap:20px;height:96px;flex:none}
header .logo{width:104px}
header .logo svg{width:100%;height:auto;display:block}
header span{font:600 36px/1 'Poppins',sans-serif;color:${t.marca};letter-spacing:.2px}
main{position:relative;flex:1;display:flex;flex-direction:column;justify-content:center;gap:${historia ? 44 : 36}px;padding:${historia ? '20px 0' : '10px 0 24px'}}
.kicker{align-self:flex-start;font:600 ${historia ? 30 : 27}px/1 'Poppins',sans-serif;letter-spacing:2.4px;padding:15px 28px;border-radius:999px;background:${t.kickerBg};color:${t.kickerFg};border:2px solid ${t.kickerBorde}}
h1{font:700 ${fsAuto.toFixed(1)}px/1.08 'Poppins',sans-serif;letter-spacing:-.6px;text-wrap:balance}
h1 em{font-style:normal;${t.em}}
.sub{font:400 ${historia ? 46 : 42}px/1.32 'Open Sans',sans-serif;color:${t.sub};max-width:900px;text-wrap:balance}
.cta{align-self:flex-start;display:inline-flex;align-items:center;gap:18px;margin-top:${historia ? 14 : 8}px;padding:${historia ? '30px 56px' : '26px 52px'};border-radius:999px;background:#F2A977;color:#333A4D;font:700 ${historia ? 44 : 40}px/1 'Poppins',sans-serif;box-shadow:0 10px 0 rgba(0,0,0,.10)}
.cta svg{width:1.1em;height:1.1em}
footer{position:relative;flex:none;border-top:2px solid ${t.linea};padding:${historia ? '24px 0 0' : '24px 0 36px'};color:${t.pie};font:400 ${historia ? 24 : 23}px/1.38 'Open Sans',sans-serif}
footer p+p{margin-top:6px}
footer p:first-child{font-weight:600}
</style></head><body>
<div class="lienzo ${a.tema} ${fmt}">
  <div class="c1"></div><div class="c2"></div>
  <div class="agua">${MARIPOSA('b')}</div>
  <header><div class="logo">${MARIPOSA('a')}</div><span>Medicare with Isabel</span></header>
  <main>
    <div class="kicker">${esc(im.kicker)}</div>
    <h1>${titularHtml(im.titulo)}</h1>
    <p class="sub">${esc(im.sub)}</p>
    <div class="cta">${esc(im.cta)} ${FLECHA}</div>
  </main>
  <footer>${legal.pie.map((l) => `<p>${sinCortes(l)}</p>`).join('')}</footer>
</div></body></html>`;
}

const nombreArchivo = (i, a, fmt) => String(i + 1).padStart(2, '0') + '-' + a.id + '_' + fmt + '-' + FORMATOS[fmt].w + 'x' + FORMATOS[fmt].h + '.png';

async function main() {
  const datos = JSON.parse(fs.readFileSync(path.join(DIR, 'anuncios.json'), 'utf8'));
  const out = path.resolve(opt('out', path.join(DIR, 'salida', 'imagenes')));
  const solo = opt('solo', null);
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch();
  const hechos = [];
  try {
    const ctx = await browser.newContext({ deviceScaleFactor: 1 });
    await ctx.route('**/*', (r) => (/^(data|about):/.test(r.request().url()) ? r.continue() : r.abort()));   // sin internet
    const page = await ctx.newPage();
    for (const [i, a] of datos.anuncios.entries()) {
      if (solo && a.id !== solo) continue;
      for (const fmt of Object.keys(FORMATOS)) {
        const f = FORMATOS[fmt];
        await page.setViewportSize({ width: f.w, height: f.h });
        await page.setContent(html(a, datos.legal, fmt), { waitUntil: 'load' });
        await page.evaluate(() => document.fonts.ready);
        const archivo = path.join(out, nombreArchivo(i, a, fmt));
        await page.screenshot({ path: archivo, clip: { x: 0, y: 0, width: f.w, height: f.h } });
        hechos.push(archivo);
      }
    }
  } finally {
    await browser.close();
  }
  hechos.forEach((f) => console.log(path.relative(process.cwd(), f) + '  ' + fs.statSync(f).size + ' bytes'));
  console.log(hechos.length + ' imágenes en ' + out);
}

module.exports = { nombreArchivo, FORMATOS };
if (require.main === module) main().catch((e) => { console.error('Error: ' + (e && e.message || e)); process.exitCode = 1; });
