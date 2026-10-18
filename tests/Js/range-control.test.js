'use strict';

// Run with: node --test tests/Js/range-control.test.js
// (RangeControlJsTest runs this from PHPUnit so it is part of the normal suite.)

const test = require('node:test');
const assert = require('node:assert/strict');
const { isCustom, sync, excludeCustomForSubmit } = require('../../public/js/analytics/range-control.js');

const PRESETS = ['last_7_days', 'this_month', 'last_30_days'];

function fakeButton(value, active) {
  const classes = new Set(active ? ['is-active'] : []);

  return {
    value,
    attrs: { 'aria-pressed': active ? 'true' : 'false' },
    classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)), contains: (name) => classes.has(name) },
    setAttribute(k, v) { this.attrs[k] = v; },
  };
}

/**
 * A fake Home range form: just enough DOM for the control's own logic. `custom` is whether the Custom fields are open and `preset` is
 * the period the server rendered as displayed ('custom' when a custom range is shown), exactly as the Blade partial renders them.
 */
function fakeForm(custom, preset = 'this_month', values = { start: '2026-10-01', end: '2026-10-10' }) {
  const attrs = { 'data-segmented': '1', 'data-custom': custom ? '1' : '0', 'data-preset': preset };
  const fields = Object.entries(values).map(([name, value]) => ({ name, value, disabled: !custom, required: custom }));
  const dates = [{ hidden: !custom }, { hidden: !custom }];
  const presets = PRESETS.map((value) => fakeButton(value, preset === value));
  const toggle = fakeButton('', preset === 'custom');
  const lists = {
    '[data-custom-field]': fields,
    '[data-role="custom-dates"]': dates,
    '[data-role="range-segments"] button[type="submit"][value]': presets,
  };

  return {
    fields, dates, presets, toggle,
    getAttribute: (k) => (k in attrs ? attrs[k] : null),
    setAttribute: (k, v) => { attrs[k] = v; },
    querySelectorAll: (selector) => lists[selector] || [],
    querySelector: (selector) => (selector === '[data-role="range-custom-toggle"]' ? toggle : null),
    /** What a native GET submission (or async-region's FormData) would send for the date fields right now. */
    submitted: () => fields.filter((f) => !f.disabled).map((f) => f.name + '=' + f.value),
    validationErrors: () => fields.filter((f) => f.required && !f.disabled && f.value === '').map((f) => f.name),
    /** Every segment that looks selected: the styled class AND aria-pressed must agree. */
    active: () => {
      const byClass = [...presets.map((p) => [p.value, p]), ['custom', toggle]].filter(([, b]) => b.classList.contains('is-active')).map(([k]) => k);
      const byAria = [...presets.map((p) => [p.value, p]), ['custom', toggle]].filter(([, b]) => b.attrs['aria-pressed'] === 'true').map(([k]) => k);
      assert.deepEqual(byClass, byAria, 'is-active and aria-pressed never disagree');

      return byClass;
    },
    /** The Custom toggle press, as the control's click handler does it. */
    toggleCustom() { attrs['data-custom'] = isCustom(this) ? '0' : '1'; sync(this); },
  };
}

/** A preset press as the browser sees it: the control's click handler runs, the form is read, the next turn restores. */
function pressPreset(form) {
  const restore = excludeCustomForSubmit(form);
  const sent = form.submitted();
  const errors = form.validationErrors();
  restore(); // setTimeout(restore, 0)

  return { sent, errors };
}

test('a preset press while Custom is open keeps the date fields out of the request', () => {
  const form = fakeForm(true, 'custom');
  const { sent, errors } = pressPreset(form);

  assert.deepEqual(sent, [], 'no start/end rides along with a preset');
  assert.deepEqual(errors, [], 'closed-for-the-request fields cannot block the preset');
});

test('a FAILED preset update leaves Custom exactly as it was: open, enabled, required, and the only selected segment', () => {
  const form = fakeForm(true, 'custom');
  sync(form);

  pressPreset(form); // the request fails: async-region keeps this form and its Custom results

  assert.equal(isCustom(form), true, 'still says Custom');
  assert.equal(form.getAttribute('data-custom'), '1');
  assert.deepEqual(form.dates.map((d) => d.hidden), [false, false], 'the date fields are still visible');
  assert.deepEqual(form.fields.map((f) => [f.disabled, f.required]), [[false, true], [false, true]], 'enabled and required again');
  assert.deepEqual(form.active(), ['custom'], 'the selection did not move to the failed preset');
  assert.deepEqual(form.submitted(), ['start=2026-10-01', 'end=2026-10-10'], 'Apply still carries the custom dates');
});

test('a SUCCESSFUL preset update closes Custom: the server renders a closed form that selects the new preset', () => {
  pressPreset(fakeForm(true, 'custom'));
  const fresh = fakeForm(false, 'last_30_days'); // the swapped-in form for ?range=last_30_days

  sync(fresh);

  assert.equal(isCustom(fresh), false);
  assert.deepEqual(fresh.dates.map((d) => d.hidden), [true, true]);
  assert.deepEqual(fresh.fields.map((f) => [f.disabled, f.required]), [[true, false], [true, false]]);
  assert.deepEqual(fresh.active(), ['last_30_days'], 'exactly the new preset is selected');
  assert.deepEqual(fresh.submitted(), [], 'a preset URL carries no custom dates');
});

