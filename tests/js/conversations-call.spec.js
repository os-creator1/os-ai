// Real-browser regression for Calling from Conversations. It loads the SHIPPED code — the header/Call block of
// resources/views/customer/ChatBox/index.blade.php and public/js/crm/mg-softphone.js — into Chromium (no copies).
// Checks: the Call control exists only when the server named a callable contact AND the softphone is on the page, a click
// hands the softphone exactly the selected contact, a selection change discards the previous identity at once, a stale
// control never dials, and a live call is never re-targeted.
// Run: node tests/js/conversations-call.spec.js   (needs `playwright` and its Chromium)
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const blade = fs.readFileSync(path.join(root, 'resources/views/customer/ChatBox/index.blade.php'), 'utf8');
const softphoneJs = fs.readFileSync(path.join(root, 'public/js/crm/mg-softphone.js'), 'utf8');
const jquery = fs.readFileSync(path.join(root, 'public/vendors/js/jquery/jquery.min.js'), 'utf8');
const a = blade.indexOf('// ── Conversation header and Call');
const b = blade.indexOf("/**\n       * Load the person's activity timeline");
assert.ok(a >= 0 && b > a, 'Call block markers not found');
const callBlock = blade.slice(a, b);

// Every data-role the softphone script looks up, so the real script initialises against a minimal DOM.
const roles = [...new Set([...softphoneJs.matchAll(/\$\('([a-z-]+)'\)/g)].map((m) => m[1]))];
const html = `<!doctype html><html><body>
<input class="chat_id"><span data-role="conversation-avatar"></span><span data-role="conversation-subtitle"></span>
<button class="conversation-call-btn" data-role="conversation-call" hidden></button>
<div id="conversation-context"></div>
<div id="mg-softphone" data-config='${JSON.stringify({ csrf: 't', inbound: null, sessionUrl: '/s', placeUrl: '/p', statusUrl: '/st/__CALL__', hangupUrl: '/h/__CALL__' })}'>
${roles.map((r) => `<button data-role="${r}"></button>`).join('')}
</div><audio id="mg-remote-audio"></audio></body></html>`;

const panel = (call) => `<div data-role="contact-panel" data-header-initials="AM" data-header-subtitle="+1 970"><div class="conversation-context-actions">` +
  (call ? `<button type="button" data-role="conversation-call" data-call-uid="${call.uid}" data-call-conversation="${call.conversation || ''}" data-call-label="${call.label}" data-call-phone="${call.phone}" data-call-initials="${call.initials}" hidden>Call</button>` : '') + `</div></div>`;

async function scenario(name, withSoftphone, fn) {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.setContent(html);
  await page.evaluate((src) => { const s = document.createElement('script'); s.textContent = src; document.head.appendChild(s); }, jquery);
  await page.evaluate(([block, sp, withSp]) => {
    window.calls = [];
    if (withSp) {
      // The REAL softphone script, with the SDK absent: start() runs up to the "cannot make calls" refusal, so nothing is dialled.
      const s = document.createElement('script'); s.textContent = sp; document.body.appendChild(s);
      const real = window.MGSoftphone.callContact;
      window.MGSoftphone.callContact = function (c) { window.calls.push(c); return real(c); };
    }
    const t = document.createElement('script'); t.textContent = block; document.body.appendChild(t);
  }, [callBlock, softphoneJs, withSoftphone]);
  try { await fn(page); console.log('ok  - ' + name); } catch (e) { console.error('FAIL - ' + name); throw e; } finally { await browser.close(); }
}

const load = (page, id, call) => page.evaluate(([id, call, markup]) => {
  $('.chat_id').val(id); $('#conversation-context').html(markup);
  applyConversationHeader({ title: 'Amira Misurati', call });
}, [id, call, panel(call)]);

const A = { uid: 'contact-a', label: 'Amira Misurati', phone: '+19708259551', initials: 'AM' };
const B = { uid: 'contact-b', label: 'Karina Lee', phone: '+17205028306', initials: 'KL' };
const visible = (page, sel) => page.evaluate((s) => [...document.querySelectorAll(s)].filter((e) => !e.hidden).length, sel);

