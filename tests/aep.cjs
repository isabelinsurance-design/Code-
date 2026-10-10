// Calendario AEP: the plan, the dates, the checklist, the semáforo, the .ics export.
const fs = require('fs');
const { run, BUILDS, text } = require('./lib.cjs');

run('AEP calendar', async (t) => {
  for (const file of BUILDS) {
    console.log('\n════════ ' + file + ' ════════');

    t.section('Wed 7 Oct (8 days to open): catch-up mode');
    {
      const { ctx, p, errs } = await t.page(file, { iso: '2026-10-07T17:00:00Z' });
      t.ok(await p.locator('#mod-aep').isVisible(), 'AEP calendar is the landing screen during AEP season');
      const title = await p.locator('#aepcHeroTitle').innerText();
      t.ok(title.includes('Faltan 8 días'), 'hero says 8 days to open (' + title.slice(0, 44) + '…)');
      t.ok(await p.locator('#aepcUrgent').isVisible() && await p.locator('#aepcChecks input').count() === 8, 'catch-up checklist with 8 actions');
      t.ok(!/48 h/.test(await p.locator('#aepcChecks').innerText()), 'checklist no longer asks for a 48-hour SOA process');
      t.ok(await p.locator('.aepc-week').count() === 10 && await p.locator('#aepw-pre .aepc-now-badge').count() === 1, '10 periods; "ESTÁS AQUÍ" on the pipeline-filling period');
      await p.locator('#aepcChecks input').first().check();
      t.ok((await p.evaluate(() => JSON.parse(localStorage.getItem('isabel_aep_checks') || '{}'))).ad === true, 'checklist progress persists');
      await p.locator('#aepcTabMonth').click();
      t.ok((await p.locator('.aepc-day.today').getAttribute('data-ymd')) === '2026-10-07', 'month grid highlights today');
      t.ok((await p.locator('.aepc-day[data-ymd="2026-10-15"]').innerText()).includes('ABRE AEP'), 'Oct 15 marked "ABRE AEP"');
      t.ok((await p.locator('.aepc-day[data-ymd="2026-12-07"]').innerText()).includes('ÚLTIMO DÍA'), 'Dec 7 marked as the last day');
      const nov26 = await p.locator('.aepc-day[data-ymd="2026-11-26"]').innerText();
      t.ok(nov26.includes('Thanksgiving') && !nov26.includes('Live'), 'Thanksgiving has no Live');
      t.ok((await p.locator('.aepc-day[data-ymd="2026-12-08"]').innerText()).includes('Apagar los anuncios'), 'Dec 8: turn the AEP ads off');
      const allText = await p.evaluate(() => { const o = []; for (let d = new Date(2026, 9, 1); d <= new Date(2026, 11, 31); d.setDate(d.getDate() + 1)) o.push(...aepItemsFor(_ymd(d)).map(i => i.text)); return o.join('\n'); });
      t.ok((await p.evaluate((x) => cmsRegexIssues(x), allText)).length === 0, 'every calendar item passes the system\'s own CMS checker');
      const after = await p.evaluate(() => ['2026-12-22', '2026-12-24', '2026-12-31'].map(d => aepItemsFor(d).map(i => i.text).join('|')).join(' '));
      t.ok(!/Live|Revisar anuncio/.test(after), 'no Lives or ad reviews after AEP');

      const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('button:has-text("Descargar a mi calendario")').click()]);
      const ics = fs.readFileSync(await dl.path(), 'utf-8');
      t.ok(ics.startsWith('BEGIN:VCALENDAR') && ics.trim().endsWith('END:VCALENDAR') && (ics.match(/BEGIN:VEVENT/g) || []).length > 60, '.ics is well-formed with 60+ events');
      t.ok(ics.includes('DTSTART:20261015T120000'), 'opening-day Live at 12:00 in the .ics');
      t.ok(!/48 h/.test(ics.replace(/ya no exige 48 h[^\\]*/g, '')), '.ics carries the corrected SOA wording');

      await p.locator('button:has-text("Ver estrategia completa")').click(); await p.waitForTimeout(900);
      const fr = p.frames().find(f => f.url().includes('estrategia-aep') || f.url().startsWith('blob:'));
      t.ok(!!fr && (await fr.evaluate(() => document.title)).includes('Estrategia Integral'), 'Meta 300 strategy tool opens from the calendar');
      t.ok(errs.length === 0, 'no JS errors' + (errs[0] ? ' [' + errs[0] + ']' : ''));
      await ctx.close();
    }
    t.section('Tue 20 Oct (AEP open, week 1): semáforo');
    {
      const { ctx, p, errs } = await t.page(file, { iso: '2026-10-20T17:00:00Z' });
      const ti = await p.locator('#aepcHeroTitle').innerText();
      t.ok(ti.includes('día 6 de 54') && ti.includes('Semana 1'), 'hero: ' + ti);
      t.ok(!(await p.locator('#aepcUrgent').isVisible()), 'catch-up checklist hides once AEP is open');
      await p.locator('#aepw-s1 input').fill('30'); await p.locator('#aepw-s1 input').press('Tab'); await p.waitForTimeout(150);
      t.ok((await p.locator('#aepw-s1 .aepc-sem').innerText()).includes('🔴'), '30 of 39 → red');
      await p.locator('#aepw-s1 input').fill('40'); await p.locator('#aepw-s1 input').press('Tab'); await p.waitForTimeout(150);
      t.ok((await p.locator('#aepw-s1 .aepc-sem').innerText()).includes('🟢'), '40 of 39 → green');
      t.ok(errs.length === 0, 'no JS errors');
      await ctx.close();
    }
    t.section('Thu 10 Dec (AEP closed)');
    {
      const { ctx, p, errs } = await t.page(file, { iso: '2026-12-10T17:00:00Z' });
      t.ok(await p.locator('#mod-plan').isVisible(), 'after AEP the landing returns to Plan de Acción');
      await p.locator('#navAep').click();
      t.ok((await p.locator('#aepcHeroTitle').innerText()).includes('cerrado'), 'hero says AEP cerrado');
      t.ok(errs.length === 0, 'no JS errors');
      await ctx.close();
    }
  }
});
