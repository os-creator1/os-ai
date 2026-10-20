// Browser smoke for browser calling: the REAL softphone.js + softphone-core.js + the layout's real panel CSS,
// driven by a deterministic FAKE calling provider (no Telnyx, no network, no real call). Not part of CI.
//
//   PW_MODULE_DIR=/path/to/node_modules node tests/e2e/voice-softphone.mjs
//
// A tiny local server plays the MotionGrove JSON endpoints. Two "pages" (Contacts, Conversations) share one
// shell so in-app navigation during a call can be exercised through the same swap code the app uses.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

// Standalone: lib.mjs insists on a seeded app fixture, which this fake-provider smoke does not need.
const require = createRequire(process.env.PW_MODULE_DIR ? process.env.PW_MODULE_DIR + '/' : import.meta.url);
const { chromium } = require('playwright-core');
let failures = 0;
const check = (name, ok, detail = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`); if (!ok) { failures++; } };
const finish = () => { console.log(failures === 0 ? '\nALL CHECKS PASSED' : `\n${failures} CHECK(S) FAILED`); process.exit(failures === 0 ? 0 : 1); };

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const partial = fs.readFileSync(path.join(root, 'resources/views/layouts/partials/voice-softphone.blade.php'), 'utf8');
const css = /<style>([\s\S]*?)<\/style>/.exec(partial)[1];
const core = fs.readFileSync(path.join(root, 'public/js/voice/softphone-core.js'), 'utf8');
const phone = fs.readFileSync(path.join(root, 'public/js/voice/softphone.js'), 'utf8');

const PREFIX = '/workspaces/w1/businesses/b1/';
const state = { posts: [], mode: 'ok' };

function shell(title, body, extraScript = '') {
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title}</title><style>body{font-family:system-ui;margin:0}.app-content{padding:1rem}${css}</style></head>
<body class="vertical-layout"><nav><a id="to-conv" href="${PREFIX}conversations">Conversations</a> <a id="to-contact" href="${PREFIX}people/c1">Contact</a> <a id="away" href="/workspaces/w2/businesses/b9/people">Other workspace</a></nav>
<div class="app-content content"><main class="content-body" id="main-content">${body}</main></div>
<script src="/core.js"></script><script src="/phone.js"></script>
<script type="application/x-mg-region" data-mg-region="page-scripts:start"></script>${extraScript}<script type="application/x-mg-region" data-mg-region="page-scripts:end"></script>
<script>
window.__fake = { clients: [], calls: [], micDenied: false, rec: [] };
function mkCall(o) { const c = Object.assign({ state: 'new', direction: 'outbound', remoteNumber: null, cause: null, log: [],
  answer() { c.log.push('answer'); }, hangup() { c.log.push('hangup'); }, muteAudio() { c.log.push('mute'); }, unmuteAudio() { c.log.push('unmute'); },
  hold() { c.log.push('hold'); return Promise.resolve(); }, unhold() { c.log.push('unhold'); return Promise.resolve(); } }, o); window.__fake.calls.push(c); return c; }
MGSoftphone.boot({ workspaceUid: 'w1', businessUid: 'b1', businessPrefix: '${PREFIX}', csrf: 't', sdkUrl: '',
  urls: { session: '/voice/session', options: '/voice/options', lookup: '/voice/lookup', token: '/voice/token', heartbeat: '/voice/heartbeat', offline: '/voice/offline', calls: '/voice/calls', callBase: '/voice/calls' } }, {
  supported: true, isSecure: true,
  checkMicrophone: () => Promise.resolve(window.__fake.micDenied ? { ok: false, code: 'denied', message: 'Microphone access is blocked. Allow the microphone for this site in your browser\\'s address bar, then try again.' } : { ok: true }),
  createClient: (token, h) => { const cl = { token, h, connected: false, disconnected: false, connect() { cl.connected = true; setTimeout(() => h.ready(), 5); }, disconnect() { cl.disconnected = true; }, newCall: (o) => { cl.lastOpts = o; return (cl.last = mkCall({})); } }; window.__fake.clients.push(cl); return cl; } });
</script></body></html>`;
}

