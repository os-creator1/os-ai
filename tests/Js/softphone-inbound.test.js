'use strict';

// Run with: node --test tests/Js/softphone-inbound.test.js
// Deterministic harness: the real public/js/crm/mg-softphone.js runs in a vm sandbox with a minimal fake DOM, a fake
// Telnyx SDK and a scripted fetch. No network, no browser.

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
    hidden: false, textContent: '', disabled: false,
    classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c), contains: (c) => classes.has(c), toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)) },
    getAttribute: (k) => (k in a ? a[k] : null),
    setAttribute: (k, v) => { a[k] = String(v); },
    handlers: {},
    addEventListener(type, fn) { (this.handlers[type] = this.handlers[type] || []).push(fn); },
    click() { (this.handlers.click || []).forEach((f) => f({})); },
    querySelectorAll: () => [],
    querySelector(sel) { if (!children.has(sel)) { children.set(sel, element()); } return children.get(sel); },
  };
}

function boot() {
  const cfg = {
    csrf: 'x', contactUid: null, contactLabel: '', contactPhone: '', contactInitials: '#',
    sessionUrl: '/session', placeUrl: '/place', statusUrl: '/status/__CALL__', hangupUrl: '/hangup/__CALL__',
    inbound: { presenceUrl: '/presence', incomingUrl: '/incoming', answerUrl: '/answer/__CALL__', declineUrl: '/decline/__CALL__' },
  };
  const root = element({ 'data-config': JSON.stringify(cfg), 'data-state': 'idle' });
  const incomingQueue = []; // each GET /incoming pops one scripted responder
  const clients = [];
  const requests = []; // every fetch the script made: [method, url]
  const state = { mic: null }; // test hook: replaces getUserMedia

  function FakeRTC() {
    this.handlers = {};
    clients.push(this);
  }
  FakeRTC.prototype.on = function (evt, fn) { (this.handlers[evt] = this.handlers[evt] || []).push(fn); };
  FakeRTC.prototype.connect = function () { setTimeout(() => (this.handlers['telnyx.ready'] || []).forEach((f) => f()), 0); };
  FakeRTC.prototype.disconnect = function () {};
  FakeRTC.prototype.emit = function (call) { (this.handlers['telnyx.notification'] || []).forEach((f) => f({ type: 'callUpdate', call })); };

  const respond = (data, ok = true, status = 200) => Promise.resolve({ ok, status, json: () => Promise.resolve(data) });
  const sandbox = {
    document: {
      getElementById: (id) => (id === 'mg-softphone' ? root : element()),
      querySelector: () => element(), querySelectorAll: () => [], addEventListener() {},
    },
    window: { TelnyxWebRTC: { TelnyxRTC: FakeRTC }, addEventListener() {} },
    navigator: { mediaDevices: { getUserMedia: () => (state.mic ? state.mic() : Promise.resolve({ getTracks: () => [] })) } },
    fetch: (url, options) => {
      requests.push([(options && options.method) || 'GET', url]);
      if (url === '/session') { return respond({ data: { login_token: 'a.b.c' } }); }
      if (url === '/presence') { return respond({ data: { available: true } }); }
      if (url === '/incoming') { return (incomingQueue.shift() || (() => respond({ data: [] })))(); }
      return respond({});
    },
    setTimeout: (f, ms) => { const t = setTimeout(f, ms); t.unref(); return t; },
    setInterval: (f, ms) => { const t = setInterval(f, ms); t.unref(); return t; },
    clearTimeout, clearInterval, console, Promise, JSON, Date, Math, encodeURIComponent, Array, Object, String, RegExp, Error,
  };
  sandbox.window.crypto = undefined;
  vm.runInNewContext(SOURCE, sandbox);

  const sdkCall = () => ({ state: 'ringing', hangups: 0, hangup() { this.hangups++; this.state = 'hangup'; }, answer() {} });
  const nameOf = () => root.querySelector('[data-role="softphone-name"]').textContent;

  const click = (role) => root.querySelector('[data-role="' + role + '"]').click();
  return { root, clients, incomingQueue, sdkCall, nameOf, respond, requests, state, click };
}

