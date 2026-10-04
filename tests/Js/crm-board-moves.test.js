'use strict';

// Run with: node --test tests/Js/crm-board-moves.test.js
// (CrmBoardMovesJsTest runs this from PHPUnit so it is part of the normal suite.)

const test = require('node:test');
const assert = require('node:assert/strict');
const { createMoveCoordinator, formatMoney, totalLabel, duplicateUids } = require('../../public/js/crm/board-moves.js');

/** A manual server: each send() returns a promise the test settles when it chooses. */
function harness(initial = { d1: 'A' }) {
  const sent = [];
  const events = [];
  const coordinator = createMoveCoordinator({
    send(request) {
      return new Promise((resolve, reject) => sent.push({ request, resolve, reject }));
    },
    wait: () => Promise.resolve(),
    on: {
      saving: (uid, on) => events.push(['saving', uid, on]),
      confirmed: (uid, body, info) => events.push(['confirmed', uid, body.stage_uid, info.latest]),
      revert: (uid, stage, info) => events.push(['revert', uid, stage, info.conflict]),
      idle: () => events.push(['idle']),
    },
  });

  Object.entries(initial).forEach(([uid, stage]) => coordinator.register(uid, stage));

  return { coordinator, sent, events };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));
const ok = (stage) => ({ ok: true, stage_uid: stage });

test('a drop is shown at once, before anything is sent back', () => {
  const { coordinator, sent } = harness();

  coordinator.move('d1', 'B');

  assert.equal(coordinator.shown('d1'), 'B');
  assert.equal(sent.length, 1);
  assert.deepEqual(sent[0].request, { uid: 'd1', from: 'A', to: 'B', gen: 1 });
});

test('dropping on the stage it is already shown in does nothing', () => {
  const { coordinator, sent } = harness();

  assert.equal(coordinator.move('d1', 'A'), null);
  assert.equal(sent.length, 0);
});

test('rapid A to B then B to C ends in C and sends one coalesced request from the confirmed stage', async () => {
  const { coordinator, sent, events } = harness();

  coordinator.move('d1', 'B');
  coordinator.move('d1', 'C');

  assert.equal(coordinator.shown('d1'), 'C');
  assert.equal(sent.length, 1, 'only one request per card is ever on the wire');

  sent[0].resolve(ok('B'));
  await flush();

  assert.equal(coordinator.shown('d1'), 'C', 'the late A to B answer must not move the card back');
  assert.equal(sent.length, 2);
  assert.deepEqual(sent[1].request, { uid: 'd1', from: 'B', to: 'C', gen: 2 });

  sent[1].resolve(ok('C'));
  await flush();

  assert.equal(coordinator.shown('d1'), 'C');
  assert.equal(coordinator.confirmedStage('d1'), 'C');
  assert.equal(coordinator.pending(), 0);
  assert.deepEqual(events.filter((e) => e[0] === 'revert'), []);
});

test('the response of a superseded request is marked stale and confirms nothing visually', async () => {
  const { coordinator, sent, events } = harness();

  coordinator.move('d1', 'B');
  coordinator.move('d1', 'C');
  sent[0].resolve(ok('B'));
  await flush();

  const first = events.find((e) => e[0] === 'confirmed');
  assert.deepEqual(first, ['confirmed', 'd1', 'B', false], 'latest=false so the board skips canonical totals and any redraw');
});

test('A to B to C to D while the first is slow collapses to a single A-confirmed to D request', async () => {
  const { coordinator, sent } = harness();

  coordinator.move('d1', 'B');
  coordinator.move('d1', 'C');
  coordinator.move('d1', 'D');
  sent[0].resolve(ok('B'));
  await flush();

  assert.deepEqual(sent[1].request, { uid: 'd1', from: 'B', to: 'D', gen: 3 });
  assert.equal(sent.length, 2);
});

test('an old failed request does not roll back a newer move', async () => {
  const { coordinator, sent, events } = harness();

  coordinator.move('d1', 'B');
  coordinator.move('d1', 'C');
  sent[0].reject({ status: 422, body: { message: 'no' } });
  await flush();

  assert.equal(coordinator.shown('d1'), 'C');
  assert.deepEqual(events.filter((e) => e[0] === 'revert'), [], 'no rollback while a newer drop exists');
  assert.deepEqual(sent[1].request, { uid: 'd1', from: 'A', to: 'C', gen: 2 }, 'the newer drop is sent from the still-confirmed stage');

  sent[1].resolve(ok('C'));
  await flush();

  assert.equal(coordinator.confirmedStage('d1'), 'C');
  assert.deepEqual(events.filter((e) => e[0] === 'revert'), []);
});

test('the newest drop failing rolls the card back to the last CONFIRMED stage, once', async () => {
  const { coordinator, sent, events } = harness();

  coordinator.move('d1', 'B');
  sent[0].resolve(ok('B'));
  await flush();
  coordinator.move('d1', 'C');
  sent[1].reject({ status: 500, body: {} });
  await flush();

  assert.equal(coordinator.shown('d1'), 'B');
  assert.deepEqual(events.filter((e) => e[0] === 'revert'), [['revert', 'd1', 'B', false]]);
});

