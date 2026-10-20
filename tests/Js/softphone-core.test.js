'use strict';

// Run with: node --test tests/Js/softphone-core.test.js
// (VoiceSoftphoneCoreJsTest runs this from PHPUnit so it is part of the normal suite.)
// Simulated SDK events only: no browser, no Telnyx, no network.

const test = require('node:test');
const assert = require('node:assert/strict');
const { createSoftphone, friendlyCause } = require('../../public/js/voice/softphone-core.js');

/** A fake SDK call that behaves like the wrapper softphone.js builds around Telnyx's Call. */
function fakeCall(over = {}) {
  const c = {
    state: 'new', direction: 'outbound', remoteNumber: null, cause: null, sipReason: null, log: [],
    answer() { c.log.push('answer'); }, hangup() { c.log.push('hangup'); },
    muteAudio() { c.log.push('mute'); }, unmuteAudio() { c.log.push('unmute'); },
    hold() { c.log.push('hold'); return Promise.resolve(); }, unhold() { c.log.push('unhold'); return Promise.resolve(); },
  };
  return Object.assign(c, over);
}

function harness(opts = {}) {
  const timers = [];
  const clients = [];
  const posts = [];
  let t = 1_000_000;
  const responses = Object.assign({
    session: { ok: true, json: { inbound_number_uid: null } },
    token: { ok: true, json: { token: 'a.b.c', expires_at: null } },
    calls: { ok: true, json: { call: { uid: 'call-1', from: '+14155550199', to: '+12025550111', name: 'Maya Lopez', client_state: 'Y2FsbC0x' } } },
    'calls/call-1': { ok: true, json: { call_status: 'initiating', final: false, answered_elapsed_ms: null } },
    lookup: { ok: true, json: { name: 'Maya Lopez', number: '+12025550111', call_uid: 'call-in-1' } },
  }, opts.responses || {});
  let leaderFree = opts.leaderFree !== false;
  const leaderLog = [];

  const deps = {
    supported: opts.supported !== false,
    isSecure: opts.isSecure !== false,
    urls: { session: '/session', options: '/options', token: '/token', heartbeat: '/heartbeat', calls: '/calls', lookup: '/lookup', clientEvent: (u) => `/calls/${u}/client-event`, note: (u) => `/calls/${u}/note`, callStatus: (u) => `/calls/${u}`, cancel: (u) => `/calls/${u}/cancel` },
    api: {
      get(url) { return Promise.resolve(responses[url.split('?')[0].slice(1)] || { ok: true, json: {} }); },
      post(url, body) {
        posts.push([url, body]);
        const key = url.startsWith('/calls/') && !url.endsWith('/cancel') ? null : url.slice(1);
        return Promise.resolve((key && responses[key]) || { ok: true, json: { status: 'success' } });
      },
    },
    createClient(token, handlers) {
      const client = { token, handlers, connected: false, disconnected: false, newCalls: [],
        connect() { client.connected = true; }, disconnect() { client.disconnected = true; },
        // Browser-originated dialing must never happen: any use of newCall fails the test.
        newCall() { throw new Error('direct browser dialing is forbidden'); } };
      clients.push(client);
      return client;
    },
    checkMicrophone: opts.checkMicrophone || (() => Promise.resolve({ ok: true })),
    leader: { tryAcquire: () => leaderFree, renew() { leaderLog.push('renew'); }, release() { leaderLog.push('release'); }, takeOver() { leaderFree = true; leaderLog.push('takeOver'); } },
    now: () => t,
    // Short polls (the wait-for-ready loop) really run; long timers (reconnect, heartbeat, refresh) are held
    // so a test decides when they fire.
    setTimeout(fn, ms) {
      timers.push({ fn, ms, id: timers.length + 1, live: ms > 250 });
      if (ms <= 250) { setTimeout(fn, 0); }
      return timers.length;
    },
    clearTimeout(id) { if (timers[id - 1]) { timers[id - 1].live = false; } },
    setInterval(fn, ms) { return deps.setTimeout(fn, ms); },
    clearInterval(id) { deps.clearTimeout(id); },
    uuid: () => 'key-' + posts.length,
    beaconOffline() { leaderLog.push('beacon'); },
  };

  const sp = createSoftphone(deps);
  const tick = async () => { for (let i = 0; i < 4; i += 1) { await new Promise((r) => setImmediate(r)); } };
  const advance = (ms) => { t += ms; };
  return { sp, deps, clients, posts, timers, tick, advance, leaderLog, responses, setLeaderFree(v) { leaderFree = v; } };
}

