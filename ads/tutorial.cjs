#!/usr/bin/env node
// Arma la guía paso a paso para que Isabel suba la campaña en Meta Ads Manager (página con botones de copiar).
//   node ads/tutorial.cjs [--out carpeta] [--img carpeta-con-las-18-imagenes]
// Usa ads/tutorial.html como plantilla y mete dentro los textos de ads/anuncios.json y miniaturas JPEG de las imágenes.
// Si no encuentra las imágenes, las genera con ads/render.cjs. Necesita Playwright.
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

let chromium;
try { ({ chromium } = require('playwright')); }
catch (_) { ({ chromium } = require(process.env.PLAYWRIGHT_PATH || '/opt/node22/lib/node_modules/playwright')); }

const DIR = __dirname;
const args = process.argv.slice(2);
const opt = (name, dflt) => { const i = args.indexOf('--' + name); return i < 0 ? dflt : args[i + 1]; };
const { nombreArchivo, FORMATOS } = require('./render.cjs');

async function miniaturas(imgDir, anuncios) {
  const browser = await chromium.launch();
  const out = {};
  try {
    const page = await browser.newPage();
    for (const [i, a] of anuncios.entries()) {
      for (const fmt of Object.keys(FORMATOS)) {
        const f = nombreArchivo(i, a, fmt);
        const w = fmt === 'feed' ? 300 : 212, h = Math.round(w * FORMATOS[fmt].h / FORMATOS[fmt].w);
        const png = fs.readFileSync(path.join(imgDir, f)).toString('base64');
        await page.setViewportSize({ width: w, height: h });
        await page.setContent(`<body style="margin:0"><img src="data:image/png;base64,${png}" style="display:block;width:${w}px;height:${h}px"></body>`, { waitUntil: 'load' });
        const jpg = await page.screenshot({ type: 'jpeg', quality: 74, clip: { x: 0, y: 0, width: w, height: h } });
        out[f] = 'data:image/jpeg;base64,' + jpg.toString('base64');
      }
    }
  } finally {
    await browser.close();
  }
  return out;
}

async function main() {
  const datos = JSON.parse(fs.readFileSync(path.join(DIR, 'anuncios.json'), 'utf8'));
  const outDir = path.resolve(opt('out', path.join(DIR, 'salida', 'tutorial')));
  const imgDir = path.resolve(opt('img', path.join(DIR, 'salida', 'imagenes')));
  const faltan = datos.anuncios.some((a, i) => Object.keys(FORMATOS).some((fmt) => !fs.existsSync(path.join(imgDir, nombreArchivo(i, a, fmt)))));
  if (faltan) {
    const r = spawnSync(process.execPath, [path.join(DIR, 'render.cjs'), '--out', imgDir], { stdio: 'inherit' });
    if (r.status !== 0) throw new Error('no pude generar las imágenes');
  }
  const thumbs = await miniaturas(imgDir, datos.anuncios);
  const paquete = { legal: datos.legal, campana: datos.campana, formulario: datos.formulario, anuncios: datos.anuncios, thumbs };
  // Dentro de <script type="application/json"> nada puede cerrar la etiqueta: se escapa cada "<".
  const json = JSON.stringify(paquete).replace(/</g, '\\u003c');
  const plantilla = fs.readFileSync(path.join(DIR, 'tutorial.html'), 'utf8');
  if (!plantilla.includes('/*DATOS*/')) throw new Error('la plantilla no tiene el marcador /*DATOS*/');
  fs.mkdirSync(outDir, { recursive: true });
  const archivo = path.join(outDir, 'index.html');
  fs.writeFileSync(archivo, plantilla.replace('/*DATOS*/', () => json));
  console.log(path.relative(process.cwd(), archivo) + '  ' + fs.statSync(archivo).size + ' bytes');
}

main().catch((e) => { console.error('Error: ' + (e && e.message || e)); process.exitCode = 1; });
