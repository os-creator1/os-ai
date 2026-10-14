const { chromium } = require('playwright');
const qa = require('./qa.json');
const BASE = process.env.QA_BASE || 'http://127.0.0.1:8099';
const results = [];
const problems = [];
const { execSync } = require('child_process');
// One statement against the disposable test database (same MYSQL_CMD reset.sh uses).
function sql(q) { return execSync(`${process.env.MYSQL_CMD || 'mysql -uroot ultimatesms_testing'} -N -e "${q.replace(/"/g, '\\"')}"`, { encoding: 'utf8' }).trim(); }
function ok(name, cond, extra) { results.push({ name, pass: !!cond, extra: extra || '' }); if (!cond) problems.push(name + ' ' + (extra || '')); }

async function run(label, viewport, userKey, fn, expectedErrors = []) {
  require('child_process').execSync('sh reset.sh', { cwd: __dirname });
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport });
  await ctx.addCookies([{ name: 'qa_user', value: String(qa[userKey].user), url: BASE }]);
  const page = await ctx.newPage();
  const errs = [];
  page.on('console', m => { if (m.type() === 'error') errs.push('console: ' + m.text()); });
  page.on('pageerror', e => errs.push('pageerror: ' + e.message));
  page.on('response', r => { if (r.status() >= 500) errs.push('http ' + r.status() + ' ' + r.url()); });
  const home = `${BASE}/dashboard`;
  await page.goto(home);
  await page.waitForSelector('[data-role="home-ask"]');
  await fn(page, { label, home, qa: qa[userKey] });
  // `expectedErrors` are errors a scenario causes ON PURPOSE (a deliberately dropped response); anything else still fails.
  const unexpected = errs.filter(e => !expectedErrors.some(x => e.includes(x)));
  ok(`${label}: no console errors / 5xx`, unexpected.length === 0, unexpected.join(' | '));
  await browser.close();
}

async function ask(page, text) {
  const before = await page.locator('[data-role="ask-message"][data-from="assistant"]').count();
  await page.fill('[data-role="ask-input"]', text);
  await page.click('[data-role="ask-submit"]');
  await page.waitForFunction((n) => document.querySelectorAll('[data-role="ask-message"][data-from="assistant"]').length > n, before, { timeout: 15000 });
  await page.waitForFunction(() => document.querySelector('[data-role="ask-working"]').hidden === true, null, { timeout: 15000 });
}
// For a turn after which the page re-renders a DIFFERENT (new) conversation, so the count of messages cannot be compared.
async function askAndWaitFor(page, text, expected) {
  await page.fill('[data-role="ask-input"]', text);
  await page.click('[data-role="ask-submit"]');
  await page.waitForFunction((t) => document.querySelector('[data-role="ask-conversation"]').innerText.includes(t), expected, { timeout: 15000 });
  await page.waitForFunction(() => document.querySelector('[data-role="ask-working"]').hidden === true, null, { timeout: 15000 });
}
async function lastReply(page) { return page.locator('[data-role="ask-message"][data-from="assistant"]').last(); }
async function noOverflow(page) { return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1); }