const ready = async (h) => { h.sp.init(); await h.tick(); };
const pollOnce = async (h) => { h.timers.filter((t) => t.ms === 1500 && t.live).forEach((t) => t.fn()); await h.tick(); };

// Start a call the way the page does, let the "server" ring our browser leg, and return the answered leg.
const readyClient = async (h, uid = 'num-1') => {
  const p = h.sp.startCall({ numberUid: uid, scope: 'managed', fromNumber: '+14155550199', toNumber: '+12025550111', name: 'Maya Lopez', contactUid: 'c1' });
  await h.tick();
  const client = h.clients[h.clients.length - 1];
  client.handlers.ready();
  await p; // registered first, THEN the server was asked to dial
  const leg = fakeCall({ direction: 'inbound', state: 'ringing', remoteNumber: '+12025550111' });
  client.handlers.callUpdate(leg);
  client.last = leg;
  return { p, client, leg };
};

// The customer picks up: the server says so, and only then does the call become "in call".
const customerAnswers = async (h, client, leg, elapsedMs = 0) => {
  leg.state = 'active'; client.handlers.callUpdate(leg);
  h.responses['calls/call-1'] = { ok: true, json: { call_status: 'answered', final: false, answered_elapsed_ms: elapsedMs } };
  await pollOnce(h);
};

test('the server places the call: the browser registers first, asks the server, then answers only the leg it rang', async () => {
  const h = harness();
  const { client, leg } = await readyClient(h);
  assert.equal(h.clients.length, 1);
  assert.deepEqual(leg.log, ['answer'], 'the leg for THIS request is answered on the person\'s behalf');
  const order = h.posts.map(([u]) => u);
  assert.ok(order.indexOf('/token') < order.indexOf('/calls'), 'registered before the server dials');
  assert.deepEqual(h.posts.find(([u]) => u === '/calls')[1].contact_uid, 'c1');
  assert.equal(h.sp.getState().call.phase, 'dialing');
  leg.state = 'active'; client.handlers.callUpdate(leg);
  assert.equal(h.sp.getState().call.phase, 'ringing', 'our leg being up is not the customer answering');
});

test('a leg for a different number is NOT auto-answered: it is treated as a genuine incoming call', async () => {
  const h = harness();
  const p = h.sp.startCall({ numberUid: 'n', scope: 'managed', fromNumber: 'f', toNumber: '+12025550111' });
  await h.tick(); h.clients[0].handlers.ready(); await p;
  const stranger = fakeCall({ direction: 'inbound', state: 'ringing', remoteNumber: '+12025559999' });
  h.clients[0].handlers.callUpdate(stranger);
  assert.deepEqual(stranger.log, ['hangup'], 'turned away while a call is being placed, never auto-answered');
});

test('outgoing lifecycle: server-confirmed answer, accurate timer, mute, hold, end', async () => {
  const h = harness();
  const { client, leg } = await readyClient(h);
  await customerAnswers(h, client, leg, 4000);
  assert.equal(h.sp.getState().call.phase, 'active');
  const answeredAt = h.sp.getState().call.answeredAt;
  assert.equal(h.deps.now() - answeredAt, 4000, 'the timer starts from the SERVER\'s answer time');
  h.advance(65000);
  assert.equal(Math.floor((h.deps.now() - answeredAt) / 1000), 69);

  h.sp.toggleMute(); assert.equal(h.sp.getState().call.muted, true); assert.ok(leg.log.includes('mute'));
  h.sp.toggleMute(); assert.equal(h.sp.getState().call.muted, false); assert.ok(leg.log.includes('unmute'));
  h.sp.toggleHold(); await h.tick(); assert.ok(leg.log.includes('hold'));
  leg.state = 'held'; client.handlers.callUpdate(leg); assert.equal(h.sp.getState().call.held, true);
  leg.state = 'active'; client.handlers.callUpdate(leg); assert.equal(h.sp.getState().call.held, false);

  h.sp.hangup(); assert.ok(leg.log.includes('hangup'));
  leg.state = 'hangup'; client.handlers.callUpdate(leg);
  const c = h.sp.getState().call;
  assert.equal(c.phase, 'ended'); assert.equal(c.endReason.code, 'completed'); assert.equal(c.durationSeconds, 69);
  assert.ok(h.posts.some(([u, b]) => u === '/calls/call-1/client-event' && b.event === 'ended'));
});

