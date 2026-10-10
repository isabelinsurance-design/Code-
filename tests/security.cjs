// Stored-XSS, restore validation, tool trackers.
const { run, BUILDS } = require('./lib.cjs');

run('Security', async (t) => {
  for (const file of BUILDS) {
    console.log('\n════════ ' + file + ' ════════');
    const { ctx, p, errs } = await t.page(file);
    p.on('dialog', d => d.accept());
    const evil = '<img src=x onerror="window.__xss=true">';

    t.section('CRM and calendar never run pasted markup');
    await p.evaluate(() => showModule('crm', null));
    await p.evaluate((h) => { leadName.value = h; leadPhone.value = h; leadNotes.value = h; addLead(); }, evil);
    await p.waitForTimeout(250);
    t.ok(!(await p.evaluate(() => window.__xss)), 'lead name/phone/notes payload does not execute');
    t.ok(await p.locator('#mod-crm .lead-name').first().innerText() === evil, 'lead name is shown as text');
    await p.evaluate((h) => { calendarItems['Lunes'].push({ type: 'post', text: h }); saveCalendar(); renderCalendar(); }, evil);
    t.ok(!(await p.evaluate(() => window.__xss)), 'calendar payload does not execute');
    await p.evaluate(() => { calendarItems['Martes'] = [{ type: '"><script>alert(1)</script>', text: 'safe' }]; renderCalendar(); });
    t.ok(!(await p.locator('#calGrid').innerHTML()).includes('<script>'), 'hostile calendar type cannot inject a script');
    await p.evaluate(() => { calendarItems['Miércoles'] = [{ type: 'reel', text: 'Tip viral semanal' }]; saveCalendar(); });
    await p.reload({ waitUntil: 'networkidle' });
    t.ok(await p.evaluate(() => calendarItems['Miércoles'][0].text) === 'Tip viral semanal', 'calendar persists across reload');

    t.section('Restore validation');
    const r = await p.evaluate(() => {
      localStorage.setItem('isabel_anthropic_key', 'sk-ant-ORIGINAL');
      const bad = {
        isabel_arbitrary_evil: '<script>steal()</script>',
        isabel_crm_leads: { not: 'an array' },
        isabel_memoria_hechos: [{ id: '1', text: 'azul', date: 'x', __proto: 'junk', evil: '<img onerror=alert(1)>' }],
        isabel_anthropic_key: 'sk-ant-MALICIOUS',
        isabel_settings: { preset: 'maximo' },
        isabel_t65_spend: '150',
      };
      const out = {};
      Object.entries(bad).forEach(([k, v]) => { if (k === 'isabel_anthropic_key') return; const c = _validateBackupValue(k, v); if (c !== null) out[k] = c; });
      return { keys: Object.keys(out), h: JSON.parse(out.isabel_memoria_hechos || '[]')[0], key: localStorage.getItem('isabel_anthropic_key') };
    });
    t.ok(!r.keys.includes('isabel_arbitrary_evil') && !r.keys.includes('isabel_crm_leads'), 'unknown keys and wrong-typed values are dropped');
    t.ok(r.key === 'sk-ant-ORIGINAL', 'a backup can never overwrite the API key');
    t.ok(r.h && r.h.text === 'azul' && !('evil' in r.h) && !('__proto' in r.h), 'only allow-listed fields survive');
    t.ok(r.keys.includes('isabel_settings') && r.keys.includes('isabel_t65_spend'), 'settings and T65 spend travel with the backup');

    t.section('T65 tracker');
    await p.evaluate(() => { document.getElementById('toolsToggle').click(); openTool('t65-lead-machine.html', null, 'x'); });
    await p.waitForTimeout(900);
    const fr = p.frames().find(f => f.url().includes('t65') || f.url().startsWith('blob:'));
    await fr.evaluate((h) => { document.getElementById('leadName').value = h; addLead(); }, evil);
    await p.waitForTimeout(250);
    t.ok(!(await fr.evaluate(() => window.__xssT65)), 'T65 tracker payload does not execute');
    t.ok(errs.length === 0, 'no JS errors' + (errs[0] ? ' [' + errs[0] + ']' : ''));
    await ctx.close();
  }
});