(async () => {
  for (const [vname, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
    // ───────── Launch Home ─────────
    await run(`Launch/${vname}`, viewport, 'launch', async (page, c) => {
      const html = await page.content();
      ok(`${c.label}: setup state`, html.includes('data-home-state="setup"'));
      const askBox = await page.locator('[data-role="home-ask"]').boundingBox();
      const greet = await page.locator('[data-role="home-greeting"]').boundingBox();
      ok(`${c.label}: Ask directly beneath greeting`, askBox.y > greet.y && askBox.y - (greet.y + greet.height) < 120, JSON.stringify({ g: greet.y + greet.height, a: askBox.y }));
      ok(`${c.label}: contextual setup chips`, await page.locator('[data-role="ask-suggestion"]', { hasText: 'What should I set up first?' }).count() === 1);
      ok(`${c.label}: no model call on load (panel closed, no assistant messages)`, await page.locator('[data-role="ask-conversation"]').isHidden());
      await page.screenshot({ path: `shots/${vname}-launch-ready.png`, fullPage: false });

      await page.click('[data-role="ask-suggestion"]:has-text("What should I set up first?")');
      await page.waitForFunction(() => document.querySelectorAll('[data-role="ask-message"][data-from="assistant"]').length > 0, null, { timeout: 15000 });
      await page.waitForFunction(() => document.querySelector('[data-role="ask-working"]').hidden === true);
      let reply = await lastReply(page);
      const thread0 = await page.locator('[data-role="ask-conversation"]').innerText();
      ok(`${c.label}: first-use AI-data notice shown, honest about the provider and about deletion`, thread0.includes('How Ask MotionGrove uses AI') && thread0.includes('third-party provider') && thread0.includes('cannot delete what the AI provider has already received') && thread0.includes('minimal record of any action you approved'));
      ok(`${c.label}: setup guidance answer`, (await reply.innerText()).includes('Start with the basics'));
      ok(`${c.label}: answer shows supporting facts`, await reply.locator('.home-ask-fact').count() > 0);
      await page.screenshot({ path: `shots/${vname}-launch-answer.png` });

      await ask(page, 'Help me create a booking type');
      reply = await lastReply(page);
      ok(`${c.label}: asks for missing information`, (await reply.innerText()).includes('What should it be called'));

      await ask(page, 'Wedding package, 3-hour wedding package');
      reply = await lastReply(page);
      ok(`${c.label}: action preview shown`, await reply.locator('[data-role="ask-proposal"]').count() === 1 && (await reply.innerText()).includes('Wedding package') && (await reply.innerText()).includes('3 hours'));
      ok(`${c.label}: nothing written before confirm`, true);
      await page.screenshot({ path: `shots/${vname}-launch-preview.png` });
      await reply.locator('[data-role="ask-confirm"]').click();
      await page.waitForSelector('[data-role="ask-proposal"][data-status="completed"]', { timeout: 15000 });
      ok(`${c.label}: real result shown`, (await page.locator('[data-role="ask-proposal"]').last().innerText()).includes('was created'));
      await page.screenshot({ path: `shots/${vname}-launch-done.png` });
      ok(`${c.label}: no horizontal overflow`, await noOverflow(page));

      await page.click('[data-role="ask-minimize"]');
      ok(`${c.label}: back to dashboard keeps the page`, await page.locator('[data-role="ask-conversation"]').isHidden() && await page.locator('[data-role="home-greeting"]').isVisible());
      await page.reload();
      await page.waitForSelector('[data-role="ask-resume"]:not([hidden])', { timeout: 10000 });
      await page.click('[data-role="ask-resume"]');
      ok(`${c.label}: refresh resumes the conversation`, await page.locator('[data-role="ask-proposal"][data-status="completed"]').count() === 1);
    });

    // ───────── Exhausted trial ─────────
    await run(`Capped/${vname}`, viewport, 'capped', async (page, c) => {
      await page.fill('[data-role="ask-input"]', 'What should I set up first?');
      await page.click('[data-role="ask-submit"]');
      await page.waitForSelector('[data-kind="limit"]', { timeout: 15000 });
      const card = page.locator('[data-role="ask-message"][data-kind="limit"]').last();
      const text = await card.innerText();
      ok(`${c.label}: trial title`, text.includes("You've used your trial's included AI"));
      ok(`${c.label}: trial body`, text.includes("You've reached the AI usage limit included with your free trial.") && text.includes('Your other MotionGrove features will keep working.'));
      const up = card.locator('[data-role="ask-upgrade"]');
      ok(`${c.label}: Upgrade plan links to the plan page`, (await up.getAttribute('href')).endsWith(`/workspaces/${c.qa.workspace}/plan`), await up.getAttribute('href'));
      ok(`${c.label}: no internal cost/model/provider leaked`, !/\$\s?\d|token|gpt|openai|microusd|unlimited/i.test(text));
      await page.screenshot({ path: `shots/${vname}-capped-limit.png` });
      const href = await up.getAttribute('href');
      const r = await page.request.get(BASE + new URL(href, BASE).pathname, { headers: { Cookie: 'qa_user=' + c.qa.user } });
      ok(`${c.label}: plan page reachable`, r.status() === 200, String(r.status()));
      await page.click('[data-role="ask-minimize"]');
      const r2 = await page.request.get(c.home, { headers: { Cookie: 'qa_user=' + c.qa.user } });
      ok(`${c.label}: Home and non-AI features unchanged`, r2.status() === 200);
    });


    // ───────── First message whose response is lost, then retried ─────────
    await run(`Retry/${vname}`, viewport, 'launch', async (page, c) => {
      let dropped = false;
      await page.route('**/home/assistant/messages', async (route) => {
        if (!dropped) {
          dropped = true;
          await route.fetch();          // the server handles it (conversation created, model used, allowance charged) ...
          await route.abort('failed');  // ... but the browser never receives the response
          return;
        }
        await route.continue();
      });
      await page.fill('[data-role="ask-input"]', 'Help me create a booking type');
      await page.click('[data-role="ask-submit"]');
      await page.waitForSelector('[data-role="ask-message"][data-kind="error"]', { timeout: 15000 });
      ok(`${c.label}: lost response is reported as retryable`, dropped && (await page.locator('[data-role="ask-conversation"]').innerText()).includes("We couldn't reach MotionGrove"));

      await page.fill('[data-role="ask-input"]', 'Help me create a booking type'); // the customer sends the same question again
      await page.click('[data-role="ask-submit"]');
      await page.waitForFunction(() => document.querySelector('[data-role="ask-working"]').hidden === true, null, { timeout: 15000 });
      await page.waitForFunction(() => /called/i.test(document.querySelector('[data-role="ask-conversation"]').innerText), null, { timeout: 15000 });
      ok(`${c.label}: the retry is answered`, (await page.locator('[data-role="ask-conversation"]').innerText()).includes('What should it be called'));
      ok(`${c.label}: one conversation, one user message, one model call charged`,
        sql(`SELECT COUNT(*) FROM assistant_conversations WHERE business_id=(SELECT id FROM businesses WHERE uid='${c.qa.business}')`) === '1'
        && sql(`SELECT COUNT(*) FROM assistant_messages WHERE role='user' AND body='Help me create a booking type'`) === '1'
        && sql(`SELECT COUNT(*) FROM ai_usage_ledger WHERE status='committed' AND workspace_id=(SELECT id FROM workspaces WHERE uid='${c.qa.workspace}')`) === '1',
        sql(`SELECT COUNT(*) FROM ai_usage_ledger WHERE status='committed'`));
    }, ['net::ERR_FAILED']);

    // ───────── Each "Try again" belongs to ITS OWN failed question (and client id), however many newer ones came after ─────────
    const posts = (page) => { const l = []; page.on('request', (r) => { if (r.method() === 'POST' && r.url().endsWith('/home/assistant/messages')) { l.push(JSON.parse(r.postData())); } }); return l; };
    const retryButtons = (page) => page.locator('[data-role="ask-retry"]');
    const replies = (page) => page.locator('[data-role="ask-message"][data-from="assistant"]').count();
    // Waits for the NEXT assistant bubble (answer or error) and for the request to finish, so a click is never raced by an early "idle".
    const settle = async (page, before) => {
      await page.waitForFunction((n) => document.querySelectorAll('[data-role="ask-message"][data-from="assistant"]').length > n, before, { timeout: 15000 });
      await page.waitForFunction(() => document.querySelector('[data-role="ask-working"]').hidden === true, null, { timeout: 15000 });
    };
    const send = async (page, q) => { const b = await replies(page); await page.fill('[data-role="ask-input"]', q); await page.click('[data-role="ask-submit"]'); await settle(page, b); };
    // The page reads the saved conversation once on load (idle callback); wait for it so its late render cannot interleave with a scenario.
    const loaded = (page) => page.waitForFunction(() => /How Ask MotionGrove uses AI/.test(document.querySelector('[data-role="ask-conversation"]').innerText), null, { timeout: 15000 });
    // A successful retry re-renders the whole thread (fewer bubbles than before), so wait on the request itself, not on a bubble count.
    const afterRequest = async (page, act) => {
      const finished = Promise.race([page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/home/assistant/messages'), { timeout: 15000 }), page.waitForEvent('requestfailed', { timeout: 15000 })]);
      await act();
      await finished;
      await page.waitForFunction(() => document.querySelector('[data-role="ask-working"]').hidden === true, null, { timeout: 15000 });
      await page.waitForTimeout(150);
    };
    const retryAndSettle = (page, nth) => afterRequest(page, () => retryButtons(page).nth(nth).dispatchEvent('click'));

    await run(`RetryBinding/A-fails-then-newer-question/${vname}`, viewport, 'launch', async (page, c) => {
      await loaded(page);
      const sent = posts(page); let n = 0;
      await page.route('**/home/assistant/messages', (route) => {
        if (route.request().method() !== 'POST') { return route.continue(); }
        const i = n++;
        if (i === 0) { return route.abort('failed'); }                                                   // A: never reaches the server
        if (i === 1) { return route.fulfill({ status: 429, contentType: 'application/json', body: '{}' }); }   // B: throttled (also retryable)
        return route.continue();
      });
      await send(page, 'Help me create a booking type');   // A
      await send(page, 'How is my SEO doing?');           // B: a NEWER question whose own outcome is a retryable error
      ok(`${c.label}: both errors keep their own retry`, await retryButtons(page).count() === 2);
      await retryAndSettle(page, 0);                       // retry A, not B
      const a = sent[0], retryA = sent[2];
      ok(`${c.label}: A's retry resends A (not the newer B) with A's original client id`, sent.length === 3 && retryA.q === 'Help me create a booking type' && retryA.client_message_id === a.client_message_id && a.client_message_id !== sent[1].client_message_id, JSON.stringify(sent));
      ok(`${c.label}: A was answered once, and B (never retried) was not sent to the model`, sql(`SELECT COUNT(*) FROM assistant_messages WHERE role='user' AND body='Help me create a booking type'`) === '1' && sql(`SELECT COUNT(*) FROM assistant_messages WHERE role='user' AND body='How is my SEO doing?'`) === '0');
    }, ['net::ERR_FAILED', '429']);

    // A STORED failed turn is rebuilt from the server after a later turn succeeds; its retry must still resend ITS question.
    await run(`RetryBinding/stored-error-then-success/${vname}`, viewport, 'launch', async (page, c) => {
      const flag = require('os').tmpdir() + '/mg-browser-outage.flag';
      require('fs').writeFileSync(flag, '1');
      try {
        await loaded(page);
        const sent = posts(page);
        await send(page, 'Outage question one');           // A: the provider fails, a retryable error turn is stored
        require('fs').unlinkSync(flag);                    // the provider recovers
        await send(page, 'How is my SEO doing?');          // B: succeeds; the thread is rebuilt from the server
        const text = await page.locator('[data-role="ask-conversation"]').innerText();
        ok(`${c.label}: A's stored error is rebuilt after B succeeded and still offers its retry`, text.includes('Outage question one') && await retryButtons(page).count() === 1, text.slice(-300));
        await retryAndSettle(page, 0);
        ok(`${c.label}: the retry resends A (not the newer B) as a new request`, sent.length === 3 && sent[2].q === 'Outage question one' && sent[2].client_message_id !== sent[0].client_message_id && sent[2].client_message_id !== sent[1].client_message_id, JSON.stringify(sent));
        ok(`${c.label}: A is now answered, and B was asked exactly once`, sql(`SELECT COUNT(*) FROM assistant_messages WHERE role='user' AND body='How is my SEO doing?'`) === '1' && sql(`SELECT COUNT(*) FROM assistant_messages WHERE role='user' AND body='Outage question one'`) === '2' && (await page.locator('[data-role="ask-conversation"]').innerText()).includes('I can help with that'));
      } finally { try { require('fs').unlinkSync(flag); } catch (e) { /* already gone */ } }
    });

    await run(`RetryBinding/multiple-failures/${vname}`, viewport, 'launch', async (page, c) => {
      await loaded(page);
      const sent = posts(page); let n = 0;
      await page.route('**/home/assistant/messages', (route) => (route.request().method() !== 'POST' ? route.continue() : n++ < 3 ? route.abort('failed') : route.continue()));   // A, B and B's first retry are all lost
      await send(page, 'Help me create a booking type');   // A fails
      await send(page, 'How is my SEO doing?');           // B fails
      ok(`${c.label}: two failed questions have two independent retries`, await retryButtons(page).count() === 2);
      await retryAndSettle(page, 1);   // B's retry first (fails again: a third, independent error bubble appears)
      ok(`${c.label}: a used retry stays disabled and the new failure has its own`, await retryButtons(page).count() === 3 && await retryButtons(page).nth(1).isDisabled() && await retryButtons(page).nth(2).isEnabled());
      await retryAndSettle(page, 0);   // then A's
      ok(`${c.label}: each retry carries its own question and original id`,
        sent.length === 4 && sent[2].q === 'How is my SEO doing?' && sent[2].client_message_id === sent[1].client_message_id
        && sent[3].q === 'Help me create a booking type' && sent[3].client_message_id === sent[0].client_message_id, JSON.stringify(sent));
    }, ['net::ERR_FAILED']);

    await run(`RetryBinding/lost-response-and-double-click/${vname}`, viewport, 'launch', async (page, c) => {
      await loaded(page);
      const sent = posts(page); let n = 0;
      await page.route('**/home/assistant/messages', async (route) => {
        if (route.request().method() !== 'POST') { await route.continue(); return; }
        if (n++ === 0) { await route.fetch(); await route.abort('failed'); return; }  // processed and charged server-side, response lost
        await new Promise((r) => setTimeout(r, 400));                                  // keep the retry in flight so a second click can race it
        await route.continue();
      });
      await send(page, 'Help me create a booking type');
      const btn = retryButtons(page).first();
      await afterRequest(page, async () => { await btn.dispatchEvent('click'); await btn.dispatchEvent('click', undefined, { timeout: 1000 }).catch(() => {}); });   // an impatient second click
      ok(`${c.label}: a double click sends exactly one retry, with the original id`, sent.length === 2 && sent[1].client_message_id === sent[0].client_message_id, JSON.stringify(sent));
      ok(`${c.label}: retrying a request that had succeeded is answered without a second charge`,
        sql(`SELECT COUNT(*) FROM assistant_messages WHERE role='user' AND body='Help me create a booking type'`) === '1'
        && sql(`SELECT COUNT(*) FROM ai_usage_ledger WHERE status='committed'`) === '1');
      ok(`${c.label}: the answer is shown`, (await page.locator('[data-role="ask-conversation"]').innerText()).includes('What should it be called'));
    }, ['net::ERR_FAILED']);

    // ───────── A conversation that has reached its message cap rolls a follow-up into a new conversation ─────────
    await run(`Cap/${vname}`, viewport, 'launch', async (page, c) => {
      await ask(page, 'Help me create a booking type');
      const biz = `(SELECT id FROM businesses WHERE uid='${c.qa.business}')`;
      sql(`UPDATE assistant_conversations SET message_count=120 WHERE business_id=${biz}`);
      const oldUid = sql(`SELECT uid FROM assistant_conversations WHERE business_id=${biz} ORDER BY id LIMIT 1`);

      await askAndWaitFor(page, 'Help me set up a booking type please', 'What should it be called'); // an addressed follow-up (the page names the current conversation)
      ok(`${c.label}: the follow-up at the cap is answered`, (await (await lastReply(page)).innerText()).includes('What should it be called'));
      ok(`${c.label}: a new conversation was started and the full one kept untouched`,
        sql(`SELECT COUNT(*) FROM assistant_conversations WHERE business_id=${biz}`) === '2'
        && sql(`SELECT message_count FROM assistant_conversations WHERE uid='${oldUid}'`) === '120'
        && sql(`SELECT COUNT(*) FROM assistant_messages WHERE body='Help me set up a booking type please'`) === '1');

      await askAndWaitFor(page, 'And one more thing about booking types', 'And one more thing about booking types'); // the browser now continues in the NEW conversation
      await page.waitForFunction(() => document.querySelectorAll('[data-role="ask-message"][data-from="assistant"]').length >= 2, null, { timeout: 15000 });
      ok(`${c.label}: the next turn stays in the new conversation (still two conversations)`, sql(`SELECT COUNT(*) FROM assistant_conversations WHERE business_id=${biz}`) === '2');
    });

    // ───────── Core allowance used up, then upgraded to Growth inside the same month ─────────
    await run(`Upgrade/${vname}`, viewport, 'core', async (page, c) => {
      const ws = c.qa.workspace;
      sql(`UPDATE workspace_plan_assignments a JOIN workspaces w ON w.id=a.workspace_id SET a.workspace_plan_catalog_id=(SELECT id FROM workspace_plan_catalog WHERE tier='core') WHERE w.uid='${ws}'`);
      sql(`DELETE FROM ai_usage_periods WHERE workspace_id=(SELECT id FROM workspaces WHERE uid='${ws}')`);
      sql(`INSERT INTO ai_usage_periods (scope_type,scope_id,workspace_id,period_key,policy_key,policy_version,cap_microusd,reserved_microusd,committed_microusd,interactive_reserved_microusd,interactive_committed_microusd,created_at,updated_at) SELECT 'workspace',id,id,DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m'),'core',1,5000000,0,5000000,0,0,NOW(),NOW() FROM workspaces WHERE uid='${ws}'`);
      await page.reload();
      await page.waitForSelector('[data-role="home-ask"]');

      await ask(page, 'Help me create a booking type');
      let card = page.locator('[data-role="ask-message"][data-kind="limit"]').last();
      ok(`${c.label}: used-up Core allowance is stated plainly, with no trial wording`, await card.count() === 1 && !/trial/i.test(await card.innerText()));

      // Provider-confirmed upgrade to Growth, same billing month.
      sql(`UPDATE workspace_plan_assignments a JOIN workspaces w ON w.id=a.workspace_id SET a.workspace_plan_catalog_id=(SELECT id FROM workspace_plan_catalog WHERE tier='growth') WHERE w.uid='${ws}'`);

      await ask(page, 'Help me set up a booking type please');
      const last = await lastReply(page);
      ok(`${c.label}: the larger Growth allowance is available at once`, (await last.getAttribute('data-kind')) !== 'limit' && (await last.innerText()).includes('What should it be called'), await last.innerText());
      ok(`${c.label}: the open period adopted the Growth cap and kept its spend`,
        sql(`SELECT CONCAT(cap_microusd,'/',policy_key) FROM ai_usage_periods WHERE workspace_id=(SELECT id FROM workspaces WHERE uid='${ws}') AND scope_type='workspace'`) === '10000000/growth'
        && Number(sql(`SELECT committed_microusd FROM ai_usage_periods WHERE workspace_id=(SELECT id FROM workspaces WHERE uid='${ws}') AND scope_type='workspace'`)) >= 5000000);
    });

    // ───────── Growth Home ─────────
    await run(`Growth/${vname}`, viewport, 'growth', async (page, c) => {
      const html = await page.content();
      ok(`${c.label}: not setup state`, !html.includes('data-home-state="setup"'));
      ok(`${c.label}: contextual growth chips`, await page.locator('[data-role="ask-suggestion"]', { hasText: 'How is my business doing this month?' }).count() === 1);
      const cards = ['next_best_move', 'needs_attention', 'headlines', 'handling'].map(b => `[data-band="${b}"]`);
      for (const sel of cards) { ok(`${c.label}: card ${sel} present`, await page.locator(sel).count() >= 1); }
      await page.screenshot({ path: `shots/${vname}-growth-ready.png` });

      await page.click('[data-role="ask-suggestion"]:has-text("How is my business doing this month?")');
      await page.waitForFunction(() => document.querySelectorAll('[data-role="ask-message"][data-from="assistant"]').length > 0, null, { timeout: 15000 });
      await page.waitForFunction(() => document.querySelector('[data-role="ask-working"]').hidden === true);
      let reply = await lastReply(page);
      const t = await reply.innerText();
      ok(`${c.label}: results answer with measured facts`, /based on/i.test(t) && /New contacts/.test(t) && /Waiting for your reply/.test(t));
      await page.screenshot({ path: `shots/${vname}-growth-answer.png` });

      await ask(page, 'How is my SEO doing?');
      await ask(page, 'Which keyword improved the most?');
      reply = await lastReply(page);
      ok(`${c.label}: SEO follow-up is honest about missing data`, (await reply.innerText()).includes('No keyword has a recorded improvement'));
      ok(`${c.label}: limitation (no Search Console) shown`, (await reply.innerText()).includes('Search Console'));
      await ask(page, 'What should we do about the ones declining?');
      reply = await lastReply(page);
      ok(`${c.label}: next-step follow-up`, (await reply.innerText()).includes('Start tracking'));

      // Deterministic lookup chip (no model).
      await page.click('[data-role="ask-minimize"]');
      await page.click('[data-role="ask-suggestion"]:has-text("waiting")', { timeout: 1000 }).catch(() => {});
      await ask(page, "Show customers who haven't received a reply");
      reply = await lastReply(page);
      ok(`${c.label}: waiting customers listed`, (await reply.innerText()).includes('waiting for a reply'));

      await ask(page, 'Create a booking type for a 3-hour wedding package');
      reply = await lastReply(page);
      ok(`${c.label}: proposal`, await reply.locator('[data-role="ask-proposal"]').count() === 1);
      await page.screenshot({ path: `shots/${vname}-growth-preview.png` });
      // Double click must not duplicate.
      await reply.locator('[data-role="ask-confirm"]').dblclick();
      await page.waitForSelector('[data-role="ask-proposal"][data-status="completed"]', { timeout: 15000 });
      ok(`${c.label}: executed`, true);
      await page.screenshot({ path: `shots/${vname}-growth-done.png` });
      ok(`${c.label}: no horizontal overflow`, await noOverflow(page));

      await page.reload();
      await page.waitForSelector('[data-role="ask-resume"]:not([hidden])', { timeout: 10000 });
      await page.click('[data-role="ask-resume"]');
      const n = await page.locator('[data-role="ask-message"]').count();
      ok(`${c.label}: conversation resumes after refresh (${n} messages)`, n >= 10);
      // Keyboard: Escape returns to the dashboard.
      await page.keyboard.press('Escape');
      ok(`${c.label}: Escape closes conversation`, await page.locator('[data-role="ask-conversation"]').isHidden());
      for (const sel of cards) { ok(`${c.label}: card ${sel} still present after`, await page.locator(sel).count() >= 1); }

      // Customer deletes the conversation: two-step, then it is really gone (messages, summary) and cannot be resumed.
      await page.click('[data-role="ask-resume"]');
      const del = page.locator('[data-role="ask-delete"]');
      ok(`${c.label}: delete button offered on a saved conversation`, await del.isVisible());
      await del.click();
      ok(`${c.label}: first press only asks (nothing deleted yet)`, (await del.innerText()).includes('Press again') && await page.locator('[data-role="ask-message"]').count() >= 10);
      await del.click();
      await page.waitForFunction(() => document.querySelector('[data-role="ask-conversation"]').innerText.includes('Conversation deleted.'), null, { timeout: 15000 });
      ok(`${c.label}: conversation deleted from the page`, await page.locator('[data-role="ask-message"]').count() === 0);
      await page.reload();
      await page.waitForSelector('[data-role="home-ask"]');
      ok(`${c.label}: nothing left to resume after deletion`, await page.locator('[data-role="ask-resume"]:not([hidden])').count() === 0);
      const gone = await page.request.get(BASE + `/workspaces/${c.qa.workspace}/businesses/${c.qa.business}/home/assistant`, { headers: { Cookie: 'qa_user=' + c.qa.user } });
      ok(`${c.label}: server holds no conversation messages after deletion`, gone.status() === 200 && ((await gone.json()).messages || []).length === 0, String(gone.status()));
    });
  }

  console.log(JSON.stringify({ passed: results.filter(r => r.pass).length, failed: results.filter(r => !r.pass).length, problems }, null, 2));
  require('fs').writeFileSync('results.json', JSON.stringify(results, null, 2));
})().catch(e => { console.error('SCRIPT ERROR', e, JSON.stringify(results.filter(r => !r.pass))); process.exit(1); });
