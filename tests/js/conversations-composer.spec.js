// Real-browser regression for the unified Conversations composer (email send idempotency, conversation switching, stale configuration).
// It loads the SHIPPED code out of resources/views/customer/ChatBox/index.blade.php (not a copy) into Chromium: the composer block,
// the SMS/email dispatcher (enter_chat), and the real conversation-selection click handler, driven against a simulated backend.
// Run: node tests/js/conversations-composer.spec.js   (needs `playwright` and its Chromium; BLADE=<path> tests another revision).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const blade = fs.readFileSync(process.env.BLADE || path.join(root, 'resources/views/customer/ChatBox/index.blade.php'), 'utf8');
const between = (from, to) => {
  const a = blade.indexOf(from);
  const b = blade.indexOf(to, a);
  assert.ok(a >= 0 && b > a, `markers not found: ${from}`);
  return blade.slice(a, b);
};
const shipped = [
  between('var composerState', "/**\n       * Load the person's activity timeline"),
  between('function m5UuidV4', 'function enter_chat'),
  between('function enter_chat', '// Conversations failed-send/retry — delegated'),
  between('$("#users-list").on("click"', '// The contact panel slides'),
].join('\n').replace(/\{\{[\s\S]*?\}\}/g, 'x');

const html = `<!doctype html><html><body>
<input class="chat_id"><div class="chat_history"></div><div id="conversation-context"></div><input type="file" id="media_image">
<div id="users-list"><ul class="chat-users-list"><li data-id="A"><span class="notification_count"></span></li><li data-id="B"></li><li data-id="C"></li></ul></div>
<div class="chat-app-form"><div data-role="composer-channel"><button class="conversation-channel-option" data-channel="sms"></button><button class="conversation-channel-option" data-channel="email"></button></div>
<div data-role="composer-email-fields"><input id="email-subject"><select id="email-location"></select></div><p data-role="composer-guidance"></p>
<textarea id="message" class="message"></textarea><button class="send">Send</button></div></body></html>`;

async function scenario(name, fn) {
  if (process.env.ONLY && !name.includes(process.env.ONLY)) { return; }
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const server = { sent: 0, requests: [], seen: new Set(), hold: null, mode: 'accept-then-drop', sms: 0 };
  await page.route('http://conv.test/**', async (route) => {
    const url = route.request().url();
    if (url.endsWith('/')) { return route.fulfill({ contentType: 'text/html', body: html }); }
    if (url.includes('/sms/')) { server.sms += 1; return route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":"success"}' }); }
    const params = new URLSearchParams(route.request().postData() || '');
    const kind = url.includes('/reply') ? 'reply' : 'send';
    const key = kind + ':' + params.get('send_token');   // the sender's idempotency is per endpoint kind + key
    const already = server.seen.has(key);
    server.requests.push({ url, kind, token: params.get('send_token'), contact_uid: params.get('contact_uid') });
    if (!already) { server.seen.add(key); server.sent += 1; }
    if (server.hold) { await server.hold; }
    if (server.mode === 'accept-then-drop' && server.requests.length === 1) { return route.abort('connectionreset'); }
    if (server.mode === 'always-drop') { return route.abort('connectionreset'); }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'success', message: already ? 'Already sent.' : 'Reply sent.' }) });
  });
  await page.goto('http://conv.test/');
  await page.addScriptTag({ path: path.join(root, 'public/vendors/js/vendors.min.js') });
  await page.evaluate((code) => {
    window.isRtl = false; window.toasts = []; window.reloads = []; window.loads = {};
    window.conversationUrl = (n, id) => 'http://conv.test/sms/' + id;
    window.toastr = { warning: (m) => window.toasts.push(['warning', m]), success: (m) => window.toasts.push(['success', m]) };
    // Timeline loads are controlled by the test: each returns a Deferred that the test resolves (applying a config) or rejects.
    window.loadTimeline = (id) => { window.reloads.push(String(id)); const d = $.Deferred(); window.loads[id] = d; return d; };
    const s = document.createElement('script'); s.textContent = code; document.body.appendChild(s);
  }, shipped);
  try { await fn(page, server); console.log('ok  -', name); } finally { await browser.close(); }
}

