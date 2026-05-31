// Genera los íconos PWA a partir del SVG source.
// Corre una vez (o cuando cambies el SVG): node scripts/generate-icons.mjs
import sharp from 'sharp';
import { writeFileSync, readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = join(__dirname, '..', 'public');

const svgSquare = readFileSync(join(PUBLIC_DIR, 'icon-source.svg'));
const svgMaskable = readFileSync(join(PUBLIC_DIR, 'icon-source-maskable.svg'));

const targets = [
  { in: svgSquare,   name: 'icon-192.png',          size: 192 },
  { in: svgSquare,   name: 'icon-512.png',          size: 512 },
  { in: svgMaskable, name: 'icon-512-maskable.png', size: 512 },
  { in: svgSquare,   name: 'apple-touch-icon.png',  size: 180 },
];

for (const t of targets) {
  const out = await sharp(t.in).resize(t.size, t.size).png().toBuffer();
  writeFileSync(join(PUBLIC_DIR, t.name), out);
  console.log(`✓ ${t.name} (${t.size}x${t.size})`);
}

// favicon como SVG es soportado por todos los browsers modernos
writeFileSync(join(PUBLIC_DIR, 'favicon.svg'), svgSquare);
console.log('✓ favicon.svg');