test('a preset press with Custom closed changes nothing', () => {
  const form = fakeForm(false, 'this_month');
  sync(form);

  const { sent } = pressPreset(form);

  assert.deepEqual(sent, []);
  assert.deepEqual(form.fields.map((f) => [f.disabled, f.required]), [[true, false], [true, false]]);
  assert.equal(isCustom(form), false);
  assert.deepEqual(form.active(), ['this_month']);
});

test('empty Custom is still blocked and valid Custom still submits both dates', () => {
  const empty = fakeForm(true, 'this_month', { start: '', end: '' });
  sync(empty);
  assert.deepEqual(empty.validationErrors(), ['start', 'end'], 'Apply cannot submit an empty custom range');

  const valid = fakeForm(true);
  sync(valid);
  assert.deepEqual(valid.validationErrors(), []);
  assert.deepEqual(valid.submitted(), ['start=2026-10-01', 'end=2026-10-10']);
});

test('opening Custom enables and requires the fields; closing disables them', () => {
  const form = fakeForm(false);

  form.setAttribute('data-custom', '1');
  sync(form);
  assert.deepEqual(form.fields.map((f) => [f.disabled, f.required]), [[false, true], [false, true]]);
  assert.deepEqual(form.dates.map((d) => d.hidden), [false, false]);

  form.setAttribute('data-custom', '0');
  sync(form);
  assert.deepEqual(form.fields.map((f) => [f.disabled, f.required]), [[true, false], [true, false]]);
  assert.deepEqual(form.dates.map((d) => d.hidden), [true, true]);
});

test('exactly one segment is selected in every state', () => {
  const form = fakeForm(false, 'this_month');
  sync(form);
  assert.deepEqual(form.active(), ['this_month']);

  form.toggleCustom(); // open Custom: Custom becomes the selection, the preset lets go
  assert.deepEqual(form.active(), ['custom']);
  assert.equal(form.toggle.classList.contains('is-active'), true, 'the styled class, not just aria-pressed');

  form.toggleCustom(); // close without applying: the displayed preset is selected again
  assert.deepEqual(form.active(), ['this_month']);
  assert.equal(form.toggle.classList.contains('is-active'), false);
});

test('a server-rendered Custom range keeps Custom selected, and closing its fields cannot select a preset that is not displayed', () => {
  const form = fakeForm(true, 'custom');
  sync(form);
  assert.deepEqual(form.active(), ['custom']);

  form.toggleCustom(); // fields close, but the page still shows the custom range
  assert.deepEqual(form.active(), ['custom']);
  assert.deepEqual(form.dates.map((d) => d.hidden), [true, true]);

  form.toggleCustom();
  assert.deepEqual(form.active(), ['custom']);
});

test('a stale highlight is cleared: a form rendered with the wrong class converges to the single displayed selection', () => {
  const form = fakeForm(false, 'last_7_days');
  form.toggle.classList.toggle('is-active', true); // e.g. restored markup that still highlights Custom
  form.toggle.setAttribute('aria-pressed', 'true');

  sync(form);

  assert.deepEqual(form.active(), ['last_7_days']);
});

test('bind marks the page as JavaScript-enabled so the no-JavaScript fallback CSS can never match', () => {
  const { bind } = require('../../public/js/analytics/range-control.js');
  const html = { attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } };
  const doc = { documentElement: html, addEventListener() {}, querySelectorAll: () => [] };

  bind(doc, { addEventListener() {} });

  assert.equal(html.attrs['data-js'], '1', 'the markup guards its <noscript> rules with html:not([data-js])');
});

test('an async swap drops the <noscript> that DOMParser turned into live DOM, then re-syncs the new form', () => {
  const { bind } = require('../../public/js/analytics/range-control.js');
  const handlers = {};
  const doc = { documentElement: { setAttribute() {} }, addEventListener: (type, fn) => { handlers[type] = fn; }, querySelectorAll: () => [] };
  bind(doc, { addEventListener() {} });

  let removed = 0;
  let formsSynced = 0;
  const form = { getAttribute: () => null, querySelectorAll: () => { formsSynced += 1; return []; }, querySelector: () => null };
  const region = {
    querySelectorAll: (selector) => {
      if (selector === 'noscript') { return [{ remove() { removed += 1; } }]; }
      if (selector === '[data-role="analytics-range"]') { return [form]; }

      return [];
    },
  };

  // Parsing with scripting disabled left a real <noscript><style> in the swapped-in region: it must be removed on update.
  handlers['async-region:updated']({ target: region });

  assert.equal(removed, 1, 'the swapped-in <noscript> is removed');
  assert.ok(formsSynced > 0, 'the new form is re-synced to its rendered state');
});