const cfgFor = (id, extra = {}) => Object.assign({ sms: true, email: true, default: 'email', email_connected: true, url: 'http://conv.test/email/send', contact_uid: null, subject: 'Re: Hi', locations: [] }, extra);
// A conversation is "open and loaded": selected, and its timeline response applied (what loadTimeline's .done does).
const open = (page, id, extra = {}) => page.evaluate(({ id, cfg }) => {
  $('.chat_id').val(id); applyComposer(cfg); $('#message').val('');
}, { id, cfg: cfgFor(id, extra) });
const compose = (page, body) => page.evaluate((b) => { $('#message').val(b); $('#email-subject').val('Re: Hi'); }, body);
const tick = (page, ms = 300) => page.waitForTimeout(ms);
const disabled = (page, sel = '.send') => page.evaluate((q) => $(q).prop('disabled'), sel);
// Click the REAL conversation row (the shipped selection handler runs), without answering its timeline load.
const select = (page, id) => page.evaluate((i) => { $('.chat-users-list li[data-id="' + i + '"]').trigger('click'); }, id);
const answer = (page, id, extra = {}) => page.evaluate(({ id, cfg }) => { if ($('.chat_id').val() === id) { applyComposer(cfg); } window.loads[id].resolve(); }, { id, cfg: cfgFor(id, extra) });