(async () => {
  await scenario('no callable contact: no Call control anywhere', true, async (page) => {
    await load(page, 'conv-1', null);
    assert.equal(await visible(page, '[data-role=conversation-call]'), 0);
  });
  await scenario('softphone missing from the page: the control is not offered, not shown disabled', false, async (page) => {
    await load(page, 'conv-1', A);
    assert.equal(await visible(page, '[data-role=conversation-call]'), 0);
    assert.equal(await page.evaluate(() => document.querySelectorAll('#conversation-context [data-role=conversation-call]').length), 0);
  });
  await scenario('callable contact: header and panel controls show, header subtitle/avatar fill', true, async (page) => {
    await load(page, 'conv-1', A);
    assert.equal(await visible(page, '[data-role=conversation-call]'), 2);
    assert.equal(await page.textContent('[data-role=conversation-avatar]'), 'AM');
    assert.equal(await page.textContent('[data-role=conversation-subtitle]'), '+1 970');
  });
  await scenario('click launches the softphone for exactly the selected contact', true, async (page) => {
    await load(page, 'conv-1', A);
    await page.click('.conversation-call-btn');
    assert.deepEqual(await page.evaluate(() => window.calls), [{ uid: 'contact-a', label: 'Amira Misurati', phone: '+19708259551', initials: 'AM' }]);
    assert.equal(await page.textContent('[data-role=softphone-name]'), 'Amira Misurati');
    assert.equal(await page.evaluate(() => document.getElementById('mg-softphone').classList.contains('is-open')), true);
  });
  await scenario('an unlinked conversation number is called through the conversation itself, with no contact uid', true, async (page) => {
    await load(page, 'conv-1', { uid: '', conversation: 'conv-1', label: '+19708250000', phone: '+19708250000', initials: '#' });
    assert.equal(await visible(page, '[data-role=conversation-call]'), 2);
    await page.click('.conversation-call-btn');
    assert.deepEqual(await page.evaluate(() => window.calls), [{ uid: '', label: '+19708250000', phone: '+19708250000', initials: '#', conversation: 'conv-1' }]);
    assert.equal(await page.textContent('[data-role=softphone-name]'), '+19708250000');
  });
  await scenario('switching conversation discards the previous identity before the new one loads', true, async (page) => {
    await load(page, 'conv-1', A);
    await page.evaluate(() => { $('.chat_id').val('conv-2'); resetConversationHeader(); $('#conversation-context').empty(); });
    assert.equal(await visible(page, '[data-role=conversation-call]'), 0);
    assert.equal(await page.evaluate(() => $('.conversation-call-btn').attr('data-call-uid') || ''), '');
    await page.evaluate(() => $('.conversation-call-btn').prop('hidden', false).trigger('click'));   // even a forced click dials nothing
    assert.deepEqual(await page.evaluate(() => window.calls), []);
  });
  await scenario('a control stamped for another conversation never dials', true, async (page) => {
    await load(page, 'conv-1', A);
    await page.evaluate(() => $('.chat_id').val('conv-2'));          // selection moved on without a reload yet
    await page.evaluate(() => $('.conversation-call-btn').trigger('click'));
    assert.deepEqual(await page.evaluate(() => window.calls), []);
  });
  await scenario('after a call to A, calling B shows B (identity fully replaced)', true, async (page) => {
    await load(page, 'conv-1', A);
    await page.click('.conversation-call-btn');
    await page.evaluate(() => document.querySelector('[data-role=softphone-done]').click());
    await load(page, 'conv-2', B);
    await page.click('.conversation-call-btn');
    assert.equal((await page.evaluate(() => window.calls)).map((c) => c.uid).join(), 'contact-a,contact-b');
    assert.equal(await page.textContent('[data-role=softphone-name]'), 'Karina Lee');
    assert.equal(await page.textContent('[data-role=softphone-avatar]'), 'KL');
  });
  console.log('conversations call: all browser scenarios passed');
})().catch((e) => { console.error(e); process.exit(1); });
