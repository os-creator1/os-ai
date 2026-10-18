/*
 * The date-range control's Custom state, shared by Results and Home (resources/views/customer/business/analytics/_range.blade.php).
 *
 * Bound once per page, by delegation, so it keeps working for a range control swapped in by an in-place update (async-region swaps do
 * not run scripts) and for every range control on the page.
 *
 * STATE. Whether the Custom date fields are open is ONE explicit fact: for Home's segmented control the form's own data-custom (rendered
 * by the server from the range actually shown, flipped only by the Custom toggle); the full-size Results control still follows its select.
 * Closed fields are disabled (they add nothing to a URL) and open ones are required (Apply can never submit an empty custom range).
 *
 * A PRESET PRESS never edits that state. A press only keeps the date fields out of THAT ONE submission (they are switched off for the
 * synchronous moment the browser, or async-region, reads the form, then put back). If the request succeeds the server renders the new
 * form (Custom closed); if it fails the old form is still on the page, still showing Custom data, and still says Custom.
 */
(function (root, factory) {
  'use strict';

  var api = factory();

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }

  if (root && root.document && !root.__analyticsRangeCustomDates) {
    root.__analyticsRangeCustomDates = true;
    api.bind(root.document, root);
  }
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  function isSegmented(form) {
    return form.getAttribute('data-segmented') === '1';
  }

  function isCustom(form) {
    if (isSegmented(form)) {
      return form.getAttribute('data-custom') === '1';
    }

    var preset = form.querySelector('[data-role="range-preset"]');

    return !!preset && preset.value === 'custom';
  }

  /** Make the form show exactly what its state says. */
  function sync(form) {
    if (!form) { return; }

    var custom = isCustom(form);

    form.querySelectorAll('[data-role="custom-dates"]').forEach(function (el) { el.hidden = !custom; });

    if (isSegmented(form)) {
      form.querySelectorAll('[data-custom-field]').forEach(function (input) { input.disabled = !custom; input.required = custom; });

      var toggle = form.querySelector('[data-role="range-custom-toggle"]');

      if (toggle) {
        toggle.setAttribute('aria-pressed', custom ? 'true' : 'false');
        toggle.classList.toggle('is-open', custom);
      }
    }
  }

  /**
   * Keep the Custom date fields out of ONE submission without changing the control's state, visibility or active button.
   * Returns the function that puts every field back exactly as it was.
   */
  function excludeCustomForSubmit(form) {
    var saved = [];

    form.querySelectorAll('[data-custom-field]').forEach(function (input) {
      saved.push({ input: input, disabled: input.disabled, required: input.required });
      input.disabled = true;
      input.required = false;
    });

    return function restore() {
      saved.forEach(function (s) { s.input.disabled = s.disabled; s.input.required = s.required; });
    };
  }

  function bind(document, win) {
    function syncAll(root) {
      (root || document).querySelectorAll('[data-role="analytics-range"]').forEach(sync);
    }

    document.addEventListener('change', function (event) {
      if (event.target && event.target.matches && event.target.matches('[data-role="range-preset"]')) {
        sync(event.target.form);
      }
    });

    document.addEventListener('click', function (event) {
      var target = event.target;
      var press = target && target.closest ? target.closest('[data-role="range-segments"] button[type="submit"][value]') : null;

      if (press && press.value !== 'custom') {
        var owner = press.form || press.closest('form');

        if (owner && isSegmented(owner)) {
          // The browser (or async-region) reads the form synchronously right after this click; the fields come back on the next turn.
          var restore = excludeCustomForSubmit(owner);
          win.setTimeout(restore, 0);
        }
      }

      var toggle = target && target.closest ? target.closest('[data-role="range-custom-toggle"]') : null;

      if (!toggle) { return; }

      var form = toggle.form || toggle.closest('form');
      form.setAttribute('data-custom', isCustom(form) ? '0' : '1');
      sync(form);
    });

    // A page restored from history shows exactly what the server rendered for its URL, plus any deliberate toggle.
    win.addEventListener('pageshow', function () { syncAll(document); });
    document.addEventListener('async-region:updated', function (event) { syncAll(event.target); });
    syncAll(document);
  }

  return { isCustom: isCustom, sync: sync, excludeCustomForSubmit: excludeCustomForSubmit, bind: bind };
});
