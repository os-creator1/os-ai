// Real-browser regression for the unified Conversations composer (email send idempotency + conversation switching).
// It loads the SHIPPED composer code out of resources/views/customer/ChatBox/index.blade.php (not a copy) into Chromium and drives it
// against a simulated backend. Run: node tests/js/conversations-composer.spec.js   (needs `playwright` and its Chromium).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const blade = fs.readFileSync(path.join(root, 'resources/views/customer/ChatBox/index.blade.php'), 'utf8');
const between = (from, to) => {
  const a = blade.indexOf(from);
  const b = blade.indexOf(to, a);
  assert.ok(a >= 0 && b > a, `markers not found: ${from}`);
  return blade.slice(a, b);
};
// The composer block, and the token generator it uses; Blade echoes become inert strings.
const shipped = (between('var composerState', "/**\n       * Load the person's activity timeline") + between('function m5UuidV4', 'function enter_chat'))
  .replace(/\{\{[\s\S]*?\}\}/g, 'x');

const html = `<!doctype html><html><body>
<input class="chat_id"><div class="chat-app-form"><div data-role="composer-channel"><button class="conversation-channel-option" data-channel="sms"></button><button class="conversation-channel-option" data-channel="email"></button></div>
<div data-role="composer-email-fields"><input id="email-subject"><select id="email-location"></select></div><p data-role="composer-guidance"></p>
<textarea id="message"></textarea><button class="send">Send</button></div></body></html>`;

async function scenario(name, fn) {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const server = { sent: 0, tokens: [], hold: null, mode: 'accept-then-drop' };
  await page.route('http://conv.test/**', async (route) => {
    const url = route.request().url();
    if (url.endsWith('/')) { return route.fulfill({ contentType: 'text/html', body: html }); }
    const params = new URLSearchParams(route.request().postData() || '');
    const token = params.get('send_token');
    server.tokens.push(token);
    const already = server.tokens.slice(0, -1).includes(token);
    if (!already) { server.sent += 1; }            // the provider accepts the first time a key is seen, never again
    if (server.hold) { await server.hold; }
    if (server.mode === 'accept-then-drop' && server.tokens.length === 1) { return route.abort('connectionreset'); }
    if (server.mode === 'always-drop') { return route.abort('connectionreset'); }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'success', message: already ? 'Already sent.' : 'Reply sent.' }) });
  });
  await page.goto('http://conv.test/');
  await page.addScriptTag({ path: path.join(root, 'public/vendors/js/vendors.min.js') });
  await page.evaluate((code) => {
    window.isRtl = false; window.toasts = []; window.reloads = [];
    window.toastr = { warning: (m) => window.toasts.push(['warning', m]), success: (m) => window.toasts.push(['success', m]) };
    window.loadTimeline = (id) => { window.reloads.push(String(id)); };
    const s = document.createElement('script'); s.textContent = code; document.body.appendChild(s);
  }, shipped);
  try { await fn(page, server); console.log('ok  -', name); } finally { await browser.close(); }
}

const open = (page, id, extra = {}) => page.evaluate(({ id, extra }) => {
  $('.chat_id').val(id);
  applyComposer(Object.assign({ sms: true, email: true, default: 'email', email_connected: true, url: 'http://conv.test/email/' + id, subject: 'Re: Hi', locations: [] }, extra));
  $('#message').val('');
}, { id, extra });
const compose = (page, body) => page.evaluate((b) => { $('#message').val(b); $('#email-subject').val('Re: Hi'); }, body);
const tick = (page, ms = 300) => page.waitForTimeout(ms);

