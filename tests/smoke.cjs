// Every tab opens, every tool loads with the interceptor and without JS errors.
const fs = require('fs');
const path = require('path');
const { run, BUILDS, ROOT } = require('./lib.cjs');

run('Smoke', async (t) => {
  const toolFiles = fs.readdirSync(path.join(ROOT, 'tools')).filter(f => f.endsWith('.html'));
  for (const file of BUILDS) {
    console.log('\n════════ ' + file + ' ════════');
    const { ctx, p, errs } = await t.page(file);
    const mods = await p.evaluate(() => [...document.querySelectorAll('.sidebar .nav-item[onclick^="showModule"]')].map(b => b.getAttribute('onclick').match(/'(\w+)'/)[1]));
    const closed = [];
    for (const m of mods) { await p.evaluate((m) => showModule(m, null), m); if (!(await p.locator('#mod-' + m).isVisible())) closed.push(m); }
    t.ok(mods.length >= 20 && closed.length === 0, `${mods.length} tabs, all open` + (closed.length ? ' — FAILED: ' + closed : ''));
    await p.locator('#toolsToggle').click();
    const names = await p.locator('#toolsCollapsible .nav-item').evaluateAll(els => els.map(e => e.getAttribute('onclick').match(/'([^']+\.html)'/)[1]));
    t.ok(names.length === toolFiles.length, `sidebar lists all ${toolFiles.length} tools (found ${names.length})`);
    t.ok(await p.locator('.sidebar-toggle-count').first().innerText() === String(toolFiles.length), 'tool count badge matches the folder');
    const bad = [];
    for (const n of names) {
      const ok = await p.evaluate((n) => new Promise((resolve) => {
        const f = document.getElementById('toolFrame');
        const timer = setTimeout(() => resolve(false), 10000);
        f.addEventListener('load', () => { clearTimeout(timer); try { resolve(!!f.contentWindow.__isabelFetchPatched); } catch (e) { resolve(false); } }, { once: true });
        openTool(n, null, n);
      }), n);
      if (!ok) bad.push(n);
    }
    t.ok(bad.length === 0, 'every tool opens inside the app with the shared-key interceptor' + (bad.length ? ' — FAILED: ' + bad : ''));
    t.ok(errs.length === 0, 'no JS errors in the app' + (errs[0] ? ' [' + errs[0] + ']' : ''));
    await ctx.close();
  }
  console.log('\n— each tool opened on its own');
  const { ctx, p } = await t.page('index.html');
  const issues = [];
  for (const f of toolFiles) {
    const pg = await ctx.newPage(); const errs = []; pg.on('pageerror', e => errs.push(e.message));
    await pg.goto(t.base + 'tools/' + f, { waitUntil: 'load' }); await pg.waitForTimeout(250);
    if (errs.length || !(await pg.evaluate(() => !!window.__isabelFetchPatched))) issues.push(f + ' ' + errs.join(' | '));
    await pg.close();
  }
  t.ok(issues.length === 0, `all ${toolFiles.length} tools load with no JS errors` + (issues.length ? ' — ' + issues.join('; ') : ''));
  await ctx.close();
});