const pages = {
  [PREFIX + 'people/c1']: shell('Contact', '<h1>Maya Lopez</h1><div class="mg-cp-actions"><button type="button" id="call-contact" data-mg-call data-mg-show-reason data-contact-uid="c1">Call</button></div>'),
  [PREFIX + 'conversations']: shell('Conversations', '<h1>Inbox</h1><input type="hidden" class="chat_id" value="conv-1"><div class="chat-header"><button type="button" id="call-conv" data-mg-call data-conversation-input=".chat_id">Call</button></div>'),
};

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  const json = (o, code = 200) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
  if (url.pathname === '/core.js') { res.writeHead(200, { 'Content-Type': 'text/javascript' }); return res.end(core); }
  if (url.pathname === '/phone.js') { res.writeHead(200, { 'Content-Type': 'text/javascript' }); return res.end(phone); }
  if (pages[url.pathname]) { res.writeHead(200, { 'Content-Type': 'text/html' }); return res.end(pages[url.pathname]); }
  if (url.pathname === '/voice/options') {
    if (state.mode === 'nonumber') { return json({ status: 'success', callable: false, reason: 'no_voice_number', message: 'No number is set up for calling yet. An admin can enable voice for a number in Voice settings.', numbers: [], target: { name: 'Maya Lopez', number: '+12025550111' }, settings_url: '/settings' }); }
    return json({ status: 'success', callable: true, numbers: [{ uid: 'n1', number: '+14155550199', source: 'managed', scope: 'managed' }, { uid: 'n2', number: '+14155550100', source: 'managed', scope: 'managed' }], target: { name: 'Maya Lopez', number: '+12025550111' } });
  }
  if (url.pathname === '/voice/session') { return json({ status: 'success', inbound_number_uid: 'n1', inbound_number: '+14155550199', inbound_scope: 'managed' }); }
  if (url.pathname === '/voice/lookup') { return json({ status: 'success', name: url.searchParams.get('number') === '+12025550111' ? 'Maya Lopez' : null, number: url.searchParams.get('number'), call_uid: 'in-call-1' }); }
  let body = ''; req.on('data', (c) => { body += c; });
  req.on('end', () => {
    let parsed = null; try { parsed = body ? JSON.parse(body) : null; } catch (e) { /* the offline beacon is multipart */ }
    state.posts.push([url.pathname, parsed]);
    if (url.pathname === '/voice/token') { return json({ status: 'success', token: 'a.b.c', expires_at: null }); }
    if (url.pathname === '/voice/calls') { return json({ status: 'success', duplicate: false, call: { uid: 'call-1', from: '+14155550199', to: '+12025550111', name: 'Maya Lopez', client_state: 'Y2FsbC0x' } }); }
    return json({ status: 'success', registered: true });
  });
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const BASE = `http://127.0.0.1:${server.address().port}`;

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
const shots = process.env.E2E_SHOTS || '/tmp';