test('the server reporting a final outcome ends the call with a plain message (busy, no answer)', async () => {
  const h = harness();
  const { client, leg } = await readyClient(h);
  leg.state = 'active'; client.handlers.callUpdate(leg);
  h.responses['calls/call-1'] = { ok: true, json: { call_status: 'busy', final: true } };
  await pollOnce(h);
  assert.equal(h.sp.getState().call.phase, 'ended');
  assert.equal(h.sp.getState().call.endReason.message, 'The number is busy.');
  assert.ok(leg.log.includes('hangup'), 'our leg is released');
});

test('cancel before the browser leg is up asks the server to hang up its leg', async () => {
  const h = harness();
  const p = h.sp.startCall({ numberUid: 'n', scope: 'managed', fromNumber: 'f', toNumber: '+12025550111' });
  await h.tick(); h.clients[0].handlers.ready(); await p;
  h.sp.hangup();
  assert.ok(h.posts.some(([u]) => u === '/calls/call-1/cancel'));
  assert.equal(h.sp.getState().call.endReason.code, 'canceled');
});

test('no ringing leg within the wait time fails honestly and cancels the server call', async () => {
  const h = harness();
  const p = h.sp.startCall({ numberUid: 'n', scope: 'managed', fromNumber: 'f', toNumber: '+12025550111' });
  await h.tick(); h.clients[0].handlers.ready(); await p;
  h.advance(26000);
  await pollOnce(h);
  assert.equal(h.sp.getState().call.endReason.code, 'leg_timeout');
  assert.ok(h.posts.some(([u]) => u === '/calls/call-1/cancel'));
});

test('a double click starts exactly one call', async () => {
  const h = harness();
  const a = h.sp.startCall({ numberUid: 'n', scope: 'managed', fromNumber: 'f', toNumber: 't' });
  const b = h.sp.startCall({ numberUid: 'n', scope: 'managed', fromNumber: 'f', toNumber: 't' });
  assert.equal((await b).code, 'call_in_progress');
  await h.tick();
  h.clients[0].handlers.ready();
  await a;
  assert.equal(h.posts.filter(([u]) => u === '/calls').length, 1);
});

test('denied microphone: no call is created, and the message says how to fix it', async () => {
  const h = harness({ checkMicrophone: () => Promise.resolve({ ok: false, code: 'denied', message: 'Microphone access is blocked.' }) });
  const r = await h.sp.startCall({ numberUid: 'n', fromNumber: 'f', toNumber: 't' });
  assert.equal(r.ok, false);
  assert.equal(h.sp.getState().call.phase, 'ended');
  assert.equal(h.sp.getState().call.endReason.message, 'Microphone access is blocked.');
  assert.equal(h.posts.filter(([u]) => u === '/calls').length, 0);
  assert.equal(h.clients.length, 0);
});

test('an unsupported or insecure browser says so instead of failing silently', async () => {
  for (const [opts, code] of [[{ supported: false }, 'unsupported'], [{ isSecure: false }, 'insecure']]) {
    const h = harness(opts);
    await h.sp.init();
    assert.equal(h.sp.getState().registration, code);
    const r = await h.sp.startCall({ numberUid: 'n' });
    assert.equal(r.code, code);
  }
});

test('a server refusal (cross-tenant number, destination not allowed, duplicate) is shown with the server message and nothing rings', async () => {
  const h = harness({ responses: { calls: { ok: false, json: { code: 'destination_not_allowed', message: 'Calls to this country are not enabled for your account.' } } } });
  const p = h.sp.startCall({ numberUid: 'n', scope: 'managed', fromNumber: 'f', toNumber: 't' });
  await h.tick(); h.clients[0].handlers.ready();
  const r = await p;
  assert.equal(r.code, 'destination_not_allowed');
  assert.equal(h.sp.getState().call.endReason.failed, true);
  assert.equal(h.sp.getState().call.endReason.message, 'Calls to this country are not enabled for your account.');
});

test('a token failure before dialing means the server is never asked to place a call', async () => {
  const h = harness({ responses: { token: { ok: false, json: { code: 'direct_dialing_open', message: 'Remove the outbound voice profile in Telnyx.' } } } });
  const r = await h.sp.startCall({ numberUid: 'n', scope: 'managed', fromNumber: 'f', toNumber: 't' });
  assert.equal(r.code, 'failed');
  assert.equal(h.posts.filter(([u]) => u === '/calls').length, 0);
});

