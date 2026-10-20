// Browser verification of browser calling in the REAL, authenticated MotionGrove layout, with a deterministic
// FAKE calling provider on both sides: the Telnyx WebRTC SDK is replaced in the browser by a scripted fake
// (so the real softphone.js adapter code runs), the server runs with TELNYX_VOICE_DRIVER=fake, and the Telnyx
// webhooks are signed by this script with a test-only key. No Telnyx account, no network, no real call.
//
//   1. migrate:fresh a DISPOSABLE database (TestDatabaseSafety-approved name) and seed it:
//        VOICE_E2E_SEED=1 VOICE_E2E_OUT=/tmp/voice-fixture.json vendor/bin/phpunit tests/Feature/Voice/VoiceBrowserFixtureTest.php
//   2. node -e "<generate an ed25519 keypair>" -> /tmp/voice-keys.json  {"pub": "<raw base64>", "priv": "<pkcs8 pem>"}
//   3. serve the app (APP_ENV=local, TELNYX_VOICE_ENABLED=true TELNYX_VOICE_DRIVER=fake TELNYX_VOICE_CONNECTION_ID=e2e-conn
//      TELNYX_VOICE_CALL_CONTROL_APP_ID=e2e-app TELNYX_API_KEY=x TELNYX_WEBHOOK_PUBLIC_KEY=<pub>):
//        php -S 127.0.0.1:8011 -t public server.php
//   4. PW_MODULE_DIR=... VOICE_E2E_OUT=... VOICE_E2E_KEYS=... VOICE_E2E_DB=ultimatesms_testing node tests/e2e/voice-real-layout.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { execSync } from 'node:child_process';

