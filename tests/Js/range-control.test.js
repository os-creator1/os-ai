'use strict';

// Run with: node --test tests/Js/range-control.test.js
// (RangeControlJsTest runs this from PHPUnit so it is part of the normal suite.)

const test = require('node:test');
const assert = require('node:assert/strict');
const { isCustom, sync, excludeCustomForSubmit } = require('../../public/js/analytics/range-control.js');

/** A fake Home range form: just enough DOM for the control's own logic. `custom` is the state the server rendered. */
function fakeForm(custom, values = { start: '2026-10-01', end: '2026-10-10' }) {
  const attrs = { 'data-segmented': '1', 'data-custom': custom ? '1' : '0' };
  const fields = Object.entries(values).map(([name, value]) => ({ name, value, disabled: !custom, required: custom }));
  const dates = [{ hidden: !custom }, { hidden: !custom }];
  const toggle = { attrs: { 'aria-pressed': custom ? 'true' : 'false' }, setAttribute(k, v) { this.attrs[k] = v; }, classList: { toggle() {} } };
  const lists = { '[data-custom-field]': fields, '[data-role="custom-dates"]': dates };

  return {
    fields, dates, toggle,
    getAttribute: (k) => (k in attrs ? attrs[k] : null),
    setAttribute: (k, v) => { attrs[k] = v; },
    querySelectorAll: (selector) => lists[selector] || [],
    querySelector: (selector) => (selector === '[data-role="range-custom-toggle"]' ? toggle : null),
    /** What a native GET submission (or async-region's FormData) would send for the date fields right now. */
    submitted: () => fields.filter((f) => !f.disabled).map((f) => f.name + '=' + f.value),
    validationErrors: () => fields.filter((f) => f.required && !f.disabled && f.value === '').map((f) => f.name),
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
  const form = fakeForm(true);
  const { sent, errors } = pressPreset(form);

  assert.deepEqual(sent, [], 'no start/end rides along with a preset');
  assert.deepEqual(errors, [], 'closed-for-the-request fields cannot block the preset');
});

test('a FAILED preset update leaves Custom exactly as it was: open, enabled, required, pressed', () => {
  const form = fakeForm(true);

  pressPreset(form); // the request fails: async-region keeps this form and its Custom results

  assert.equal(isCustom(form), true, 'still says Custom');
  assert.equal(form.getAttribute('data-custom'), '1');
  assert.deepEqual(form.dates.map((d) => d.hidden), [false, false], 'the date fields are still visible');
  assert.deepEqual(form.fields.map((f) => [f.disabled, f.required]), [[false, true], [false, true]], 'enabled and required again');
  assert.equal(form.toggle.attrs['aria-pressed'], 'true');
  assert.deepEqual(form.submitted(), ['start=2026-10-01', 'end=2026-10-10'], 'Apply still carries the custom dates');
});

test('a SUCCESSFUL preset update closes Custom: the server renders a closed form and sync agrees', () => {
  pressPreset(fakeForm(true));
  const fresh = fakeForm(false); // the swapped-in form for ?range=last_30_days

  sync(fresh);

  assert.equal(isCustom(fresh), false);
  assert.deepEqual(fresh.dates.map((d) => d.hidden), [true, true]);
  assert.deepEqual(fresh.fields.map((f) => [f.disabled, f.required]), [[true, false], [true, false]]);
  assert.equal(fresh.toggle.attrs['aria-pressed'], 'false');
  assert.deepEqual(fresh.submitted(), [], 'a preset URL carries no custom dates');
});

test('a preset press with Custom closed changes nothing', () => {
  const form = fakeForm(false);

  const { sent } = pressPreset(form);

  assert.deepEqual(sent, []);
  assert.deepEqual(form.fields.map((f) => [f.disabled, f.required]), [[true, false], [true, false]]);
  assert.equal(isCustom(form), false);
});

test('empty Custom is still blocked and valid Custom still submits both dates', () => {
  const empty = fakeForm(true, { start: '', end: '' });
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