test('an early caller hangup during identification frees the slot, and a stale answer never reopens the call', async () => {
  const h = boot();
  await tick(); await tick(); await tick();
  assert.equal(h.clients.length, 1, 'the page registered one shared client');
  const rtc = h.clients[0];

  // Ring 1: the identification request is slow (held open).
  let releaseFirst;
  h.incomingQueue.push(() => new Promise((resolve) => { releaseFirst = () => resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: [{ id: 'old-call', caller: { name: 'Stale Caller', number: '+1 (415) 555-0000' } }] }) }); }));
  const first = h.sdkCall();
  rtc.emit(first);
  await tick();

  // The caller hangs up before the server answered.
  first.state = 'hangup';
  rtc.emit(first);
  await tick();

  // Ring 2 arrives and is identified normally: the busy flag must not have stuck.
  h.incomingQueue.push(() => h.respond({ data: [{ id: 'new-call', caller: { name: 'New Caller', number: '+1 (415) 555-1111' } }] }));
  const second = h.sdkCall();
  rtc.emit(second);
  await tick(); await tick();

  assert.equal(second.hangups, 0, 'the second ring was not rejected as busy');
  assert.equal(h.root.getAttribute('data-state'), 'incoming');
  assert.equal(h.nameOf(), 'New Caller');

  // The late response for the ended ring must be ignored.
  releaseFirst();
  await tick(); await tick();
  assert.equal(h.nameOf(), 'New Caller', 'a stale response did not replace the live call');
  assert.equal(h.root.getAttribute('data-state'), 'incoming');
});

test('an ended ring whose late response arrives alone does not open a popup', async () => {
  const h = boot();
  await tick(); await tick(); await tick();
  const rtc = h.clients[0];

  let release;
  h.incomingQueue.push(() => new Promise((resolve) => { release = () => resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: [{ id: 'gone', caller: { name: 'Gone', number: '' } }] }) }); }));
  const call = h.sdkCall();
  rtc.emit(call);
  await tick();
  call.state = 'hangup';
  rtc.emit(call);
  await tick();
  release();
  await tick(); await tick();

  assert.notEqual(h.root.getAttribute('data-state'), 'incoming');
});

function ringAndIdentify(h, rtc, id, name) {
  h.incomingQueue.push(() => h.respond({ data: [{ id, caller: { name, number: '+1 (415) 555-0000' } }] }));
  const call = h.sdkCall();
  rtc.emit(call);
  return call;
}

test('a stale microphone rejection for an ended call cannot decline or hang up the next call', async () => {
  const h = boot();
  await tick(); await tick(); await tick();
  const rtc = h.clients[0];

  const callA = ringAndIdentify(h, rtc, 'call-a', 'Caller A');
  await tick(); await tick();
  assert.equal(h.nameOf(), 'Caller A');

  // Answer A: the microphone prompt stays pending.
  let rejectMic;
  h.state.mic = () => new Promise((resolve, reject) => { rejectMic = reject; });
  h.click('softphone-answer');
  await tick();

  // A ends (caller hung up) and its popup is dismissed; then B rings and is identified.
  callA.state = 'hangup';
  rtc.emit(callA);
  h.incomingQueue.length = 0;
  await tick(); await tick(); await tick();
  const callB = ringAndIdentify(h, rtc, 'call-b', 'Caller B');
  await tick(); await tick();
  assert.equal(h.nameOf(), 'Caller B');
  assert.equal(h.root.getAttribute('data-state'), 'incoming');
  const before = h.requests.length;

  // A's microphone request now rejects, late.
  rejectMic({ name: 'NotAllowedError' });
  await tick(); await tick(); await tick();

  const after = h.requests.slice(before).map((r) => r.join(' '));
  assert.deepEqual(after.filter((r) => /decline|hangup/.test(r)), [], 'nothing was declined or hung up');
  assert.equal(callB.hangups, 0, "B's ringing leg was not hung up");
  assert.equal(h.root.getAttribute('data-state'), 'incoming', "B's popup is intact");
  assert.equal(h.nameOf(), 'Caller B');
});

test('a genuine microphone refusal on the current call still declines it and reports the problem', async () => {
  const h = boot();
  await tick(); await tick(); await tick();
  const rtc = h.clients[0];

  const call = ringAndIdentify(h, rtc, 'call-c', 'Caller C');
  await tick(); await tick();
  h.state.mic = () => Promise.reject({ name: 'NotAllowedError' });
  h.click('softphone-answer');
  await tick(); await tick(); await tick();

  assert.ok(h.requests.some((r) => r[0] === 'POST' && r[1] === '/decline/call-c'), 'the call was declined on the server');
  assert.equal(call.hangups, 1, 'the ringing leg was hung up');
  assert.equal(h.root.getAttribute('data-state'), 'failed');
  assert.match(h.root.querySelector('[data-role="softphone-detail"]').textContent, /Microphone access was blocked/);
});
