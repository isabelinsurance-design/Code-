// AI layer: models, streaming, safe rendering, fallbacks, web search, context, settings, tool interceptor.
const { run, sse, text, err, json, EXTRACT, EMPTY_CAPTURE, BUILDS, TEST_KEY } = require('./lib.cjs');

const mainCalls = (calls) => calls.filter(c => !EXTRACT(c.body));
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
async function ask(p, text) { await p.evaluate(() => showModule('cerebro', null)); await p.fill('#cerebroInput', text); await p.evaluate(() => askCerebro()); }

run('AI core', async (t) => {
  for (const file of BUILDS) {
    console.log('\n════════ ' + file + ' ════════');

    // ───────────────────────────── streaming + markdown + context ─────────────────────────────
    t.section('Cerebro: model, parameters, streaming, markdown, context');
    {
      const { ctx, p, calls, errs } = await t.page(file, {
        handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : text('## Hooks\n\n- **Uno** rápido\n- Dos\n\n1. Primero\n\n✅ Tu próxima acción: graba el Reel.'),
      });
      await p.evaluate(() => showModule('cerebro', null));
      await p.fill('#cerebroInput', 'Dame 3 ganchos para Reels');
      await p.evaluate(() => askCerebro());
      await p.waitForFunction(() => document.querySelector('#cerebroOutput .ai-md h2'));
      await p.waitForTimeout(300);
      const main = mainCalls(calls)[0], cap = calls.find(c => EXTRACT(c.body));
      t.ok(main.body.model === 'claude-sonnet-5-5', 'writing uses claude-sonnet-5-5 (got ' + main.body.model + ')');
      t.ok(main.body.stream === true, 'request is streamed');
      t.ok(main.body.output_config && main.body.output_config.effort === 'medium', 'effort = medium for chat');
      t.ok(main.body.max_tokens >= 4000, 'max_tokens leaves room for thinking (' + main.body.max_tokens + ')');
      t.ok(!('temperature' in main.body) && !('top_p' in main.body), 'no sampling parameters (rejected by current models)');
      t.ok(main.headers['x-api-key'] === TEST_KEY && main.headers['anthropic-version'] === '2023-06-01' && main.headers['anthropic-dangerous-direct-browser-access'] === 'true', 'auth + browser headers sent');
      t.ok(/CONTEXTO DE HOY/.test(main.body.system) && /abre en 5 días/.test(main.body.system), 'system prompt carries today\'s date and "AEP abre en 5 días"');
      t.ok(/REGLAS CMS PARA ESTE AEP/.test(main.body.system) && /NO exige esperar 48 horas/.test(main.body.system), 'system prompt carries the 2027 CMS rules (no 48h SOA wait)');
      t.ok(!!cap && cap.body.model === 'claude-haiku-5-5' && cap.body.output_config.effort === 'low', 'memory capture uses the fast model (haiku-5-5, effort low)');
      t.ok(!!cap && !/CONTEXTO DE HOY/.test(cap.body.system), 'memory capture does not receive the context block');
      const dom = await p.evaluate(() => { const o = document.getElementById('cerebroOutput'); return { h2: o.querySelector('h2')?.textContent, li: o.querySelectorAll('li').length, strong: o.querySelector('strong')?.textContent, txt: o.innerText, raw: o.dataset.raw }; });
      t.ok(dom.h2 === 'Hooks' && dom.li >= 3 && dom.strong === 'Uno', 'markdown renders as headings, lists and bold');
      t.ok(!/##|\*\*/.test(dom.txt), 'no raw markdown marks left on screen');
      await p.evaluate(() => copyAI('cerebroOutput'));
      const clip = await p.evaluate(() => navigator.clipboard.readText());
      t.ok(/• Dos/.test(clip) && !/##|\*\*/.test(clip), 'copy button gives clean text for pasting into Facebook');
      const usage = await p.evaluate(() => JSON.parse(localStorage.getItem('isabel_usage') || '{}'));
      t.ok(Object.values(usage)[0] && Object.values(usage)[0].calls >= 2, 'usage is tracked for the monthly estimate');
      t.ok(errs.length === 0, 'no JS errors' + (errs[0] ? ' [' + errs[0] + ']' : ''));
      await ctx.close();
    }

    t.section('Context: memory on/off, AEP phase');
    {
      const storage = { isabel_memoria_hechos: [{ id: '1', text: 'Prefiere hablar de dental', date: 'x' }], isabel_memoria_tareas: [{ id: '2', text: 'Llamar a SCAN', due: 'viernes', done: false, date: 'x' }] };
      for (const [useMemory, label] of [[true, 'on'], [false, 'off']]) {
        const { ctx, p, calls } = await t.page(file, { storage, settings: { useMemory }, handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : text('ok') });
        await ask(p, 'hola amiga'); await p.waitForTimeout(500);
        const sys = mainCalls(calls)[0].body.system;
        t.ok(/Prefiere hablar de dental/.test(sys) === useMemory && /Llamar a SCAN/.test(sys) === useMemory, `memory ${label}: facts and tasks ${useMemory ? 'are' : 'are NOT'} sent`);
        t.ok(/Hoy es sábado, 10 de octubre de 2026/i.test(sys) || /sábado.*10 de octubre de 2026/i.test(sys), `memory ${label}: today's date is always sent`);
        await ctx.close();
      }
      const { ctx, p, calls } = await t.page(file, { iso: '2026-10-20T17:00:00Z', handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : text('ok') });
      await ask(p, 'hola'); await p.waitForTimeout(400);
      t.ok(/AEP está ABIERTO: día 6 de 54/.test(mainCalls(calls)[0].body.system), 'on Oct 20 the context says "AEP está ABIERTO: día 6 de 54"');
      await ctx.close();
    }

    t.section('Safe rendering: AI text can never run code');
    {
      const evil = 'Hola <img src=x onerror="window.__x=1"> <script>window.__y=1</script> [malo](javascript:alert(1)) [bueno](https://example.com/a?b=1&c=2)';
      const { ctx, p } = await t.page(file, { handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : text(evil) });
      await ask(p, 'x'); await p.waitForTimeout(500);
      const r = await p.evaluate(() => { const o = document.getElementById('cerebroOutput'); return { x: window.__x, y: window.__y, imgs: o.querySelectorAll('img,script').length, js: o.querySelectorAll('a[href^="javascript:"]').length, good: [...o.querySelectorAll('a')].map(a => a.href + '|' + a.rel), txt: o.innerText }; });
      t.ok(!r.x && !r.y && r.imgs === 0, 'no <img onerror> or <script> from AI text is executed or inserted');
      t.ok(/<img src=x/.test(r.txt), 'the markup is shown as harmless text');
      t.ok(r.js === 0 && r.good.length === 1 && /noopener/.test(r.good[0]), 'only https links become links, with rel=noopener');
      await ctx.close();
    }

    // ───────────────────────────── fallbacks and friendly errors ─────────────────────────────
    t.section('Model fallback and friendly errors');
    {
      const { ctx, p, calls } = await t.page(file, {
        handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : (b.model === 'claude-sonnet-5-5' ? err(404, 'not_found_error', 'model: claude-sonnet-5-5') : text('Respuesta con modelo de respaldo')),
      });
      await ask(p, 'x'); await p.waitForTimeout(600);
      let models = mainCalls(calls).map(c => c.body.model);
      t.ok(models[0] === 'claude-sonnet-5-5' && models[1] === 'claude-sonnet-5', 'unavailable model → automatically tries the next one (' + models.join(' → ') + ')');
      t.ok((await p.locator('#cerebroOutput').innerText()).includes('modelo de respaldo'), 'the answer still arrives');
      await p.evaluate(() => askCerebro()); await p.waitForTimeout(500);
      models = mainCalls(calls).map(c => c.body.model);
      t.ok(models[2] === 'claude-sonnet-5', 'the next request skips the model that failed (' + models[2] + ')');
      await ctx.close();
    }
    for (const [name, resp, expect] of [
      ['401', err(401, 'authentication_error', 'invalid x-api-key'), /API Key no es válida/],
      ['credit balance', err(400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API.'), /no tiene saldo/],
    ]) {
      const { ctx, p } = await t.page(file, { handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : resp });
      await ask(p, 'x'); await p.waitForTimeout(500);
      const e = await p.evaluate(() => ({ t: document.querySelector('#cerebroOutput .ai-error')?.innerText || '', btn: !!document.querySelector('#cerebroOutput .ai-error button') }));
      t.ok(expect.test(e.t) && e.btn, `${name}: plain-Spanish message with a ⚙️ Ajustes button`);
      await ctx.close();
    }
    {
      const { ctx, p } = await t.page(file, { key: false, handler: () => text('no debería llamarse') });
      await p.evaluate(() => { localStorage.removeItem('isabel_anthropic_key'); });
      await p.reload({ waitUntil: 'networkidle' });
      await ask(p, 'x'); await p.waitForTimeout(300);
      t.ok(/Falta tu API Key/.test(await p.locator('#cerebroOutput').innerText()), 'no key → clear message instead of a failed request');
      await ctx.close();
    }

    // ───────────────────────────── Radar: web search ─────────────────────────────
    t.section('Radar: deep model, web search, live status, sources');
    {
      const { ctx, p, calls } = await t.page(file, {
        handler: (b) => b.tools ? sse([
          { t: 'thinking' }, { t: 'text', text: 'Voy a buscar.' },
          { t: 'search', query: 'quotely facebook ads medicare', results: [{ title: 'Quotely', url: 'https://quotely.io/x' }, { title: 'Malo', url: 'javascript:alert(1)' }, { title: 'Quotely otra vez', url: 'https://quotely.io/x' }] },
          { t: 'text', text: '## 🧭 ANÁLISIS CHIEF OF STAFF\n\n### Resumen ejecutivo\nTodo bien.\n\n✅ Tu próxima acción: lanza el anuncio.', cites: [{ url: 'https://example.com/c', title: 'Ejemplo' }] },
        ]) : text('ok'),
      });
      await p.evaluate(() => showModule('intelmkt', null));
      await p.evaluate(() => { window.__st = []; const el = document.getElementById('intelMktStatus'); const d = Object.getOwnPropertyDescriptor(Node.prototype, 'textContent'); Object.defineProperty(el, 'textContent', { get() { return d.get.call(this); }, set(v) { window.__st.push(v); d.set.call(this, v); }, configurable: true }); });
      await p.click('#intelMktBtn');
      await p.waitForFunction(() => /Radar completo/.test(document.getElementById('intelMktStatus').textContent), null, { timeout: 8000 });
      const b = mainCalls(calls)[0].body;
      t.ok(b.model === 'claude-opus-5-5' && b.output_config.effort === 'high', 'Radar uses the deep model (opus-5-5, effort high)');
      t.ok(b.tools && b.tools[0].type === 'web_search_20250305' && b.tools[0].max_uses === 6 && b.tools[0].user_location.city === 'Los Angeles', 'web search tool with Los Angeles location');
      t.ok(b.max_tokens >= 16000, 'room for long reports (' + b.max_tokens + ' tokens)');
      const st = await p.evaluate(() => window.__st);
      t.ok(st.some(s => /Pensando/.test(s)) && st.some(s => /Buscando: quotely facebook ads medicare/.test(s)), 'live status shows thinking and the search being run');
      const out = await p.evaluate(() => ({ h2: document.querySelector('#intelMktOutput h2')?.textContent, links: [...document.querySelectorAll('#intelMktOutput .ai-sources a')].map(a => a.href + '|' + a.rel), end: document.getElementById('intelMktStatus').textContent }));
      t.ok(/ANÁLISIS CHIEF OF STAFF/.test(out.h2), 'report renders with section headings');
      t.ok(out.links.length === 2 && out.links.every(l => /noopener/.test(l)) && !out.links.some(l => /javascript/.test(l)), 'sources: 2 unique https links (duplicate and javascript: removed)');
      t.ok(/2 fuentes/.test(out.end), 'status line reports 2 sources');
      const runs = await p.evaluate(() => JSON.parse(localStorage.getItem('isabel_intel_runs') || '[]'));
      t.ok(runs[0] && runs[0].sources.length === 2 && runs[0].snapshot, 'run saved with sources and the self-grade snapshot');
      await ctx.close();
    }
    {
      let n = 0;
      const { ctx, p, calls } = await t.page(file, {
        handler: (b) => { if (EXTRACT(b)) return EMPTY_CAPTURE(); n++; return n === 1 ? sse([{ t: 'search', query: 'q1', results: [{ title: 'R', url: 'https://r.example/1' }] }], 'pause_turn') : text('Resultado final tras la pausa'); },
      });
      const out = await p.evaluate(async () => { const r = await callClaude('sys', 'pregunta', 'intelMktOutput', { tier: 'deep', webSearch: 3 }); return r; });
      const mc = mainCalls(calls);
      const asst = mc[1] && mc[1].body.messages[1];
      t.ok(mc.length === 2 && out === 'Resultado final tras la pausa', 'pause_turn → continues automatically');
      t.ok(asst && asst.role === 'assistant' && asst.content.some(x => x.type === 'server_tool_use' && x.input.query === 'q1') && asst.content.some(x => x.type === 'web_search_tool_result' && x.content[0].encrypted_content === 'ENC'), 'the paused turn is sent back unchanged (search + encrypted results)');
      await ctx.close();
    }
    {
      const { ctx, p, calls } = await t.page(file, {
        handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : (b.tools ? err(400, 'invalid_request_error', 'web search is not enabled for this organization') : text('Respuesta sin web')),
      });
      await p.evaluate(() => callClaude('sys', 'pregunta', 'intelMktOutput', { tier: 'deep', webSearch: 3 }));
      const mc = mainCalls(calls);
      t.ok(mc.length === 2 && !mc[1].body.tools, 'web search disabled in the account → retries without it');
      t.ok(/no pude buscar en la web/.test(await p.locator('#intelMktOutput').innerText()), 'and tells her it could not search');
      await ctx.close();
    }

    // ───────────────────────────── memory capture + orchestrator + equipo ─────────────────────────────
    t.section('Capture-by-default, orchestrator, Equipo IA');
    {
      const cap = JSON.stringify({ capturas: [{ tipo: 'hecho', texto: 'Mi color favorito es azul' }, { tipo: 'persona', nombre: 'Carlos', rol: 'cardiólogo', notas: '' }, { tipo: 'tarea', texto: 'Llamar a SCAN', vence: 'viernes' }, { tipo: 'compromiso', quien: 'Sami', que: 'traer el statement', vence: 'martes' }, 'basura', null] });
      const { ctx, p } = await t.page(file, { handler: (b) => EXTRACT(b) ? text(cap) : text('ok') });
      await ask(p, 'Carlos es mi cardiólogo y mi color favorito es azul'); await p.waitForTimeout(600);
      await p.evaluate(() => askCerebro()); await p.waitForTimeout(600);
      const m = await p.evaluate(() => ({ h: memoria.hechos.length, p: memoria.personas.length, t: memoria.tareas.length, c: memoria.compromisos.length }));
      t.ok(m.h === 1 && m.p === 1 && m.t === 1 && m.c === 1, 'captures one of each and does NOT duplicate on a repeated message (' + JSON.stringify(m) + ')');
      await ctx.close();
    }
    {
      const { ctx, p } = await t.page(file, { handler: (b) => EXTRACT(b) ? text('esto no es json') : text('ok') });
      await ask(p, 'hola'); await p.waitForTimeout(400);
      t.ok((await p.locator('#cerebroOutput').innerText()).includes('ok'), 'garbage from the capture step never breaks the answer');
      await ctx.close();
    }
    {
      const { ctx, p, calls } = await t.page(file, {
        handler: (b) => /Eres un orquestador/.test(b.system) ? text('{"coaches":["ganchos","reels"],"razon":"hooks y reels"}')
          : (/^Pregunta original/.test(b.messages[0].content) ? text('## Síntesis\n\nTodo junto.\n\n✅ Tu próxima acción: graba.') : (EXTRACT(b) ? EMPTY_CAPTURE() : text('Voz del coach'))),
      });
      await p.evaluate(() => showModule('route', null));
      await p.fill('#routeInput', 'Quiero un Reel con buen gancho'); await p.click('#routeBtn');
      await p.waitForFunction(() => /Listo en/.test(document.getElementById('routeStatus').textContent), null, { timeout: 8000 });
      const route = calls.find(c => /Eres un orquestador/.test(c.body.system));
      const coaches = calls.filter(c => /Voces?|voz/.test(c.body.system) && !/orquestador/.test(c.body.system) && !/^Pregunta original/.test(c.body.messages[0].content));
      t.ok(route.body.model === 'claude-haiku-5-5', 'routing uses the fast model');
      t.ok(coaches.length === 2 && coaches.every(c => c.body.model === 'claude-sonnet-5-5'), '2 coaches run on the writing model');
      t.ok(/Síntesis/.test(await p.locator('#routeOutput h2').innerText()), 'integrated answer streams into the page as formatted text');
      t.ok(await p.locator('#routeIndividual .route-voice').count() === 2, 'individual voices are kept');
      await ctx.close();
    }
    {
      const arrivals = [];
      const { ctx, p } = await t.page(file, { handler: async (b) => { if (EXTRACT(b)) return EMPTY_CAPTURE(); arrivals.push(Date.now()); await sleep(200); return text('## Borrador\n\n- listo'); } });
      await p.evaluate(() => showModule('equipo', null));
      await p.fill('#apiKeyInput', TEST_KEY);
      await p.click('#eqRunBtn');
      await p.waitForFunction(() => /Listo en/.test(document.getElementById('eqStatus').textContent), null, { timeout: 10000 });
      t.ok(arrivals.length === 6 && Math.max(...arrivals) - Math.min(...arrivals) < 250, '6 agents start together (' + (Math.max(...arrivals) - Math.min(...arrivals)) + ' ms apart)');
      t.ok(await p.locator('#mod-equipo .ai-md h2').count() === 6, 'all 6 cards show formatted drafts');
      await ctx.close();
    }

    // ───────────────────────────── settings window ─────────────────────────────
    t.section('Settings: quality, connection test, TPMO numbers, usage');
    {
      const { ctx, p, calls } = await t.page(file, { handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : text('OK') });
      await p.click('.gear-btn');
      t.ok(await p.locator('#cfgModal.open').count() === 1 && await p.locator('input[name=cfgPreset][value=equilibrado]').isChecked(), 'gear opens settings with "Equilibrado" selected by default');
      await p.click('button:has-text("Probar mi conexión")');
      await p.waitForFunction(() => document.querySelectorAll('#cfgTest .cfg-ok, #cfgTest .cfg-bad').length >= 4, null, { timeout: 15000 });
      const rows = await p.locator('#cfgTest .cfg-ok').count();
      const models = calls.map(c => c.body.model);
      t.ok(rows === 4 && models.join() === 'claude-haiku-5-5,claude-sonnet-5-5,claude-opus-5-5,claude-sonnet-5-5', 'connection test checks fast, writing, deep and web search (' + models.join(', ') + ')');
      t.ok(!!calls[3].body.tools, 'the last probe uses the web search tool');
      await p.fill('#cfgOrgs', '8'); await p.fill('#cfgPlans', '4x5');
      const s1 = await p.evaluate(() => getSettings());
      t.ok(s1.tpmoOrgs === '8' && s1.tpmoPlans === '45', 'TPMO numbers are saved and cleaned to digits');
      await p.check('input[name=cfgPreset][value=economico]');
      const before = calls.length;
      await p.evaluate(() => closeSettings());
      await ask(p, 'x'); await p.waitForTimeout(500);
      const lastMain = mainCalls(calls.slice(before)).pop();
      t.ok(lastMain.body.model === 'claude-haiku-5-5', '"Económico" switches writing to claude-haiku-5-5');
      t.ok((await p.evaluate(() => localStorage.getItem('isabel_chat_model'))) === 'claude-haiku-5-5', 'the tools are told the same model');
      await p.evaluate(() => openSettings());
      t.ok(/llamadas/.test(await p.locator('#cfgUsage').innerText()) && /≈ \$\d/.test(await p.locator('#cfgUsage').innerText()), 'usage and estimated spend are shown');
      await ctx.close();
    }

    // ───────────────────────────── bugs fixed ─────────────────────────────
    t.section('Fixed bugs: Compliance tab, Calendario, Dashboard');
    {
      const { ctx, p } = await t.page(file, { handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : text('1. VEREDICTO: ✅ APROBADO\n2. SCORE: 95') });
      await p.evaluate(() => showModule('compliance', null));
      await p.fill('#complianceText', 'Revisa tu plan con Isabel, agente licenciada.');
      await p.click('#mod-compliance button:has-text("Revisar Compliance")');
      await p.waitForSelector('#complianceResult .compliance-ok', { timeout: 5000 });
      t.ok(true, 'CMS Compliance tab: the review button works again (it was dead since June)');
      const flags = await p.evaluate(() => [cmsRegexIssues('SOA firmado 48 horas antes de la cita').length, cmsRegexIssues('el mejor plan del mercado').length, cmsRegexIssues('Revisa tu plan con calma').length]);
      t.ok(flags[0] === 1 && flags[1] === 1 && flags[2] === 0, 'text checker flags the old 48-hour SOA rule and "el mejor plan", not clean text');
      await ctx.close();
    }
    {
      const { ctx, p } = await t.page(file, { handler: (b) => EXTRACT(b) ? EMPTY_CAPTURE() : text('Lunes: Reel\nMartes: Post') });
      await p.evaluate(() => showModule('calendar', null));
      const label = await p.locator('#calWeekLabel').innerText();
      t.ok(label === 'Semana del 5 de octubre al 11 de octubre', 'week label follows today\'s date (' + label + ')');
      t.ok((await p.locator('#mod-calendar .stat-val').allInnerTexts()).every(v => v === '0'), 'summary shows real counts (all 0 for an empty week), not made-up numbers');
      await p.evaluate(() => { calendarItems['Lunes'].push({ type: 'reel', text: 'Mi reel' }); renderCalendar(); });
      t.ok(await p.locator('#calStatReel').innerText() === '1', 'adding an item updates the count');
      await p.evaluate(() => generateWeekPlan()); await p.waitForTimeout(500);
      t.ok(await p.locator('#calGrid .cal-day').count() === 7 && /Lunes: Reel/.test(await p.locator('#calPlanOut').innerText()), '"Generar Plan con IA" shows the plan in its own box and no longer wipes the calendar');
      await p.evaluate(() => showModule('dashboard', null));
      await ctx.close();
      const c2 = await t.page(file, { handler: () => text('x') });
      await c2.p.evaluate(() => showModule('dashboard', null));
      t.ok(await c2.p.locator('#dashUpcoming .lead-row').count() === 4, 'Dashboard "Próximas publicaciones" fills from the AEP plan when the weekly calendar is empty');
      await c2.ctx.close();
    }

    // ───────────────────────────── standalone tools (interceptor v2) ─────────────────────────────
    t.section('Tools: interceptor v2');
    {
      let n = 0;
      const { ctx, p, calls } = await t.page('tools/isabel-cerebro-system.html', {
        handler: (b) => { n++; if (n === 2) return err(404, 'not_found_error', 'model: x'); if (n === 4) return err(401, 'authentication_error', 'invalid x-api-key'); return json({ content: [{ type: 'thinking', thinking: '', signature: 'S' }, { type: 'text', text: 'hola desde la herramienta' }], stop_reason: 'end_turn', usage: { input_tokens: 5, output_tokens: 5 } }); },
      });
      const r1 = await p.evaluate(() => fetch('https://api.anthropic.com/v1/messages', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ model: 'claude-sonnet-4-20250514', max_tokens: 1000, temperature: 0.7, system: 's', messages: [{ role: 'user', content: 'hola' }] }) }).then(r => r.json()));
      const b1 = calls[0].body;
      t.ok(b1.model === 'claude-sonnet-5-5' && b1.max_tokens === 4096 && b1.output_config.effort === 'medium' && !('temperature' in b1), 'old model ID is replaced; max_tokens raised; effort set; temperature removed');
      t.ok(calls[0].headers['x-api-key'] === TEST_KEY, 'shared key is added');
      t.ok(r1.content.length === 1 && r1.content[0].type === 'text' && r1.content[0].text === 'hola desde la herramienta', 'thinking blocks are removed so content[0].text works');
      const r2 = await p.evaluate(() => fetch('https://api.anthropic.com/v1/messages', { method: 'POST', body: JSON.stringify({ model: 'x', max_tokens: 100, messages: [] }) }).then(r => r.json()));
      t.ok(calls[1].body.model === 'claude-sonnet-5-5' && calls[2].body.model === 'claude-sonnet-5' && r2.content[0].text, 'unavailable model → retries with the next one inside the tool');
      const r3 = await p.evaluate(() => fetch('https://api.anthropic.com/v1/messages', { method: 'POST', body: JSON.stringify({ model: 'x', max_tokens: 100, messages: [] }) }).then(r => r.json()));
      t.ok(/API Key no es válida/.test(r3.error.message), 'tool errors arrive in plain Spanish');
      await ctx.close();
    }
    {
      const { ctx, p } = await t.page(file, { handler: () => text('x') });
      const res = await p.evaluate(() => {
        let n = 0; const orig = window.broadcastApiKey; window.broadcastApiKey = () => { n++; };
        window.dispatchEvent(new MessageEvent('message', { data: { type: 'ISABEL_REQUEST_KEY' }, source: window }));
        const fromStranger = n;
        window.broadcastApiKey = orig; return fromStranger;
      });
      t.ok(res === 0, 'the shell ignores key requests that do not come from one of its own tool frames');
      let asked = 0;
      await p.evaluate(() => { window.__asked = 0; const o = window.broadcastApiKey; window.broadcastApiKey = function (w) { if (w) window.__asked++; return o.apply(this, arguments); }; });
      await p.evaluate(() => { document.getElementById('toolsToggle').click(); });
      await p.evaluate(() => openTool('meta-ads-isabel.html', null, 'x')); await p.waitForTimeout(1200);
      asked = await p.evaluate(() => window.__asked);
      t.ok(asked >= 1, 'a real tool frame still gets the key');
      await ctx.close();
    }
  }
});