(async () => {
  await scenario('an accepted send whose response is lost is never re-sent automatically and a manual retry reuses the SAME key', async (page, server) => {
    await open(page, 'A'); await compose(page, 'Hello there');
    await page.evaluate(() => sendEmail()); await tick(page, 600);
    assert.equal(server.requests.length, 1, 'no automatic resubmission');
    assert.equal(server.sent, 1);
    assert.equal(await disabled(page), false, 'Send is available again');
    assert.equal(await page.evaluate(() => $('#message').val()), 'Hello there', 'the draft is kept');
    assert.match(await page.evaluate(() => window.toasts.at(-1)[1]), /couldn't confirm/i);
    await page.evaluate(() => sendEmail()); await tick(page, 600);
    assert.equal(server.requests.length, 2);
    assert.equal(server.requests[1].token, server.requests[0].token, 'retry re-sends the original idempotency key');
    assert.equal(server.sent, 1, 'the provider accepted exactly one email');
    assert.equal(await page.evaluate(() => $('#message').val()), '', 'cleared only after the server confirmed');
  });

  await scenario('(1) after an ambiguous /send a timeline refresh that turns the composer into /reply still retries the ORIGINAL /send, key and contact', async (page, server) => {
    await open(page, 'A', { url: 'http://conv.test/email/send', contact_uid: 'contact-1' });
    await compose(page, 'Hello there');
    await page.evaluate(() => sendEmail()); await tick(page, 500);
    assert.equal(server.requests.length, 1);
    assert.equal(server.requests[0].kind, 'send');
    // The refresh after the ambiguous outcome: the message now exists, so the composer's own endpoint becomes a reply to it.
    await page.evaluate((cfg) => { applyComposer(cfg); }, cfgFor('A', { url: 'http://conv.test/email/reply/m1', contact_uid: null }));
    assert.equal(await page.evaluate(() => $('#message').val()), 'Hello there', 'the refresh keeps the draft');
    await page.evaluate(() => { $('#email-subject').val('Re: Hi'); sendEmail(); }); await tick(page, 500);
    assert.equal(server.requests.length, 2);
    assert.equal(server.requests[1].kind, 'send', 'the retry goes to the original endpoint, not the new /reply');
    assert.equal(server.requests[1].token, server.requests[0].token, 'with the original key');
    assert.equal(server.requests[1].contact_uid, 'contact-1', 'and the original Contact');
    assert.equal(server.sent, 1, 'exactly one email was accepted');
  });

  await scenario('edited content is a new intent and gets a new key', async (page, server) => {
    server.mode = 'always-drop';
    await open(page, 'A'); await compose(page, 'First wording');
    await page.evaluate(() => sendEmail()); await tick(page);
    await compose(page, 'Second wording');
    await page.evaluate(() => sendEmail()); await tick(page);
    assert.equal(server.requests.length, 2);
    assert.notEqual(server.requests[0].token, server.requests[1].token);
  });

  await scenario('(2) selecting another conversation disables the composer at once; nothing is sent until ITS timeline applies', async (page, server) => {
    server.mode = 'ok';
    await open(page, 'A'); await compose(page, 'Message typed for A');
    await select(page, 'B');                       // B's timeline has NOT loaded yet
    assert.equal(await disabled(page), true, 'Send disabled immediately');
    assert.equal(await disabled(page, '#message'), true, 'typing disabled immediately');
    await page.evaluate(() => { $('#message').prop('disabled', false).val('typed anyway'); $('#email-subject').val('x'); });
    await page.evaluate(() => { sendEmail(); enter_chat(); });
    await tick(page);
    assert.equal(server.requests.length, 0, 'no email from the stale configuration');
    assert.equal(server.sms, 0, 'no SMS from the stale configuration');
    // After a timeline-load FAILURE the composer stays disabled and still cannot send.
    await page.evaluate(() => { window.loads['B'].reject(); });
    assert.equal(await disabled(page), true);
    await page.evaluate(() => { sendEmail(); enter_chat(); }); await tick(page);
    assert.equal(server.requests.length, 0);
    assert.equal(server.sms, 0);
    // A different selection that loads normally is usable again.
    await select(page, 'C'); await answer(page, 'C');
    assert.equal(await disabled(page), false);
    assert.equal(await page.evaluate(() => composerState.forId), 'C');
  });

  await scenario('(2) a late timeline response for a conversation that is no longer selected never configures the composer', async (page, server) => {
    server.mode = 'ok';
    await open(page, 'A');
    await select(page, 'B'); await select(page, 'C');
    await answer(page, 'B');                       // B answers late: the open conversation is C
    assert.notEqual(await page.evaluate(() => composerState.forId), 'B');
    assert.equal(await disabled(page), true, 'C is still not loaded');
  });

  await scenario('(3) a successful response clears only the submitted draft: a newer draft typed meanwhile survives', async (page, server) => {
    server.mode = 'ok';
    let release; server.hold = new Promise((r) => { release = r; });
    await open(page, 'A'); await compose(page, 'Submitted draft');
    await page.evaluate(() => sendEmail()); await tick(page, 100);
    await page.evaluate(() => $('#message').val('Submitted draft, then more I typed'));
    release(); await tick(page, 400);
    assert.equal(await page.evaluate(() => $('#message').val()), 'Submitted draft, then more I typed');
    assert.equal(await disabled(page), false);
  });

  await scenario('(3) a success after switching channel to SMS leaves the SMS draft alone; an untouched draft is cleared', async (page, server) => {
    server.mode = 'ok';
    let release; server.hold = new Promise((r) => { release = r; });
    await open(page, 'A'); await compose(page, 'Email body');
    await page.evaluate(() => sendEmail()); await tick(page, 100);
    await page.evaluate(() => { setComposerChannel('sms'); $('#message').val('Email body'); });   // same text, but now an SMS draft
    release(); await tick(page, 400);
    assert.equal(await page.evaluate(() => $('#message').val()), 'Email body', 'the SMS draft is not erased');

    server.hold = null;
    await open(page, 'A'); await page.evaluate(() => setComposerChannel('email')); await compose(page, 'Plain second email');
    await page.evaluate(() => sendEmail()); await tick(page, 400);
    assert.equal(await page.evaluate(() => $('#message').val()), '', 'an untouched submitted draft is cleared after confirmation');
  });

  await scenario('switching conversation before a SUCCESSFUL or FAILED response leaves the new draft and Send alone', async (page, server) => {
    for (const mode of ['ok', 'always-drop']) {
      server.mode = mode; server.requests.length = 0;
      let release; server.hold = new Promise((r) => { release = r; });
      await open(page, 'A'); await compose(page, 'Message for A ' + mode);
      await page.evaluate(() => sendEmail()); await tick(page, 100);
      await select(page, 'B'); await answer(page, 'B'); await tick(page, 120);   // the shipped handler wipes the box 50ms after a load
      await page.evaluate(() => $('#message').val('Draft for B'));
      assert.equal(await disabled(page), false, 'B can send while A is in flight');
      release(); await tick(page, 400);
      assert.equal(await page.evaluate(() => $('#message').val()), 'Draft for B', mode);
      assert.equal(await disabled(page), false, mode);
      server.hold = null;
    }
  });

  await scenario('Send availability follows the CURRENT conversation and channel, not an in-flight one', async (page, server) => {
    server.mode = 'ok';
    let release; server.hold = new Promise((r) => { release = r; });
    await open(page, 'A'); await compose(page, 'Message for A');
    await page.evaluate(() => sendEmail()); await tick(page, 100);
    assert.equal(await disabled(page), true, 'A is busy');
    await open(page, 'B', { email: false, default: 'sms', url: null });   // SMS-only conversation
    release(); await tick(page, 400);
    assert.equal(await page.evaluate(() => composerState.channel), 'sms');
    assert.equal(await disabled(page), false, 'SMS stays usable');
    await open(page, 'C', { sms: false, email: false, default: 'email', email_connected: false, url: null });
    assert.equal(await disabled(page), true, 'an unusable channel stays disabled');
  });

  console.log('conversations composer: all browser scenarios passed');
})().catch((e) => { console.error(e); process.exit(1); });
