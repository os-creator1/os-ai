// White Label editor save queue — run with: node --test tests/Js
const test = require('node:test');
const assert = require('node:assert');
const Saver = require('../../public/js/agency-site-saver.js');

/** A server holding last-write-wins values, with per-request latency so responses can be slow. */
function fakeServer(latencies, behaviour) {
    const state = {};
    const log = [];
    let n = 0;
    return {
        state, log,
        send: async (body) => {
            const i = n++;
            const mode = (behaviour && behaviour[i]) || 'ok';
            await new Promise((r) => setTimeout(r, latencies[i] || 0));
            if (mode === 'network') { throw new Error('offline'); }
            if (mode === 'invalid') { return { status: 422, body: { ok: false, message: 'Use plain text only.' } }; }
            Object.assign(state, JSON.parse(JSON.stringify(body.answers)));
            log.push(JSON.stringify(body.answers));
            return { status: 200, body: { ok: true } };
        },
    };
}

test('an edit made while a save is in flight is still sent and wins (P1)', async () => {
    const server = fakeServer([60, 0]);
    const saver = Saver.create({ send: server.send });
    saver.remember('hero.title', 'Default');

    let dom = 'A';
    const first = saver.save(saver.changed({ 'hero.title': dom }));
    dom = 'AB';                                   // typed again while request 1 is still in flight
    const second = saver.save(saver.changed({ 'hero.title': dom }));
    const results = await Promise.all([first, second]);

    assert.deepStrictEqual(results.map((r) => r.ok), [true, true]);
    assert.strictEqual(server.state['hero.title'], 'AB', 'the server holds the latest value');
    assert.deepStrictEqual(saver.changed({ 'hero.title': dom }), {}, 'nothing left to send');
});

test('the slow first response can never overwrite the later edit (out of order)', async () => {
    const server = fakeServer([100, 1]);          // request 1 is much slower than request 2
    const saver = Saver.create({ send: server.send });
    saver.remember('p', 'D');

    const a = saver.save(saver.changed({ p: 'one' }));
    const b = saver.save(saver.changed({ p: 'two' }));
    await Promise.all([a, b]);

    assert.deepStrictEqual(server.log.map((l) => JSON.parse(l).p), ['one', 'two'], 'applied in the order made');
    assert.strictEqual(server.state.p, 'two');
});

test('changing a value and changing it back while in flight is still sent', async () => {
    const server = fakeServer([40, 0]);
    const saver = Saver.create({ send: server.send });
    saver.remember('p', 'Default');

    const a = saver.save(saver.changed({ p: 'X' }));
    const back = saver.changed({ p: 'Default' }); // user reverts before the response
    assert.deepStrictEqual(back, { p: 'Default' }, 'a revert differs from what the server will hold');
    const b = saver.save(back);
    await Promise.all([a, b]);

    assert.strictEqual(server.state.p, 'Default');
});

test('what was submitted is copied: later mutation of the object cannot change the request', async () => {
    const server = fakeServer([10]);
    const saver = Saver.create({ send: server.send });
    const answers = { list: ['a'] };
    const r = saver.save(answers);
    answers.list.push('mutated-after-queueing');
    await r;
    assert.deepStrictEqual(server.state.list, ['a']);
});

test('a validation failure is an explicit failure and the values are sent again next time', async () => {
    const server = fakeServer([0, 0], { 0: 'invalid' });
    const saver = Saver.create({ send: server.send });
    saver.remember('p', 'Default');

    const bad = await saver.save(saver.changed({ p: '<b>x</b>' }));
    assert.strictEqual(bad.ok, false);
    assert.strictEqual(bad.message, 'Use plain text only.');
    assert.strictEqual(saver.lastFailure(), 'Use plain text only.');
    assert.deepStrictEqual(saver.changed({ p: '<b>x</b>' }), { p: '<b>x</b>' }, 'still unsaved');

    const good = await saver.save(saver.changed({ p: 'fixed' }));
    assert.strictEqual(good.ok, true);
    assert.strictEqual(saver.lastFailure(), null);
});

test('a network failure resolves ok:false (never rejects) and keeps the edit unsaved', async () => {
    const server = fakeServer([0], { 0: 'network' });
    const saver = Saver.create({ send: server.send });
    saver.remember('p', 'Default');

    const res = await saver.save(saver.changed({ p: 'edit' }));
    assert.strictEqual(res.ok, false);
    assert.match(res.message, /Could not reach the server/);
    assert.deepStrictEqual(saver.changed({ p: 'edit' }), { p: 'edit' });
    assert.strictEqual(saver.pending(), 0);
});

test('a failure does not roll back a newer queued edit of the same field', async () => {
    const server = fakeServer([30, 0], { 0: 'network' });
    const saver = Saver.create({ send: server.send });
    saver.remember('p', 'Default');

    const a = saver.save(saver.changed({ p: 'first' }));   // will fail
    const b = saver.save(saver.changed({ p: 'second' }));  // queued after
    const [ra, rb] = await Promise.all([a, b]);

    assert.strictEqual(ra.ok, false);
    assert.strictEqual(rb.ok, true);
    assert.strictEqual(server.state.p, 'second');
    assert.deepStrictEqual(saver.changed({ p: 'second' }), {});
});

test('idle() resolves only after every queued save has finished, so publishing can wait for them', async () => {
    const server = fakeServer([30, 30]);
    const saver = Saver.create({ send: server.send });
    saver.remember('a', 'x'); saver.remember('b', 'x');
    saver.save({ a: '1' }); saver.save({ b: '2' });
    assert.strictEqual(saver.pending(), 2);
    await saver.idle();
    assert.strictEqual(saver.pending(), 0);
    assert.deepStrictEqual(server.state, { a: '1', b: '2' });
});

test('an empty save is a successful no-op and sends nothing', async () => {
    const server = fakeServer([0]);
    const saver = Saver.create({ send: server.send });
    const r = await saver.save({});
    assert.strictEqual(r.ok, true);
    assert.strictEqual(server.log.length, 0);
});

test('a reset queued behind an in-flight save runs after it, and later saves wait for the reset (reset wins)', async () => {
    const server = fakeServer([60]);
    const saver = Saver.create({ send: server.send });
    saver.remember('p', 'Default');
    const events = [];

    const edit = saver.save(saver.changed({ p: 'edited' })).then(() => events.push('save-done'));
    const reset = saver.enqueue(async () => { events.push('reset-run'); delete server.state.p; return { ok: true }; });
    await Promise.all([edit, reset]);

    assert.deepStrictEqual(events, ['save-done', 'reset-run'], 'the reset starts only after the in-flight save finished');
    assert.strictEqual(server.state.p, undefined, 'the reset is what the server ends with');
});

test('a failing queued task resolves {ok:false} instead of rejecting', async () => {
    const saver = Saver.create({ send: async () => ({ status: 200, body: { ok: true } }) });
    const r = await saver.enqueue(async () => { throw new Error('offline'); });
    assert.strictEqual(r.ok, false);
    assert.strictEqual(saver.pending(), 0);
});