(async () => {
  await scenario('an accepted send whose response is lost is never re-sent automatically and a manual retry reuses the SAME key', async (page, server) => {
    await open(page, 'A'); await compose(page, 'Hello there');
    await page.evaluate(() => sendEmail()); await tick(page, 600);
    assert.equal(server.tokens.length, 1, 'no automatic resubmission');
    assert.equal(server.sent, 1);
    assert.equal(await page.evaluate(() => $('.send').prop('disabled')), false, 'Send is available again');
    assert.equal(await page.evaluate(() => $('#message').val()), 'Hello there', 'the draft is kept');
    const warning = await page.evaluate(() => window.toasts.at(-1)[1]);
    assert.match(warning, /couldn't confirm/i);
    await page.evaluate(() => sendEmail()); await tick(page, 600);
    assert.equal(server.tokens.length, 2);
    assert.equal(server.tokens[1], server.tokens[0], 'retry re-sends the original idempotency key');
    assert.equal(server.sent, 1, 'the provider accepted exactly one email');
    assert.equal(await page.evaluate(() => $('#message').val()), '', 'cleared only after the server confirmed');
  });

  await scenario('edited content is a new intent and gets a new key; the old key is not reused for it', async (page, server) => {
    server.mode = 'always-drop';
    await open(page, 'A'); await compose(page, 'First wording');
    await page.evaluate(() => sendEmail()); await tick(page);
    await compose(page, 'Second wording');
    await page.evaluate(() => sendEmail()); await tick(page);
    assert.equal(server.tokens.length, 2);
    assert.notEqual(server.tokens[0], server.tokens[1]);
  });

  await scenario('switching conversation before a SUCCESSFUL response leaves the new draft and Send alone', async (page, server) => {
    server.mode = 'ok';
    let release; server.hold = new Promise((r) => { release = r; });
    await open(page, 'A'); await compose(page, 'Message for A');
    await page.evaluate(() => sendEmail()); await tick(page, 100);
    await open(page, 'B'); await page.evaluate(() => $('#message').val('Draft for B'));
    assert.equal(await page.evaluate(() => $('.send').prop('disabled')), false, 'B can send while A is in flight');
    release(); await tick(page, 400);
    assert.equal(await page.evaluate(() => $('#message').val()), 'Draft for B');
    assert.equal(await page.evaluate(() => $('.send').prop('disabled')), false);
    assert.deepEqual(await page.evaluate(() => window.reloads), [], "B's view is not reloaded for A's response");
    assert.equal(await page.evaluate(() => window.toasts.at(-1)[0]), 'success');
  });

  await scenario('switching conversation before a FAILED response leaves the new draft and Send alone', async (page, server) => {
    server.mode = 'always-drop';
    let release; server.hold = new Promise((r) => { release = r; });
    await open(page, 'A'); await compose(page, 'Message for A');
    await page.evaluate(() => sendEmail()); await tick(page, 100);
    await open(page, 'B'); await page.evaluate(() => $('#message').val('Draft for B'));
    release(); await tick(page, 400);
    assert.equal(await page.evaluate(() => $('#message').val()), 'Draft for B');
    assert.equal(await page.evaluate(() => $('.send').prop('disabled')), false);
    assert.deepEqual(await page.evaluate(() => window.reloads), []);
  });

  await scenario('Send availability follows the CURRENT conversation and channel, not an in-flight one', async (page, server) => {
    server.mode = 'ok';
    let release; server.hold = new Promise((r) => { release = r; });
    await open(page, 'A'); await compose(page, 'Message for A');
    await page.evaluate(() => sendEmail()); await tick(page, 100);
    assert.equal(await page.evaluate(() => $('.send').prop('disabled')), true, 'A is busy');
    await open(page, 'B', { email: false, default: 'sms', url: null });   // SMS-only conversation
    release(); await tick(page, 400);
    assert.equal(await page.evaluate(() => composerState.channel), 'sms');
    assert.equal(await page.evaluate(() => $('.send').prop('disabled')), false, 'SMS stays usable');
    await open(page, 'C', { sms: false, email: false, default: 'email', email_connected: false, url: null });
    assert.equal(await page.evaluate(() => $('.send').prop('disabled')), true, 'an unusable channel stays disabled');
  });

  console.log('conversations composer: all browser scenarios passed');
})().catch((e) => { console.error(e); process.exit(1); });