async function run(label, viewport) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  const text = async (sel) => ((await page.locator(sel).textContent()) || '').trim(); // textContent: innerText would apply the badge's CSS uppercase
  const fake = (fn, arg) => page.evaluate(fn, arg);
  state.posts.length = 0; state.mode = 'ok';

  await page.goto(BASE + PREFIX + 'people/c1');
  await page.waitForFunction(() => window.__fake.clients.length === 1);
  check(`${label}: inbound presence registers exactly one client for the assigned number`, await fake(() => window.__fake.clients.length) === 1);
  check(`${label}: Call button is enabled for a callable contact`, !(await page.locator('#call-contact').isDisabled()));

  // 1. open the panel, confirm, call
  await page.click('#call-contact');
  check(`${label}: panel opens with the contact, number and a caller-ID choice`, (await text('[data-el=name]')) === 'Maya Lopez' && (await text('[data-el=number]')) === '+12025550111' && (await page.locator('[data-el=select] option').count()) === 2);
  check(`${label}: nothing is dialed before the user confirms`, await fake(() => window.__fake.calls.length) === 0);
  await page.selectOption('[data-el=select]', 'n2');
  await page.click('[data-role=voice-start]');
  await page.waitForFunction(() => window.__fake.calls.length === 1);
  const opts = await fake(() => window.__fake.clients[0].lastOpts);
  check(`${label}: dial uses the validated destination, caller ID and the call's own clientState`, opts.destinationNumber === '+12025550111' && opts.callerNumber === '+14155550199' && opts.clientState === 'Y2FsbC0x', JSON.stringify(opts));
  check(`${label}: shows Connecting…`, (await text('[data-el=badge]')) === 'Connecting…');
  await page.screenshot({ path: `${shots}/voice-${label}-1-dialing.png` });

  // double click safety: the confirm button is gone, so a second call cannot be started
  check(`${label}: only one /calls request was made`, state.posts.filter(([u]) => u === '/voice/calls').length === 1);

  await fake(() => { const c = window.__fake.clients[0].last; c.state = 'ringing'; window.__fake.clients[0].h.callUpdate(c); });
  check(`${label}: ringing state`, (await text('[data-el=badge]')) === 'Ringing…');
  await fake(() => { const c = window.__fake.clients[0].last; c.state = 'active'; window.__fake.clients[0].h.callUpdate(c); });
  check(`${label}: active state`, (await text('[data-el=badge]')) === 'In call');
  await page.waitForTimeout(2200);
  const timer = await text('[data-el=timer]');
  check(`${label}: timer advances (${timer})`, /^00:0[2-4]$/.test(timer));

  await page.click('[data-role=voice-mute]');
  check(`${label}: mute toggles the SDK call and the control`, (await page.getAttribute('[data-role=voice-mute]', 'aria-pressed')) === 'true' && (await fake(() => window.__fake.clients[0].last.log.includes('mute'))));
  await page.click('[data-role=voice-hold]');
  await fake(() => { const c = window.__fake.clients[0].last; c.state = 'held'; window.__fake.clients[0].h.callUpdate(c); });
  check(`${label}: hold shows On hold and offers Resume`, (await text('[data-el=badge]')) === 'On hold' && (await text('[data-role=voice-hold]')) === 'Resume');
  await fake(() => { const c = window.__fake.clients[0].last; c.state = 'active'; window.__fake.clients[0].h.callUpdate(c); });

  // 2. navigate in-app during the call: panel and call survive, nothing reinitialised
  const audioBefore = await page.evaluate(() => { window.__audio = document.getElementById('mg-voice-audio'); window.__marker = 'same-document'; return true; });
  await page.click('#to-conv');
  await page.waitForFunction(() => document.title === 'Conversations');
  check(`${label}: in-app navigation swapped the page without a reload`, await fake(() => window.__marker === 'same-document'));
  check(`${label}: the call panel is still visible and still In call`, (await page.locator('#mg-voice-root').isVisible()) && (await text('[data-el=badge]')) === 'In call');
  check(`${label}: same audio element, no new SDK client, no hangup`, await fake(() => document.getElementById('mg-voice-audio') === window.__audio && window.__fake.clients.length === 1 && !window.__fake.clients[0].last.log.includes('hangup') && !window.__fake.clients[0].disconnected));
  check(`${label}: Conversations has its Call button (disabled while a call is open is not required, still present)`, await page.locator('#call-conv').count() === 1);
  await page.screenshot({ path: `${shots}/voice-${label}-2-active-conversations.png` });

  // 3. connection drop mid call: honest notice, call kept
  await fake(() => window.__fake.clients[0].h.socketClose());
  check(`${label}: connection drop is shown and the call is not torn down`, /Connection lost/.test(await text('[data-el=msg]')) && (await text('[data-el=badge]')) === 'In call');
  await fake(() => { const c = window.__fake.clients[0].last; c.state = 'active'; window.__fake.clients[0].h.callUpdate(c); });

  // 4. leaving the workspace asks first
  let dialog = null; page.once('dialog', async (d) => { dialog = d.message(); await d.dismiss(); });
  await page.click('#away');
  check(`${label}: leaving the workspace mid-call asks for confirmation and stays put`, dialog !== null && /end it/.test(dialog) && (await text('[data-el=badge]')) === 'In call');

  // 5. end call, note
  await page.click('[data-role=voice-end]');
  await fake(() => { const c = window.__fake.clients[0].last; c.state = 'hangup'; window.__fake.clients[0].h.callUpdate(c); });
  check(`${label}: ended state with a note box`, (await text('[data-el=badge]')) === 'Call ended' && await page.locator('[data-el=note]').isVisible());
  await page.fill('[data-el=note]', 'Wants Saturday');
  await page.click('[data-el=saveNote]');
  await page.waitForFunction(() => document.querySelector('[data-el=saveNote]').textContent === 'Note saved');
  check(`${label}: note posted to the call record`, state.posts.some(([u, b]) => u === '/voice/calls/call-1/note' && b.note === 'Wants Saturday'));
  check(`${label}: browser reported the end as a hint`, state.posts.some(([u, b]) => u === '/voice/calls/call-1/client-event' && b.event === 'ended'));
  await page.screenshot({ path: `${shots}/voice-${label}-3-ended.png` });
  await page.click('[data-role=voice-close]');
  check(`${label}: panel closes`, !(await page.locator('.mg-voice-card').isVisible()));

  // 6. incoming: decline, then answer; never auto-answered
  await fake(() => { window.__in = window.__fake.clients[0].h; window.__inc = { state: 'ringing', direction: 'inbound', remoteNumber: '+12025550111', log: [], answer() { this.log.push('answer'); }, hangup() { this.log.push('hangup'); } }; window.__in.callUpdate(window.__inc); });
  await page.waitForSelector('[data-role=voice-answer]');
  check(`${label}: incoming panel shows the known caller by name with Answer and Decline`, (await text('[data-el=name]')) === 'Maya Lopez' && (await text('[data-el=badge]')) === 'Incoming call' && await page.locator('[data-role=voice-decline]').isVisible());
  check(`${label}: not auto-answered`, await fake(() => window.__inc.log.length === 0));
  await page.screenshot({ path: `${shots}/voice-${label}-4-incoming.png` });
  await page.click('[data-role=voice-decline]');
  await fake(() => { window.__inc.state = 'hangup'; window.__in.callUpdate(window.__inc); });
  check(`${label}: decline hangs up and reads as declined`, (await fake(() => window.__inc.log.join())) === 'hangup' && /declined/i.test(await text('[data-el=msg]')));
  await page.click('[data-role=voice-close]');

  await fake(() => { window.__inc = { state: 'ringing', direction: 'inbound', remoteNumber: '+12025558888', log: [], answer() { this.log.push('answer'); }, hangup() { this.log.push('hangup'); } }; window.__in.callUpdate(window.__inc); });
  await page.waitForSelector('[data-role=voice-answer]');
  check(`${label}: an unknown caller shows the number only, not an invented name`, (await text('[data-el=name]')) === '+12025558888' || (await text('[data-el=name]')) === '+12025558888');
  await page.click('[data-role=voice-answer]');
  await fake(() => { window.__inc.state = 'active'; window.__in.callUpdate(window.__inc); });
  check(`${label}: answering is explicit and goes active`, (await fake(() => window.__inc.log.join())) === 'answer' && (await text('[data-el=badge]')) === 'In call');
  await page.click('[data-role=voice-end]');
  await fake(() => { window.__inc.state = 'hangup'; window.__in.callUpdate(window.__inc); });
  await page.click('[data-role=voice-close]');

  // 7. microphone denied
  await page.goto(BASE + PREFIX + 'people/c1');
  await page.waitForFunction(() => window.__fake.clients.length === 1);
  await fake(() => { window.__fake.micDenied = true; });
  await page.click('#call-contact');
  await page.click('[data-role=voice-start]');
  await page.waitForFunction(() => /Microphone access is blocked/.test(document.querySelector('[data-el=msg]').textContent));
  check(`${label}: denied microphone shows how to fix it and dials nothing`, await fake(() => window.__fake.calls.length === 0) && (await text('[data-el=badge]')) === 'Call ended');
  await page.screenshot({ path: `${shots}/voice-${label}-5-mic-denied.png` });
  await page.click('[data-role=voice-close]');

  // 8. no voice number: the button is disabled with the exact reason
  state.mode = 'nonumber';
  await page.goto(BASE + PREFIX + 'people/c1');
  await page.waitForSelector('#call-contact[disabled]');
  check(`${label}: with no voice setup the Call button is disabled and says why`, /No number is set up for calling yet/.test((await page.getAttribute('#call-contact', 'title')) || '') && /Set up calling/.test(await page.locator('.mg-voice-reason').textContent()));

  // layout
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  check(`${label}: no horizontal page scroll`, !overflow);
  state.mode = 'ok';
  await page.goto(BASE + PREFIX + 'people/c1');
  await page.waitForFunction(() => window.__fake.clients.length === 1);
  await page.click('#call-contact');
  const box = await page.locator('.mg-voice-card').boundingBox();
  check(`${label}: panel sits fully inside the viewport (${Math.round(box.x)},${Math.round(box.y)} ${Math.round(box.width)}x${Math.round(box.height)})`, box.x >= 0 && box.y >= 0 && box.x + box.width <= viewport.width + 1 && box.y + box.height <= viewport.height + 1);
  await page.screenshot({ path: `${shots}/voice-${label}-6-confirm.png` });

  check(`${label}: no unexpected JavaScript console errors`, errors.length === 0, errors.join(' | '));
  await ctx.close();
}

await run('desktop', { width: 1280, height: 800 });
await run('mobile', { width: 390, height: 844 });
await browser.close();
server.close();
finish();