const require = createRequire(process.env.PW_MODULE_DIR ? process.env.PW_MODULE_DIR + '/' : import.meta.url);
const { chromium } = require('playwright-core');
const BASE = process.env.E2E_BASE_URL || 'http://127.0.0.1:8011';
const f = JSON.parse(fs.readFileSync(process.env.VOICE_E2E_OUT, 'utf8'));
const keys = JSON.parse(fs.readFileSync(process.env.VOICE_E2E_KEYS, 'utf8'));
const DB = process.env.VOICE_E2E_DB || 'ultimatesms_testing';
const shots = process.env.E2E_SHOTS || '/tmp';
let failures = 0;
const check = (name, ok, detail = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`); if (!ok) { failures++; } };

const sql = (q) => execSync(`mysql -uroot ${DB} -N -B -e ${JSON.stringify(q)}`).toString().trim();
const lastCall = () => { const r = sql('select uid,status,provider_call_control_id,provider_call_session_id,to_number,from_number,direction,duration_seconds,coalesce(note,"") from voice_calls order by id desc limit 1').split('\t'); return { uid: r[0], status: r[1], cc: r[2], session: r[3], to: r[4], from: r[5], direction: r[6], duration: Number(r[7]), note: r[8] }; };

let evt = 0;
async function webhook(type, payload, when = new Date()) {
  const body = JSON.stringify({ data: { id: `e2e-${Date.now()}-${++evt}`, event_type: type, occurred_at: when.toISOString(), payload } });
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = crypto.sign(null, Buffer.from(`${ts}|${body}`), keys.priv).toString('base64');
  const res = await fetch(`${BASE}/webhooks/telnyx-voice`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'telnyx-signature-ed25519': sig, 'telnyx-timestamp': ts }, body });
  return res.status;
}

const FAKE_SDK = `
window.__rtc = { clients: [], calls: [] };
function mkCall(client, number) {
  const c = { id: 'fake-' + Math.random().toString(16).slice(2), state: 'ringing', direction: 'inbound', options: { remoteCallerNumber: number }, log: [],
    answer() { c.log.push('answer'); c.set('active'); }, hangup() { c.log.push('hangup'); c.set('hangup'); },
    muteAudio() { c.log.push('mute'); }, unmuteAudio() { c.log.push('unmute'); },
    hold() { c.log.push('hold'); c.set('held'); return Promise.resolve(); }, unhold() { c.log.push('unhold'); c.set('active'); return Promise.resolve(); },
    set(s) { c.state = s; client.emit('telnyx.notification', { type: 'callUpdate', call: c }); } };
  window.__rtc.calls.push(c); return c;
}
window.TelnyxWebRTC = { TelnyxRTC: class {
  constructor(o) { this.o = o; this.h = {}; this.remoteElement = null; window.__rtc.clients.push(this); }
  on(e, fn) { (this.h[e] = this.h[e] || []).push(fn); return this; }
  off(e) { delete this.h[e]; }
  emit(e, p) { (this.h[e] || []).forEach((fn) => fn(p)); }
  connect() { setTimeout(() => this.emit('telnyx.ready'), 5); }
  disconnect() { this.disconnected = true; }
  newCall() { window.__directDial = true; throw new Error('direct browser dialing must never be used'); }
  ring(number) { const c = mkCall(this, number); this.emit('telnyx.notification', { type: 'callUpdate', call: c }); return c; }
  drop() { this.emit('telnyx.socket.close'); }
} };`;

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });

const u = (p) => `${BASE}/workspaces/${f.workspace}/businesses/${f.business}/${p}`;

async function newSession(label, viewport) {
  const ctx = await browser.newContext({ viewport });
  await ctx.grantPermissions(['microphone'], { origin: BASE });
  await ctx.route(/\/vendor\/telnyx\/telnyx-webrtc-.*\.js/, (r) => r.fulfill({ contentType: 'text/javascript', body: FAKE_SDK }));
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', f.email);
  await page.fill('input[name="password"]', f.password);
  await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);
  return { ctx, page, errors, label };
}

const text = async (page, sel) => ((await page.locator(sel).textContent()) || '').trim();
const ev = (page, fn, arg) => page.evaluate(fn, arg);

async function startCallFromContact(s) {
  const { page, label } = s;
  await page.goto(u(`people/${f.contact}`));
  await page.waitForFunction(() => window.__rtc && window.__rtc.clients.length === 1, null, { timeout: 15000 });
  await page.waitForSelector('[data-role=contact-call]:not([disabled])', { timeout: 15000 });
  await page.click('[data-role=contact-call]');
  await page.waitForSelector('[data-role=voice-start]');
  const countBefore = Number(sql('select count(*) from voice_calls'));
  await page.click('[data-role=voice-start]');
  // The browser registers, THEN asks the server to place the call: wait for the server's record (with our leg's ids).
  let call;
  for (let i = 0; i < 80; i++) {
    if (Number(sql('select count(*) from voice_calls')) > countBefore) { call = lastCall(); if (call.cc !== 'NULL') { break; } }
    await page.waitForTimeout(250);
  }
  if (!call || call.cc === 'NULL') { console.log('DEBUG panel:', await text(page, '[data-el=badge]'), '|', await text(page, '[data-el=msg]'), JSON.stringify(call)); throw new Error('the server never placed the call'); }
  // The "server" rang our browser: the scripted SDK delivers the leg that carries the dialled number.
  await ev(page, (n) => window.__rtc.clients[0].ring(n), call.to);
  try {
    await page.waitForFunction(() => /Ringing/i.test(document.querySelector('[data-el=badge]').textContent), null, { timeout: 10000 });
  } catch (e) {
    console.log('DEBUG panel:', await text(page, '[data-el=badge]'), '|', await text(page, '[data-el=msg]'), '| call:', JSON.stringify(call));
    throw e;
  }
  return call;
}

async function customerAnswers(call) {
  const legA = { call_session_id: call.session, call_control_id: call.cc, direction: 'outgoing', from: call.to, to: 'sip:gencred@sip.telnyx.com' };
  const a = await webhook('call.answered', legA);
  const b = await webhook('call.answered', { call_session_id: call.session, call_control_id: 'pstn-' + call.uid, direction: 'outgoing', from: call.from, to: call.to });
  return [a, b];
}

// The real sidebar link; on a phone the menu is collapsed behind the hamburger, so open it like a person would.
async function clickMenu(page, suffix) {
  const link = page.locator(`.main-menu a[href$="${suffix}"]`).first();
  const box = await link.boundingBox();
  const vw = page.viewportSize().width;
  if (!box || box.x < 0 || box.x + box.width > vw) { await page.locator('a.menu-toggle').first().click(); await page.waitForTimeout(700); }
  try { await link.click({ timeout: 8000 }); }
  catch (e) {
    await page.screenshot({ path: `${shots}/voice-real-menu-debug.png` });
    console.log('NOTE: sidebar link not clickable by pointer (' + String(e.message).split('\n')[0] + '); dispatching a real click event on it instead');
    await link.evaluate((el) => el.click());
  }
}

async function run(label, viewport) {
  const s = await newSession(label, viewport);
  const { page, errors } = s;
  const results = [];

  // 1-2. real Contact page, real Call button, simulated call
  await page.goto(u(`people/${f.contact}`));
  check(`${label}: the real Contact page shows an enabled Call action`, await page.locator('[data-role=contact-call]').count() === 1);
  const call = await startCallFromContact(s);
  check(`${label}: MotionGrove placed the call itself (a server-side call record exists, status initiating, our leg's ids stored)`, call.status === 'initiating' && !!call.cc && call.direction === 'outbound' && call.to === '+12025550111', JSON.stringify(call));
  check(`${label}: the browser never dialled directly`, !(await ev(page, () => window.__directDial === true)));
  const [wa, wb] = await customerAnswers(call);
  check(`${label}: signed webhooks accepted (our leg answered → bridge; customer answered)`, wa === 200 && wb === 200, `${wa}/${wb}`);
  await page.waitForFunction(() => /In call/i.test(document.querySelector('[data-el=badge]').textContent), null, { timeout: 10000 });
  check(`${label}: panel shows In call only after the server confirmed the customer answered; status = answered`, lastCall().status === 'answered');
  await page.screenshot({ path: `${shots}/voice-real-${label}-1-incall-contact.png` });

  // 5 baseline for persistence checks
  await ev(page, () => { window.__marker = 'same-document'; window.__audio = document.getElementById('mg-voice-audio'); window.__clientCount = window.__rtc.clients.length; });
  const persists = async (where) => {
    const ok = await page.locator('#mg-voice-root').isVisible() && /In call|On hold/i.test(await text(page, '[data-el=badge]'));
    const same = await ev(page, () => window.__marker === 'same-document' && document.getElementById('mg-voice-audio') === window.__audio && window.__rtc.clients.length === window.__clientCount && !window.__rtc.clients[0].disconnected && !window.__rtc.calls[window.__rtc.calls.length - 1].log.includes('hangup'));
    check(`${label}: after ${where}: panel visible, call state kept, same SDK client + audio element, no hangup, no reload`, ok && same);
  };

  // 3. real navigation to Conversations (sidebar), then 4. another module (Calendar)
  await clickMenu(page, '/conversations');
  await page.waitForFunction(() => /Conversations|Inbox/i.test(document.title) || location.pathname.endsWith('/conversations'), null, { timeout: 20000 });
  await page.waitForTimeout(800);
  check(`${label}: real navigation reached Conversations`, page.url().endsWith('/conversations'));
  await persists('navigating to Conversations');
  check(`${label}: Conversations has the same Call action`, await page.locator('[data-role=conversation-call]').count() === 1);
  await page.screenshot({ path: `${shots}/voice-real-${label}-2-incall-conversations.png` });

  await clickMenu(page, '/calendar');
  await page.waitForFunction(() => location.pathname.endsWith('/calendar'), null, { timeout: 20000 });
  await page.waitForTimeout(800);
  await persists('navigating to Calendar');
  await page.screenshot({ path: `${shots}/voice-real-${label}-3-incall-calendar.png` });

  // 6. mute / unmute / hold / resume / end — on a different module page than where the call began
  await page.click('[data-role=voice-mute]');
  check(`${label}: mute`, (await page.getAttribute('[data-role=voice-mute]', 'aria-pressed')) === 'true' && await ev(page, () => window.__rtc.calls.at(-1).log.includes('mute')));
  await page.click('[data-role=voice-mute]');
  check(`${label}: unmute`, (await page.getAttribute('[data-role=voice-mute]', 'aria-pressed')) === 'false' && await ev(page, () => window.__rtc.calls.at(-1).log.includes('unmute')));
  await page.click('[data-role=voice-hold]');
  await page.waitForFunction(() => /On hold/i.test(document.querySelector('[data-el=badge]').textContent));
  check(`${label}: hold`, (await text(page, '[data-role=voice-hold]')) === 'Resume');
  await page.click('[data-role=voice-hold]');
  await page.waitForFunction(() => /In call/i.test(document.querySelector('[data-el=badge]').textContent));
  check(`${label}: resume`, true);

  await page.click('[data-role=voice-end]');
  await page.waitForFunction(() => /Call ended/i.test(document.querySelector('[data-el=badge]').textContent));
  await webhook('call.hangup', { call_session_id: call.session, call_control_id: 'pstn-' + call.uid, direction: 'outgoing', from: call.from, to: call.to, hangup_cause: 'normal_clearing', hangup_source: 'caller', end_time: new Date().toISOString() });
  await page.fill('[data-el=note]', 'Wants Saturday at 10');
  await page.click('[data-el=saveNote]');
  await page.waitForFunction(() => document.querySelector('[data-el=saveNote]').textContent === 'Note saved');
  const done = lastCall();
  check(`${label}: ended; server record is completed with a real duration and the note (${done.status}, ${done.duration}s)`, done.status === 'completed' && done.duration >= 0 && done.note === 'Wants Saturday at 10');
  await page.click('[data-role=voice-close]');

  // 7. history in the real Contact page and the real Conversations timeline
  await page.goto(u(`people/${f.contact}`));
  const contactHtml = await page.locator('[data-role=contact-calls]').innerText();
  check(`${label}: Contact page lists the call (outcome, note)`, /Outgoing call · Completed/.test(contactHtml) && /Wants Saturday at 10/.test(contactHtml), contactHtml.replace(/\n/g, ' | ').slice(0, 160));
  await page.goto(u('conversations'));
  await page.waitForSelector('.chat-users-list li, .chat-users-list-pinned li');
  await page.click('.chat-users-list li, .chat-users-list-pinned li');
  await page.waitForSelector('[data-role=timeline-activity]', { timeout: 15000 });
  const timeline = await page.locator('.chat_history').innerText();
  check(`${label}: Conversation timeline shows the call as activity, not as a text message`, /Outgoing call · Completed/.test(timeline) && /Can someone call me/.test(timeline));
  await page.screenshot({ path: `${shots}/voice-real-${label}-4-history.png` });
  await page.waitForFunction(() => { const b = document.querySelector('[data-role=conversation-call]'); return b && !b.disabled; }, null, { timeout: 15000 });
  check(`${label}: the conversation's Call action is enabled for a callable contact`, true);

  // incoming: routed to the assigned, online user; decline; then answer
  const inc = (id) => ({ call_session_id: `${label}-${id}`, call_control_id: `cc-${label}-${id}`, call_leg_id: `lg-${label}-${id}`, direction: 'incoming', from: '+12025550111', to: '+14155550199' });
  check(`${label}: signed incoming-call event accepted`, await webhook('call.initiated', inc('in-s1')) === 200);
  const incRow = lastCall();
  check(`${label}: incoming call recorded against the right Business/contact and routed (ringing, not missed)`, incRow.direction === 'inbound' && incRow.status === 'ringing', JSON.stringify(incRow));
  const leg = await ev(page, () => { const c = window.__rtc.clients[0].ring('+12025550111'); return c.id; });
  await page.waitForSelector('[data-role=voice-answer]');
  await page.waitForFunction(() => document.querySelector('[data-el=name]').textContent.trim() === 'Maya Lopez', null, { timeout: 8000 }).catch(() => {});
  check(`${label}: incoming panel shows the caller by name with Answer and Decline, nothing auto-answered`, (await text(page, '[data-el=name]')) === 'Maya Lopez' && await ev(page, (id) => !window.__rtc.calls.find((c) => c.id === id).log.includes('answer'), leg));
  await page.screenshot({ path: `${shots}/voice-real-${label}-5-incoming.png` });
  await page.click('[data-role=voice-decline]');
  await webhook('call.hangup', { ...inc('in-s1'), hangup_cause: 'call_rejected', hangup_source: 'callee' });
  check(`${label}: declined call recorded as declined`, lastCall().status === 'declined');
  await page.click('[data-role=voice-close]');

  await webhook('call.initiated', inc('in-s2'));
  await ev(page, () => window.__rtc.clients[0].ring('+12025550111'));
  await page.waitForSelector('[data-role=voice-answer]');
  await page.click('[data-role=voice-answer]');
  await page.waitForFunction(() => /In call/i.test(document.querySelector('[data-el=badge]').textContent));
  await webhook('call.answered', { ...inc('in-s2'), call_control_id: `leg2-${label}-in-s2` });
  check(`${label}: answering an incoming call is explicit and the server records it answered`, lastCall().status === 'answered');
  await page.click('[data-role=voice-end]');
  await webhook('call.hangup', { ...inc('in-s2'), hangup_cause: 'normal_clearing', end_time: new Date().toISOString() });
  check(`${label}: answered incoming call completes`, lastCall().status === 'completed');
  await page.click('[data-role=voice-close]');

  // microphone denied
  await page.goto(u(`people/${f.contact}`));
  await page.waitForSelector('[data-role=contact-call]:not([disabled])');
  await ev(page, () => { navigator.mediaDevices.getUserMedia = () => Promise.reject(Object.assign(new Error('denied'), { name: 'NotAllowedError' })); });
  await page.click('[data-role=contact-call]');
  await page.click('[data-role=voice-start]');
  await page.waitForFunction(() => /Microphone access is blocked/.test(document.querySelector('[data-el=msg]').textContent));
  const before = sql('select count(*) from voice_calls');
  check(`${label}: denied microphone: clear instruction, and no call was created`, true, `calls=${before}`);
  await page.screenshot({ path: `${shots}/voice-real-${label}-6-mic-denied.png` });
  await page.click('[data-role=voice-close]');

  // connection drop mid-call
  await page.goto(u(`people/${f.contact}`));
  const c2 = await startCallFromContact(s);
  await customerAnswers(c2);
  await page.waitForFunction(() => /In call/i.test(document.querySelector('[data-el=badge]').textContent), null, { timeout: 10000 });
  await ev(page, () => window.__rtc.clients[0].drop());
  check(`${label}: a dropped connection mid-call is shown without ending the call`, /Connection lost/.test(await text(page, '[data-el=msg]')) && /In call/i.test(await text(page, '[data-el=badge]')));

  // 8. workspace switching during the call (real switcher): confirm, hang up, go offline
  await ev(page, () => { window.__rtc.calls.at(-1).state = 'active'; });
  let dialogs = [];
  page.on('dialog', async (d) => { dialogs.push(d.type() + ':' + d.message()); await d.accept(); });
  const switchForm = page.locator(`form[action*="/context/"] button, form[action*="/context/"] [type=submit]`).first();
  let switched = false;
  if (await page.locator('form[action*="/context/"]').count() > 0) {
    await page.evaluate(({ w2, b2 }) => {
      const forms = [...document.querySelectorAll('form[action*="/context/"]')];
      const target = forms.find((fm) => fm.innerHTML.includes(b2) || fm.innerHTML.includes(w2)) || forms[forms.length - 1];
      target.requestSubmit ? target.requestSubmit() : target.submit();
    }, { w2: f.workspace2, b2: f.business2 });
    await page.waitForLoadState('load');
    await page.waitForTimeout(1500);
    switched = true;
  }
  check(`${label}: switching workspace mid-call asked first, hung up the call and went offline`, switched && dialogs.some((d) => /on a call/i.test(d)) && sql('select count(*) from voice_registrations where last_seen_at is not null') === '0', `dialogs=${JSON.stringify(dialogs)}`);

  // logout during a call (real logout form)
  await page.goto(u(`people/${f.contact}`));
  await page.waitForFunction(() => window.__rtc && window.__rtc.clients.length === 1, null, { timeout: 15000 });
  await page.waitForSelector('[data-role=contact-call]:not([disabled])');
  await page.waitForTimeout(500);
  const c3 = await startCallFromContact(s);
  await customerAnswers(c3);
  await page.waitForFunction(() => /In call/i.test(document.querySelector('[data-el=badge]').textContent), null, { timeout: 10000 });
  const hungUp = page.evaluate(() => new Promise((r) => { const c = window.__rtc.calls.at(-1); const orig = c.hangup; c.hangup = function () { window.__loggedHangup = true; return orig.apply(this, arguments); }; r(true); }));
  await hungUp;
  await Promise.all([page.waitForURL((x) => x.pathname.startsWith('/login') || x.pathname === '/', { timeout: 20000 }), page.evaluate(() => document.getElementById('logout-form').requestSubmit())]);
  check(`${label}: logout mid-call asked first and ended up logged out`, dialogs.some((d) => /on a call/i.test(d)) && /login|^\/$/.test(new URL(page.url()).pathname));
  check(`${label}: logout left nothing registered or ringing`, sql('select count(*) from voice_registrations where last_seen_at is not null') === '0');

  check(`${label}: no unexpected JavaScript console errors`, errors.length === 0, errors.slice(0, 4).join(' | '));
  await s.ctx.close();
}

// Start from a clean slate (disposable database): earlier aborted runs must not leave calls in flight.
sql('delete from voice_call_events'); sql('delete from voice_calls'); sql('delete from voice_registrations');

if (!process.env.ONLY_MOBILE) await run('desktop', { width: 1280, height: 800 });
sql('delete from voice_registrations');
if (!process.env.ONLY_MOBILE) await new Promise((r) => setTimeout(r, 65000)); // the login form is rate-limited per minute
await run('mobile', { width: 390, height: 844 });
await browser.close();
console.log(failures === 0 ? '\nALL CHECKS PASSED' : `\n${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
