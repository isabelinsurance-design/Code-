// Runs every test file in this folder. Usage: node tests/run.cjs   (rebuild first: python3 build.py)
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const files = fs.readdirSync(__dirname).filter(f => f.endsWith('.cjs') && !['lib.cjs', 'run.cjs'].includes(f)).sort();
let failed = 0;
for (const f of files) {
  console.log(`\n████ ${f}`);
  const r = spawnSync(process.execPath, [path.join(__dirname, f)], { stdio: ['ignore', 'pipe', 'pipe'], encoding: 'utf-8', timeout: 600000 });
  const out = (r.stdout || '').split('\n').filter(l => !/agent-proxy|^- cdn\.|For details: curl/.test(l)).join('\n');
  process.stdout.write(out);
  if (r.status !== 0) { failed++; process.stdout.write((r.stderr || '').slice(0, 800)); }
}
console.log(failed ? `\n${failed} test file(s) FAILED` : `\nAll ${files.length} test files passed`);
process.exit(failed ? 1 : 0);