test('incoming call: shown with the looked-up name, never auto-answered; Answer and Decline work', async () => {
  const h = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } } } });
  await ready(h);
  const client = h.clients[0];
  client.handlers.ready();

  const incoming = fakeCall({ direction: 'inbound', state: 'ringing', remoteNumber: '+12025550111' });
  client.handlers.callUpdate(incoming);
  await h.tick();
  assert.equal(h.sp.getState().call.phase, 'incoming');
  assert.equal(h.sp.getState().call.name, 'Maya Lopez');
  assert.equal(h.sp.getState().call.callUid, 'call-in-1');
  assert.deepEqual(incoming.log, [], 'nothing answers automatically');

  await h.sp.answer();
  assert.deepEqual(incoming.log, ['answer']);
  incoming.state = 'active'; client.handlers.callUpdate(incoming);
  assert.equal(h.sp.getState().call.phase, 'active');
});

test('declining an incoming call hangs up and reads as declined, not missed', async () => {
  const h = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } } } });
  await ready(h);
  const client = h.clients[0]; client.handlers.ready();
  const incoming = fakeCall({ direction: 'inbound', state: 'ringing', remoteNumber: '+12025558888' });
  client.handlers.callUpdate(incoming); await h.tick();
  assert.equal(h.sp.getState().call.name, 'Maya Lopez'); // lookup stub answers every number in this fake

  h.sp.decline();
  assert.deepEqual(incoming.log, ['hangup']);
  incoming.state = 'hangup'; client.handlers.callUpdate(incoming);
  assert.equal(h.sp.getState().call.endReason.code, 'declined');
});

test('a caller who hangs up first is a missed call; an unknown caller keeps only their number', async () => {
  const h = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } }, lookup: { ok: true, json: { name: null, number: '+12025558888' } } } });
  await ready(h);
  const client = h.clients[0]; client.handlers.ready();
  const incoming = fakeCall({ direction: 'inbound', state: 'ringing', remoteNumber: '+12025558888' });
  client.handlers.callUpdate(incoming); await h.tick();
  assert.equal(h.sp.getState().call.name, null);
  assert.equal(h.sp.getState().call.number, '+12025558888');
  incoming.state = 'hangup'; client.handlers.callUpdate(incoming);
  assert.equal(h.sp.getState().call.endReason.code, 'missed');
});

test('a second incoming call while one is open is turned away, not stacked', async () => {
  const h = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } } } });
  await ready(h);
  const client = h.clients[0]; client.handlers.ready();
  const first = fakeCall({ direction: 'inbound', state: 'ringing', remoteNumber: '+12025550111' });
  const second = fakeCall({ direction: 'inbound', state: 'ringing', remoteNumber: '+12025550222' });
  client.handlers.callUpdate(first);
  client.handlers.callUpdate(second);
  assert.deepEqual(second.log, ['hangup']);
  assert.deepEqual(first.log, []);
  assert.equal(h.sp.getState().call.number, '+12025550111');
});

test('inbound presence is only for the assigned number, and the heartbeat is sent', async () => {
  const h = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } } } });
  await ready(h);
  h.clients[0].handlers.ready();
  assert.equal(h.sp.getState().registration, 'registered');
  assert.ok(h.posts.some(([u, b]) => u === '/heartbeat' && b.number_uid === 'num-1'));
  assert.equal(h.clients.length, 1);
});

test('no assigned number means no registration and no SDK client at all', async () => {
  const h = harness();
  await ready(h);
  assert.equal(h.clients.length, 0);
  assert.equal(h.sp.getState().registration, 'off');
});

test('a token failure is surfaced; a network blip retries with a fresh token', async () => {
  const h = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } }, token: { ok: false, json: { code: 'invalid_api_key', message: 'Reconnect the Telnyx account.' } } } });
  await ready(h);
  assert.equal(h.sp.getState().registration, 'failed');
  assert.equal(h.sp.getState().registrationMessage, 'Reconnect the Telnyx account.');
  assert.equal(h.clients.length, 0);

  const h2 = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } }, token: { ok: false, json: { code: 'provider_unavailable', message: 'Try again.' } } } });
  await ready(h2);
  assert.equal(h2.sp.getState().registration, 'disconnected');
  assert.ok(h2.timers.some((t) => t.live), 'a reconnect is scheduled');
});

