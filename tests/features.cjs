// AEP "Hoy" + Crear con IA, Revisor de Piezas (vision), dictado por voz, hora de los Lives.
const fs = require('fs');
const { run, text, EXTRACT, EMPTY_CAPTURE, BUILDS } = require('./lib.cjs');

const PNG_1X1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const mainCalls = (calls) => calls.filter(c => !EXTRACT(c.body));
const generic = (b) => EXTRACT(b) ? EMPTY_CAPTURE() : text('## Borrador\n\n- **Gancho:** ¿Ya abriste tu carta?\n\n✅ Tu próxima acción: graba hoy.');

run('Features', async (t) => {
  for (const file of BUILDS) {
    console.log('\n════════ ' + file + ' ════════');

    t.section('AEP · Hoy: crear el contenido con IA');
    {
      const { ctx, p, calls, errs } = await t.page(file, { iso: '2026-10-12T17:00:00Z', handler: generic });
      const rowText = await p.locator('#aepcTodayItems .aepc-item-text').first().innerText();
      t.ok(/Reel: carta de cambios/.test(rowText) && await p.locator('#aepcTodayItems button:has-text("Crear con IA")').count() === 1, 'Monday Oct 12: today\'s Reel has a "Crear con IA" button');
      await p.click('#aepcTodayItems button:has-text("Crear con IA")');
      await p.waitForFunction(() => document.querySelector('#aepcGenOut .ai-md h2'), null, { timeout: 8000 });
      const c = mainCalls(calls)[0].body;
      t.ok(c.model === 'claude-sonnet-5-5', 'uses the writing model');
      t.ok(/creator latino auténtico/.test(c.system), 'uses the Reels coach voice');
      t.ok(/Elemento: 📹 Reel: carta de cambios/.test(c.messages[0].content) && /Fase del plan: Llenar el pipeline/.test(c.messages[0].content) && /Regla CMS de la fase/.test(c.messages[0].content), 'prompt carries the item, the plan phase and its CMS rule');
      t.ok(/3 ganchos alternativos/.test(c.messages[0].content) && /NO escribas los disclaimers legales/.test(c.messages[0].content), 'asks for hooks, script, cover text; disclaimers are added by the system');
      const out = await p.locator('#aepcGenOut').innerText();
      t.ok(/Licencia: Isabel Fuentes, agente de seguros con licencia en California \(#0D96598\)/.test(out), 'licence line is appended');
      t.ok(/\[número de organizaciones\]/.test(out) && /Falta poner tus números/.test(out) && !/SHIP/.test(out), 'TPMO text (2027 wording, no SHIP) with a reminder to fill in her numbers');
      t.ok(/Guardado|guardado/.test(await p.locator('#aepcGenStatus').innerText()), 'draft is saved');
      t.ok(await p.locator('#aepcTodayItems button:has-text("Ver borrador")').count() === 1 && await p.locator('#aepcTodayItems button:has-text("Rehacer")').count() === 1, 'row now offers "Ver borrador" and "Rehacer"');
      const before = calls.length;
      await p.click('#aepcTodayItems button:has-text("Ver borrador")');
      t.ok(calls.length === before && /Borrador/.test(await p.locator('#aepcGenOut').innerText()), '"Ver borrador" shows the saved text without calling the AI again');
      // her TPMO numbers fill the disclaimer
      await p.evaluate(() => { saveSettings({ tpmoOrgs: '8', tpmoPlans: '45' }); });
      await p.click('#aepcTodayItems button:has-text("Rehacer")');
      await p.waitForFunction(() => /Actualmente representamos a 8 organizaciones/.test(document.getElementById('aepcGenOut').innerText), null, { timeout: 8000 });
      t.ok(!/Falta poner tus números/.test(await p.locator('#aepcGenOut').innerText()), 'with her numbers saved, the TPMO text is complete');
      // day panel (month view) has the same buttons
      await p.click('#aepcTabMonth'); await p.click('.aepc-day[data-ymd="2026-10-15"]');
      t.ok(await p.locator('#aepcDetailItems button:has-text("Crear con IA")').count() >= 1, 'month view: the selected day also offers "Crear con IA"');
      t.ok(errs.length === 0, 'no JS errors' + (errs[0] ? ' [' + errs[0] + ']' : ''));
      await ctx.close();
    }
    {
      const { ctx, p, calls } = await t.page(file, { iso: '2026-10-10T17:00:00Z', handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : text('### Texto principal 1\nEsto está garantizado para todos.\n') });
      await p.click('button:has-text("Crear mi anuncio de AEP ahora")');
      await p.waitForFunction(() => /garantizado/.test(document.getElementById('aepcGenOut').innerText), null, { timeout: 8000 });
      const c = mainCalls(calls)[0].body;
      t.ok(/PAQUETE para Meta Ads/.test(c.messages[0].content) && /Productos y servicios financieros/.test(c.messages[0].content) && !/64 años|latin[oa]s?\b/i.test(c.messages[0].content) && !/qué tiene hoy/.test(c.messages[0].content), 'urgent button asks for a complete Meta ad package (special ad category, no age targeting, no ethnic wording, no current-plan question)');
      t.ok(/amiga generosa/.test(c.system), 'uses the lead-capture coach voice');
      t.ok(await p.locator('#aepcGenOut_cms').count() === 1, 'a CMS warning appears when the draft says "garantizado"');
      await ctx.close();
    }

    t.section('Hora de los Lives');
    {
      const { ctx, p } = await t.page(file, { iso: '2026-10-10T17:00:00Z' });
      await p.selectOption('#aepLiveTime', '18:30');
      t.ok((await p.evaluate(() => getSettings().liveTime)) === '18:30', 'choice is saved');
      const it = await p.evaluate(() => aepItemsFor('2026-10-15').map(i => i.text).join(' | '));
      t.ok(/Facebook Live 6:30pm/.test(it), 'the calendar uses the new time (' + (it.match(/Live [^:]+:\d\d\w+/) || [''])[0] + ')');
      const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('button:has-text("Descargar a mi calendario")').click()]);
      const ics = fs.readFileSync(await dl.path(), 'utf-8');
      t.ok(ics.includes('DTSTART:20261015T183000') && ics.includes('DTEND:20261015T193000'), '.ics Live is 6:30–7:30 pm');
      await ctx.close();
    }

    t.section('Revisor de Piezas (visión)');
    {
      const { ctx, p, calls, errs } = await t.page(file, {
        handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : (/COMPETENCIA/.test(JSON.stringify(b.messages[0].content)) ? text('## Texto que veo\n\nHola\n\n## Cómo superarlo: 3 ideas de anuncio para Isabel\n\n1. Idea') : text('VEREDICTO: AMARILLO\n\n## Resumen\nFalta el disclaimer TPMO.\n\n## Qué cambiar\n- Agrega el TPMO')),
      });
      await p.evaluate(() => showModule('revisor', null));
      await p.setInputFiles('#revFile', [{ name: 'anuncio.png', mimeType: 'image/png', buffer: PNG_1X1 }]);
      await p.waitForSelector('#revThumbs .rev-thumb');
      t.ok(await p.locator('#revThumbs .rev-thumb').count() === 1 && /1 de 4/.test(await p.locator('#revCount').innerText()), 'thumbnail shows after choosing a file');
      await p.fill('#revCaption', 'Revisa tu plan con Isabel');
      await p.click('#revBtn');
      await p.waitForSelector('.rev-verdict.amarillo', { timeout: 10000 });
      const c = mainCalls(calls)[0].body, content = c.messages[0].content;
      t.ok(c.model === 'claude-opus-5-5' && c.output_config.effort === 'high', 'review uses the deep model (opus-5-5, effort high)');
      t.ok(Array.isArray(content) && content[0].type === 'image' && content[0].source.type === 'base64' && content[0].source.media_type === 'image/jpeg' && content[0].source.data.length > 100, 'sends the image as a base64 JPEG block');
      t.ok(content[content.length - 1].type === 'text' && /PIEZA de marketing/.test(content[content.length - 1].text) && /Revisa tu plan con Isabel/.test(content[content.length - 1].text), 'image(s) come first, then the instructions and her caption');
      t.ok(/TPMO/.test(content[content.length - 1].text) && /tarjeta de Medicare/.test(content[content.length - 1].text), 'checklist covers TPMO, Medicare-card imagery, etc.');
      const o = await p.evaluate(() => ({ badge: document.querySelector('.rev-verdict').innerText, txt: document.getElementById('revOut').innerText, h2: !!document.querySelector('#revOut h2') }));
      t.ok(/Ajustes menores/.test(o.badge) && !/VEREDICTO/.test(o.txt) && o.h2, 'verdict badge shown, the VEREDICTO line is hidden, report formatted');
      // competitor mode
      await p.check('input[name=revMode][value=competencia]');
      await p.click('#revBtn');
      await p.waitForFunction(() => /Cómo superarlo/.test(document.getElementById('revOut').innerText), null, { timeout: 10000 });
      t.ok(/COMPETENCIA/.test(JSON.stringify(mainCalls(calls).pop().body.messages[0].content)) && await p.locator('.rev-verdict').count() === 0, 'competitor mode: different prompt, no verdict badge');
      // limits and errors
      await p.evaluate(() => clearRevImages());
      const many = Array.from({ length: 5 }, (_, i) => ({ name: 'p' + i + '.png', mimeType: 'image/png', buffer: PNG_1X1 }));
      await p.setInputFiles('#revFile', many);
      await p.waitForFunction(() => revImages.length === 4);
      t.ok(/Máximo 4/.test(await p.locator('#revMsg').innerText()), 'keeps 4 images and says so');
      await p.click('#revThumbs .rev-thumb button');
      t.ok(await p.locator('#revThumbs .rev-thumb').count() === 3, 'an image can be removed');
      await p.evaluate(() => clearRevImages());
      await p.setInputFiles('#revFile', [{ name: 'nota.txt', mimeType: 'text/plain', buffer: Buffer.from('hola') }]);
      await p.waitForTimeout(300);
      t.ok(/no es una imagen/.test(await p.locator('#revMsg').innerText()) && await p.locator('#revThumbs .rev-thumb').count() === 0, 'a non-image file is refused with a clear message');
      await p.evaluate(() => addRevFiles([new File([new Uint8Array(4)], 'foto.heic', { type: 'image/heic' })]));
      await p.waitForTimeout(200);
      t.ok(/HEIC/.test(await p.locator('#revMsg').innerText()), 'iPhone HEIC photos get a helpful message');
      const n = calls.length;
      await p.click('#revBtn');
      t.ok(/Sube al menos una imagen/.test(await p.locator('#revStatus').innerText()) && calls.length === n, 'no images → no request');
      // big images are shrunk before upload
      const big = await p.evaluate(async () => {
        const c = document.createElement('canvas'); c.width = 3000; c.height = 2000; const g = c.getContext('2d'); g.fillStyle = '#3D8FD6'; g.fillRect(0, 0, 3000, 2000);
        const blob = await new Promise(r => c.toBlob(r, 'image/png'));
        await addRevFiles([new File([blob], 'grande.png', { type: 'image/png' })]);
        return { w: revImages[0].w, h: revImages[0].h };
      });
      t.ok(Math.max(big.w, big.h) === 1568 && big.w === 1568 && big.h === 1045, 'a 3000×2000 image is shrunk to ' + big.w + '×' + big.h + ' before upload');
      // paste
      await p.evaluate(() => clearRevImages());
      await p.evaluate(async () => {
        const c = document.createElement('canvas'); c.width = 50; c.height = 50; const blob = await new Promise(r => c.toBlob(r, 'image/png'));
        const dt = new DataTransfer(); dt.items.add(new File([blob], 'pegada.png', { type: 'image/png' }));
        document.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
      });
      await p.waitForFunction(() => revImages.length === 1);
      t.ok(true, 'pasting an image (Ctrl+V) works');
      t.ok(errs.length === 0, 'no JS errors' + (errs[0] ? ' [' + errs[0] + ']' : ''));
      await ctx.close();
    }

    t.section('Formularios: casillas y botones con su tamaño');
    {
      // Regresión: la regla global `input{width:100%}` estiraba los radios/casillas y apretaba el texto a una columna angosta.
      const { ctx, p } = await t.page(file, { handler: generic });
      await p.setViewportSize({ width: 1280, height: 900 });
      await p.evaluate(() => openSettings());
      const cfg = await p.evaluate(() => [...document.querySelectorAll('#cfgModal input[type=radio],#cfgModal input[type=checkbox]')].map(i => ({ w: i.getBoundingClientRect().width, tw: i.nextElementSibling.getBoundingClientRect().width })));
      t.ok(cfg.length === 4 && cfg.every(c => c.w > 0 && c.w < 30), 'Ajustes: the 3 quality radios and the memory checkbox are small (' + cfg.map(c => Math.round(c.w)).join('/') + ' px)');
      t.ok(cfg.every(c => c.tw > 300), 'Ajustes: the option text gets the row width, not a thin column (' + cfg.map(c => Math.round(c.tw)).join('/') + ' px)');
      await p.evaluate(() => { closeSettings(); showModule('revisor', null); });
      const rev = await p.evaluate(() => [...document.querySelectorAll('input[name=revMode]')].map(i => ({ w: i.getBoundingClientRect().width, tw: i.parentElement.getBoundingClientRect().width })));
      t.ok(rev.length === 2 && rev.every(r => r.w > 0 && r.w < 30 && r.tw < 400), 'Revisor: the two mode radios sit next to their text (' + rev.map(r => Math.round(r.tw)).join('/') + ' px wide)');
      t.ok(!(await p.locator('#revBar').isVisible()), 'Revisor: "Quitar todas" is hidden until an image is added');
      await p.setInputFiles('#revFile', [{ name: 'a.png', mimeType: 'image/png', buffer: PNG_1X1 }]);
      await p.waitForSelector('#revThumbs .rev-thumb');
      t.ok(await p.locator('#revBar').isVisible(), 'Revisor: "Quitar todas" appears with the first image');
      await p.evaluate(() => clearRevImages());
      t.ok(!(await p.locator('#revBar').isVisible()), 'Revisor: and goes away again after clearing');
      await ctx.close();
    }

    t.section('Dictado por voz');
    {
      const { ctx, p } = await t.page(file, { handler: generic });
      await ctx.addInitScript(() => { const Fake = class { start() { window.__rec = this; } stop() { if (this.onend) this.onend(); } }; window.webkitSpeechRecognition = Fake; window.SpeechRecognition = Fake; });
      await p.reload({ waitUntil: 'networkidle' });
      await p.evaluate(() => showModule('cerebro', null));
      await p.fill('#cerebroInput', 'Primera idea.');
      await p.click('button.mic-btn[onclick*="cerebroInput"]');
      t.ok((await p.locator('button.mic-btn[onclick*="cerebroInput"]').innerText()).includes('Parar') && await p.evaluate(() => window.__rec.lang) === 'es-US', 'mic starts listening in Spanish (button says "Parar")');
      await p.evaluate(() => window.__rec.onresult({ results: [{ isFinal: true, 0: { transcript: 'dame tres ganchos' } }] }));
      t.ok(await p.inputValue('#cerebroInput') === 'Primera idea. dame tres ganchos', 'what she says is added after what she already wrote');
      await p.evaluate(() => window.__rec.onresult({ results: [{ isFinal: true, 0: { transcript: 'dame tres ganchos' } }, { isFinal: false, 0: { transcript: ' para reels' } }] }));
      t.ok(await p.inputValue('#cerebroInput') === 'Primera idea. dame tres ganchos para reels', 'live (interim) words appear while she is still talking');
      await p.click('button.mic-btn[onclick*="cerebroInput"]');
      t.ok((await p.locator('button.mic-btn[onclick*="cerebroInput"]').innerText()).includes('Dictar'), 'second tap stops and restores the button');
      await p.evaluate(() => saveSettings({ voiceLang: 'en-US' }));
      await p.click('button.mic-btn[onclick*="cerebroInput"]');
      t.ok(await p.evaluate(() => window.__rec.lang) === 'en-US', 'dictation language follows the setting');
      await ctx.close();
      const nb = await t.page(file, { handler: generic });
      await nb.ctx.addInitScript(() => { delete window.webkitSpeechRecognition; delete window.SpeechRecognition; });
      await nb.p.reload({ waitUntil: 'networkidle' });
      t.ok(await nb.p.locator('.mic-btn').evaluateAll(els => els.every(e => getComputedStyle(e).display === 'none')), 'browsers without dictation simply do not show the buttons');
      await nb.ctx.close();
    }
  }
});
