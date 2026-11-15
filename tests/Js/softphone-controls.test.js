'use strict';

// Run with: node --test tests/Js/softphone-controls.test.js
// The real public/js/crm/mg-softphone.js in a vm sandbox with a fake DOM, fake Telnyx SDK and scripted fetch:
// keypad (real DTMF on the live SDK call), hold/resume, and the post-call wrap-up (disposition + note).

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/js/crm/mg-softphone.js'), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

function element(attrs) {
  const a = Object.assign({}, attrs);
  const classes = new Set();
  const children = new Map();
  return {
    hidden: false, textContent: '', disabled: false, value: '',
    classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c), contains: (c) => classes.has(c), toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)) },
    getAttribute: (k) => (k in a ? a[k] : null), setAttribute: (k, v) => { a[k] = String(v); }, removeAttribute: (k) => { delete a[k]; },
    handlers: {},
    addEventListener(type, fn) { (this.handlers[type] = this.handlers[type] || []).push(fn); },
    click() { (this.handlers.click || []).forEach((f) => f({})); },
    focus() {}, appendChild() {}, querySelectorAll: () => [],
    querySelector(sel) { if (!children.has(sel)) { children.set(sel, element()); } return children.get(sel); },
  };
}

function boot(statusSequence, numbers) {
  const cfg = { csrf: 'x', contactUid: 'u1', contactLabel: 'Test Test', contactPhone: '+1 (564) 544-8628', contactInitials: 'TT', inbound: null,
    sessionUrl: '/session', placeUrl: '/place', statusUrl: '/status/__CALL__', hangupUrl: '/hangup/__CALL__', wrapupUrl: '/wrapup/__CALL__', numbersUrl: numbers ? '/numbers' : null };
  const root = element({ 'data-config': JSON.stringify(cfg), 'data-state': 'idle' });
  const digitButtons = ['1', '4'].map((d) => element({ 'data-digit': d }));
  const dispositionButtons = ['interested', 'booked'].map((d) => element({ 'data-disposition': d }));
  root.querySelectorAll = (sel) => (sel === '[data-digit]' ? digitButtons : sel === '[data-disposition]' ? dispositionButtons : []);
  const requests = [];
  let sdk = null;
  function FakeRTC() { this.handlers = {}; }
  FakeRTC.prototype.on = function (e, f) { (this.handlers[e] = this.handlers[e] || []).push(f); };
  FakeRTC.prototype.connect = function () { setTimeout(() => (this.handlers['telnyx.ready'] || []).forEach((f) => f()), 0); };
  FakeRTC.prototype.disconnect = function () {};
  const respond = (data, status = 200) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });
  const sandbox = {
    document: { createElement: () => element(), getElementById: (id) => (id === 'mg-softphone' ? root : element()), querySelector: () => element(), querySelectorAll: () => [], addEventListener() {} },
    window: { TelnyxWebRTC: { TelnyxRTC: FakeRTC }, addEventListener() {} },
    navigator: { mediaDevices: { getUserMedia: () => Promise.resolve({ getTracks: () => [] }) } },
    fetch: (url, options) => {
      const method = (options && options.method) || 'GET';
      requests.push([method, url, options && options.body ? JSON.parse(options.body) : null]);
      if (url === '/numbers') { return respond({ data: numbers }); }
      if (url === '/session') { return respond({ data: { login_token: 't' } }); }
      if (url === '/place') {
        setTimeout(() => {
          sdk = { state: 'ringing', sent: [], held: false, answer() { this.state = 'active'; }, hangup() { this.state = 'hangup'; }, muteAudio() {}, unmuteAudio() {},
            dtmf(d) { this.sent.push(d); }, hold() { this.held = true; return Promise.resolve(); }, unhold() { this.held = false; return Promise.resolve(); } };
          sandbox.__rtc.handlers['telnyx.notification'].forEach((f) => f({ type: 'callUpdate', call: sdk }));
        }, 5);
        return respond({ data: { id: 'c1', ui_state: 'connecting' } }, 202);
      }
      if (url.indexOf('/status/') === 0) { return respond({ data: statusSequence.shift() || statusSequence[0] }); }
      if (url.indexOf('/hangup/') === 0) { return respond({ data: { id: 'c1', status: 'ended', ui_state: 'ended', answered_at: '2026-11-15T10:00:00Z', duration_seconds: 90 } }); }
      if (url.indexOf('/wrapup/') === 0) { return respond({ data: { saved: true } }); }
      return respond({});
    },
    setTimeout: (f, ms) => { const t = setTimeout(f, ms); t.unref(); return t; },
    setInterval: (f, ms) => { const t = setInterval(f, ms); t.unref(); return t; },
    clearTimeout, clearInterval, console, Promise, JSON, Date, Math, encodeURIComponent, Array, Object, String, RegExp, Error,
  };
  const Real = FakeRTC;
  sandbox.window.TelnyxWebRTC.TelnyxRTC = function (...args) { const r = new Real(...args); sandbox.__rtc = r; return r; };
  sandbox.window.TelnyxWebRTC.TelnyxRTC.prototype = Real.prototype;
  sandbox.window.crypto = undefined;
  vm.runInNewContext(SOURCE, sandbox);

  const el = (role) => root.querySelector('[data-role="' + role + '"]');
  const chooserChildren = [];
  el('softphone-chooser').appendChild = (c) => chooserChildren.push(c);
  return { root, sandbox, requests, el, digitButtons, dispositionButtons, chooserChildren, sdk: () => sdk };
}