test('expired credentials rebuild the client with a NEW token; stale client events are ignored', async () => {
  const h = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } } } });
  await ready(h);
  const first = h.clients[0]; first.handlers.ready();
  first.handlers.error({ message: 'Login incorrect: token expired' });
  assert.equal(first.disconnected, true);
  assert.equal(h.sp.getState().registration, 'disconnected');

  h.timers.filter((t) => t.live).forEach((t) => t.fn());
  await h.tick(); await h.tick();
  assert.equal(h.clients.length, 2);
  h.clients[1].handlers.ready();
  assert.equal(h.sp.getState().registration, 'registered');

  first.handlers.ready(); // a late event from the replaced client changes nothing
  first.handlers.socketClose();
  assert.equal(h.sp.getState().registration, 'registered');
});

test('a dropped socket during a call keeps the call and says so; it is not torn down', async () => {
  const h = harness();
  const { client, leg } = await readyClient(h);
  await customerAnswers(h, client, leg);
  client.handlers.socketClose();
  assert.equal(h.sp.getState().call.phase, 'active');
  assert.match(h.sp.getState().call.notice, /Connection lost/);
  assert.equal(client.disconnected, false);
  leg.state = 'recovering'; client.handlers.callUpdate(leg);
  assert.match(h.sp.getState().call.notice, /Reconnecting/);
  leg.state = 'active'; client.handlers.callUpdate(leg);
  assert.equal(h.sp.getState().call.notice, null);
});

test('a call that ends after a connection drop says the connection dropped', async () => {
  const h = harness();
  const { client, leg } = await readyClient(h);
  await customerAnswers(h, client, leg);
  client.handlers.socketClose();
  leg.state = 'hangup'; client.handlers.callUpdate(leg);
  assert.equal(h.sp.getState().call.endReason.code, 'connection_lost');
});

test('another tab leading: this tab offers to take over, and taking over registers here', async () => {
  const h = harness({ leaderFree: false, responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } } } });
  await ready(h);
  assert.equal(h.sp.getState().registration, 'other_tab');
  assert.equal(h.clients.length, 0, 'a second SDK client is never created while another tab leads');

  const r = await h.sp.startCall({ numberUid: 'num-1', fromNumber: 'f', toNumber: 't' });
  assert.equal(r.code, 'other_tab');

  h.sp.dismiss();
  h.sp.takeOver(); await h.tick(); await h.tick();
  assert.deepEqual(h.leaderLog.filter((x) => x === 'takeOver'), ['takeOver']);
  assert.equal(h.clients.length, 1);
});

test('losing the lead stops being reachable here unless a call is live', async () => {
  const h = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } } } });
  await ready(h);
  const client = h.clients[0]; client.handlers.ready();
  h.sp.onLeaderLost();
  assert.equal(client.disconnected, true);
  assert.equal(h.sp.getState().registration, 'other_tab');
});

test('logout / workspace switch: hangs up, disconnects, releases the lead, marks offline, no timers left', async () => {
  const h = harness();
  const { p, client } = await readyClient(h);
  const call = client.last; call.state = 'active'; client.handlers.callUpdate(call);

  h.sp.shutdown();
  assert.ok(call.log.includes('hangup'));
  assert.equal(client.disconnected, true);
  assert.ok(h.leaderLog.includes('release') && h.leaderLog.includes('beacon'));
  assert.equal(h.sp.getState().registration, 'off');
  // and a late socket error cannot resurrect anything
  client.handlers.error({ message: 'late' });
  assert.equal(h.timers.filter((t) => t.live && t.ms >= 1000 && t.ms < 25000).length, 0);
});

test('notes save against the call record and are reported', async () => {
  const h = harness();
  const { p, client } = await readyClient(h);
  client.last.state = 'hangup'; client.handlers.callUpdate(client.last);
  await h.sp.saveNote('Wants Saturday');
  assert.ok(h.posts.some(([u, b]) => u === '/calls/call-1/note' && b.note === 'Wants Saturday'));
  assert.equal(h.sp.getState().call.noteSaved, true);
});

test('re-rendering listeners never duplicate SDK clients: register is idempotent', async () => {
  const h = harness({ responses: { session: { ok: true, json: { inbound_number_uid: 'num-1' } } } });
  await ready(h);
  h.clients[0].handlers.ready();
  await h.sp.init(); await h.sp.init();
  assert.equal(h.clients.length, 1);
});

test('plain-language causes', () => {
  assert.equal(friendlyCause('USER_BUSY'), 'The number is busy.');
  assert.equal(friendlyCause('NO_ANSWER'), 'Nobody answered.');
  assert.equal(friendlyCause('CALL_REJECTED'), 'The call was declined.');
  assert.equal(friendlyCause('NORMAL_CLEARING'), null);
});
