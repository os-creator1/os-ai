// Contract 17B stage 4 — pure-logic tests for the editor's DOM -> runs
// serializer and the deposit / balance math. No dependencies: a minimal fake
// DOM stands in for the browser.
//
//   node --test tests/js/documents-editor.test.mjs

import test from 'node:test';
import assert from 'node:assert/strict';
import { domToRuns, isSafeHref, normalizeHref } from '../../resources/js/documents/editor/serializer.js';
import { currencyExponent, depositState, toDecimalString, toMinor } from '../../resources/js/documents/editor/money.js';

const text = (value) => ({ nodeType: 3, nodeValue: value, childNodes: [] });
const node = (name, attrs, ...children) => ({
    nodeType: 1,
    nodeName: name.toUpperCase(),
    childNodes: children,
    getAttribute: (key) => (attrs && key in attrs ? attrs[key] : null),
});
const root = (...children) => node('div', null, ...children);

test('plain text becomes one run', () => {
    assert.deepEqual(domToRuns(root(text('Hello world'))), [{ t: 'Hello world' }]);
});

test('bold, italic, underline map to b/i/u flags and merge with neighbours of equal format', () => {
    const runs = domToRuns(root(text('A '), node('b', null, text('bold')), text(' and '), node('em', null, node('u', null, text('both'))), text('!')));
    assert.deepEqual(runs, [
        { t: 'A ' },
        { t: 'bold', b: true },
        { t: ' and ' },
        { t: 'both', i: true, u: true },
        { t: '!' },
    ]);
    // adjacent text nodes of the same format collapse
    assert.deepEqual(domToRuns(root(text('a'), text('b'))), [{ t: 'ab' }]);
});

test('merge chips serialise to merge runs and keep surrounding formatting', () => {
    const runs = domToRuns(root(text('Hi '), node('span', { 'data-token': 'contact.first_name' }, text('Pat')), text(',')));
    assert.deepEqual(runs, [{ t: 'Hi ' }, { merge: 'contact.first_name' }, { t: ',' }]);
    const bold = domToRuns(root(node('strong', null, node('span', { 'data-token': 'business.name' }, text('Acme')))));
    assert.deepEqual(bold, [{ merge: 'business.name', b: true }]);
});

test('safe links become href, unsafe ones are dropped to plain text', () => {
    const ok = domToRuns(root(node('a', { href: 'https://example.com/x' }, text('site'))));
    assert.deepEqual(ok, [{ t: 'site', href: 'https://example.com/x' }]);
    const bad = domToRuns(root(node('a', { href: 'javascript:alert(1)' }, text('click'))));
    assert.deepEqual(bad, [{ t: 'click' }]);
    assert.deepEqual(domToRuns(root(node('a', { href: 'mailto:a@b.co' }, text('mail')))), [{ t: 'mail', href: 'mailto:a@b.co' }]);
    assert.deepEqual(domToRuns(root(node('a', { href: 'tel:+15550100' }, text('call')))), [{ t: 'call', href: 'tel:+15550100' }]);
});

test('br and block wrappers become newlines; the trailing break is not content', () => {
    assert.deepEqual(domToRuns(root(text('one'), node('br'), text('two'))), [{ t: 'one\ntwo' }]);
    assert.deepEqual(domToRuns(root(text('one'), node('div', null, text('two')), node('div', null, text('three')))), [{ t: 'one\ntwo\nthree' }]);
    assert.deepEqual(domToRuns(root(text('end'), node('br'))), [{ t: 'end' }]);
    assert.deepEqual(domToRuns(root(node('br'))), []);
});

test('nbsp and zero-width characters are normalised; unknown tags are flattened', () => {
    assert.deepEqual(domToRuns(root(text('a b​c'), node('span', { style: 'color:red' }, text('!')))), [{ t: 'a bc!' }]);
});

test('runs longer than the limit are split, never lost', () => {
    const long = 'x'.repeat(4500);
    const runs = domToRuns(root(text(long)));
    assert.deepEqual(runs.map((r) => r.t.length), [2000, 2000, 500]);
    assert.equal(runs.map((r) => r.t).join(''), long);
});

test('every produced run only uses the BlockSchema keys', () => {
    const runs = domToRuns(root(node('b', null, node('a', { href: 'https://x.test' }, text('t'))), node('span', { 'data-token': 'document.title' })));
    runs.forEach((run) => assert.ok(Object.keys(run).every((key) => ['t', 'b', 'i', 'u', 'href', 'merge'].includes(key))));
});

test('isSafeHref / normalizeHref mirror the server rules', () => {
    assert.equal(isSafeHref('https://example.com'), true);
    assert.equal(isSafeHref('http://example.com/a?b=1#c'), true);
    assert.equal(isSafeHref('javascript:alert(1)'), false);
    assert.equal(isSafeHref('data:text/html,hi'), false);
    assert.equal(isSafeHref('https://exa mple.com'), false);
    assert.equal(isSafeHref('mailto:pat@example.com'), true);
    assert.equal(isSafeHref('mailto:not-an-email'), false);
    assert.equal(isSafeHref('tel:+1 555'), false);
    assert.equal(isSafeHref('tel:+15550100'), true);
    assert.equal(normalizeHref('example.com'), 'https://example.com');
    assert.equal(normalizeHref('pat@example.com'), 'mailto:pat@example.com');
    assert.equal(normalizeHref('+1 555 010 0100'), 'tel:+15550100100');
    assert.equal(normalizeHref('javascript:alert(1)'), null);
    assert.equal(normalizeHref('  '), null);
});

test('money: decimal strings round-trip exactly', () => {
    assert.equal(currencyExponent('USD', 'en'), 2);
    assert.equal(currencyExponent('JPY', 'en'), 0);
    assert.equal(toMinor('250', 2), 25000);
    assert.equal(toMinor('250.5', 2), 25050);
    assert.equal(toMinor('1,250.00', 2), 125000);
    assert.equal(toMinor('0.07', 2), 7);
    assert.equal(toMinor('250.005', 2), null);
    assert.equal(toMinor('abc', 2), null);
    assert.equal(toMinor('', 2), null);
    assert.equal(toMinor('-5', 2), null);
    assert.equal(toDecimalString(25000, 2), '250.00');
    assert.equal(toDecimalString(7, 2), '0.07');
    assert.equal(toDecimalString(1200, 0), '1200');
});

test('deposit rules: > 0, < total, balance > 0, live balance = total - deposit', () => {
    const total = 100000; // 1,000.00
    assert.deepEqual(depositState(total, '250.00', 2), { depositMinor: 25000, balanceMinor: 75000, error: null });
    assert.equal(depositState(total, '0', 2).error !== null, true);
    assert.equal(depositState(total, '-1', 2).error !== null, true);
    assert.equal(depositState(total, '1000.00', 2).error !== null, true);
    assert.equal(depositState(total, '1200', 2).error !== null, true);
    assert.equal(depositState(total, '', 2).error !== null, true);
    assert.equal(depositState(total, 'ten', 2).error !== null, true);
    assert.equal(depositState(total, '999.99', 2).balanceMinor, 1);
    // the wording never mentions minor units
    ['0', '1000', 'x', ''].forEach((input) => assert.doesNotMatch(String(depositState(total, input, 2).error), /minor unit/i));
});