const connected = { id: 'c1', status: 'answered', ui_state: 'connected', answered_at: '2026-11-15T10:00:00Z', duration_seconds: 0, from_number: '+1 (720) 502-8306' };

async function connectedCall() {
  const h = boot([connected]);
  h.sandbox.window.MGSoftphone.callContact({ uid: 'u1', label: 'Test Test', phone: '+1 (564) 544-8628', initials: 'TT' });
  for (let i = 0; i < 40 && h.root.getAttribute('data-state') !== 'connected'; i++) { await new Promise((r) => setTimeout(r, 60)); }
  assert.equal(h.root.getAttribute('data-state'), 'connected');
  return h;
}

test('on call: the calling number is shown and the keypad sends real DTMF on the live SDK call', async () => {
  const h = await connectedCall();
  assert.equal(h.el('softphone-status').textContent, 'On call');
  assert.equal(h.el('softphone-from').textContent, 'Calling from +1 (720) 502-8306');

  h.el('softphone-keypad').click();
  assert.equal(h.el('softphone-keypad-view').hidden, false, 'the keypad view is open');
  assert.equal(h.el('softphone-controls').hidden, true);
  h.digitButtons[0].click(); h.digitButtons[1].click();
  assert.deepEqual(h.sdk().sent, ['1', '4'], 'DTMF went to the SDK call');
  assert.equal(h.el('softphone-digits').textContent, '1 4');

  h.el('softphone-keypad-hide').click();
  assert.equal(h.el('softphone-keypad-view').hidden, true);
  assert.equal(h.el('softphone-controls').hidden, false);
});

test('hold and resume act on the SDK call and the state says so; a refused hold is reported, never faked', async () => {
  const h = await connectedCall();
  h.el('softphone-hold').click();
  await tick(); await tick();
  assert.equal(h.sdk().held, true);
  assert.equal(h.el('softphone-status').textContent, 'On hold');

  h.el('softphone-hold').click();
  await tick(); await tick();
  assert.equal(h.sdk().held, false);
  assert.equal(h.el('softphone-status').textContent, 'On call');

  h.sdk().hold = () => Promise.reject(new Error('refused'));
  h.el('softphone-hold').click();
  await tick(); await tick();
  assert.equal(h.el('softphone-status').textContent, 'On call', 'not shown as held');
  assert.match(h.el('softphone-detail').textContent, /could not be put on hold/);
});

test('after the call ends the wrap-up posts the disposition and note once the user saves', async () => {
  const h = await connectedCall();
  h.el('softphone-notebtn').click();
  h.el('softphone-note-text').value = 'Wants a 360 booth quote.';
  h.el('softphone-note-text').handlers.input.forEach((f) => f({}));
  h.el('softphone-hangup').click();
  for (let i = 0; i < 20 && h.root.getAttribute('data-state') !== 'ended'; i++) { await new Promise((r) => setTimeout(r, 30)); }
  assert.equal(h.root.getAttribute('data-state'), 'ended');
  assert.equal(h.el('softphone-wrapup').hidden, false);
  assert.equal(h.el('softphone-done-label').textContent, 'Save');

  h.dispositionButtons[0].click();
  h.el('softphone-done').click();
  await tick(); await tick();
  const post = h.requests.find((r) => r[1] === '/wrapup/c1');
  assert.ok(post, 'wrap-up was sent');
  assert.deepEqual(post[2], { note: 'Wants a 360 booth quote.', disposition: 'interested' });
});

test('record and transfer stay disabled: no capability flag turns them on', () => {
  assert.match(SOURCE, /keypad: true, hold: true, record: false, transfer: false, note: true/);
});

test('with several eligible numbers the person chooses first; the chosen token is sent, and nothing dials before that', async () => {
  const numbers = [{ id: 'tok-primary', number: '+1 (720) 502-8306', primary: true }, { id: 'tok-second', number: '+1 (720) 502-8307', primary: false }];
  const h = boot([connected], numbers);
  h.sandbox.window.MGSoftphone.callContact({ uid: 'u1', label: 'Test Test', phone: '+1 (564) 544-8628', initials: 'TT' });
  for (let i = 0; i < 20 && h.root.getAttribute('data-state') !== 'choose'; i++) { await new Promise((r) => setTimeout(r, 20)); }
  assert.equal(h.root.getAttribute('data-state'), 'choose');
  assert.equal(h.el('softphone-chooser').hidden, false);
  assert.equal(h.requests.some((r) => r[1] === '/place'), false, 'nothing is dialled before a number is chosen');
  assert.equal(h.chooserChildren.length, 2);

  h.chooserChildren[1].click();                 // pick the second number
  h.el('softphone-choose-go').onclick();
  for (let i = 0; i < 40 && !h.requests.some((r) => r[1] === '/place'); i++) { await new Promise((r) => setTimeout(r, 30)); }
  const place = h.requests.find((r) => r[1] === '/place');
  assert.ok(place, 'the call was placed');
  assert.equal(place[2].from, 'tok-second');
});

test('with a single eligible number there is no chooser and no from token', async () => {
  const h = boot([connected], [{ id: 'only', number: '+1 (720) 502-8306', primary: true }]);
  h.sandbox.window.MGSoftphone.callContact({ uid: 'u1', label: 'Test Test', phone: '+1 (564) 544-8628', initials: 'TT' });
  for (let i = 0; i < 40 && !h.requests.some((r) => r[1] === '/place'); i++) { await new Promise((r) => setTimeout(r, 30)); }
  assert.equal(h.requests.find((r) => r[1] === '/place')[2].from, undefined);
});