test('a 409 adopts the stage the server reports and moves the card there', async () => {
  const { coordinator, sent, events } = harness();

  coordinator.move('d1', 'B');
  sent[0].reject({ status: 409, body: { stage_uid: 'Z', message: 'moved elsewhere' } });
  await flush();

  assert.equal(coordinator.shown('d1'), 'Z');
  assert.equal(coordinator.confirmedStage('d1'), 'Z');
  assert.deepEqual(events.filter((e) => e[0] === 'revert'), [['revert', 'd1', 'Z', true]]);
});

test('transient failures are retried with the identical request and then succeed', async () => {
  const { coordinator, sent } = harness();

  coordinator.move('d1', 'B');
  sent[0].reject({ network: true });
  await flush();

  assert.equal(sent.length, 2);
  assert.deepEqual(sent[1].request, sent[0].request, 'a retry repeats the same request');

  sent[1].resolve(ok('B'));
  await flush();

  assert.equal(coordinator.confirmedStage('d1'), 'B');
  assert.equal(coordinator.shown('d1'), 'B');
});

test('retries are bounded, then the card is rolled back', async () => {
  const { coordinator, sent, events } = harness();

  coordinator.move('d1', 'B');
  for (let i = 0; i < 3; i += 1) {
    sent[i].reject({ network: true });
    await flush();
  }

  assert.equal(sent.length, 3, 'one attempt plus two retries');
  assert.equal(coordinator.shown('d1'), 'A');
  assert.deepEqual(events.filter((e) => e[0] === 'revert'), [['revert', 'd1', 'A', false]]);
});

test('a validation refusal (422) is not retried', async () => {
  const { coordinator, sent } = harness();

  coordinator.move('d1', 'B');
  sent[0].reject({ status: 422, body: { message: 'closed' } });
  await flush();

  assert.equal(sent.length, 1);
  assert.equal(coordinator.shown('d1'), 'A');
});

test('different cards are independent and move concurrently', async () => {
  const { coordinator, sent } = harness({ d1: 'A', d2: 'A' });

  coordinator.move('d1', 'B');
  coordinator.move('d2', 'C');

  assert.equal(sent.length, 2, 'no board-wide lock: both are on the wire at once');

  sent[1].resolve(ok('C'));
  await flush();
  sent[0].reject({ status: 500, body: {} });
  await flush();

  assert.equal(coordinator.shown('d2'), 'C', 'one card failing never touches another');
  assert.equal(coordinator.shown('d1'), 'A');
});

test('moving back to the confirmed stage while the first request is in flight sends the way back afterwards', async () => {
  const { coordinator, sent } = harness();

  coordinator.move('d1', 'B');
  coordinator.move('d1', 'A');
  assert.equal(sent.length, 1);

  sent[0].resolve(ok('B'));
  await flush();

  assert.deepEqual(sent[1].request, { uid: 'd1', from: 'B', to: 'A', gen: 2 });
});

test('idle fires when the last unsettled card settles', async () => {
  const { coordinator, sent, events } = harness();

  coordinator.move('d1', 'B');
  assert.equal(coordinator.pending(), 1);

  sent[0].resolve(ok('B'));
  await flush();

  assert.equal(coordinator.pending(), 0);
  assert.ok(events.some((e) => e[0] === 'idle'));
});

test('sync after the board HTML is replaced keeps an unsettled card where the user put it', () => {
  const { coordinator } = harness();

  coordinator.move('d1', 'B');

  assert.equal(coordinator.sync('d1', 'A'), 'B', 'the fresh HTML still shows A; the caller must put it back in B');
});

test('sync adopts the rendered stage for a settled card and registers a new one', () => {
  const { coordinator } = harness();

  assert.equal(coordinator.sync('d1', 'Q'), null);
  assert.equal(coordinator.shown('d1'), 'Q');
  assert.equal(coordinator.sync('d9', 'A'), null);
  assert.equal(coordinator.shown('d9'), 'A');
});

test('money and totals are spelled exactly like the server', () => {
  assert.equal(formatMoney(120000, 'USD'), 'USD 1,200');
  assert.equal(formatMoney(120050, 'USD'), 'USD 1,200.50');
  assert.equal(formatMoney(5, 'USD'), 'USD 0.05');
  assert.equal(formatMoney(100000000, null), '1,000,000');
  assert.equal(totalLabel(3, 0, 'USD'), '3');
  assert.equal(totalLabel(3, 120000, 'USD'), '3 · USD 1,200');
});

test('the uniqueness invariant names every duplicated uid once', () => {
  assert.deepEqual(duplicateUids(['a', 'b', 'c']), []);
  assert.deepEqual(duplicateUids(['a', 'b', 'a', 'a', 'b']), ['a', 'b']);
});
