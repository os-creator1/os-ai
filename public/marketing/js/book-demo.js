/*
 * /book-demo glue. Step 1 is the visitor's details form; step 2 is the real scheduler (public booking engine,
 * driven by its own script). This file only moves between the two, carries the step-1 answers into the booking
 * write, and mounts the Cloudflare Turnstile widget when the final step opens so the token is always fresh.
 * Nothing is stored in a URL; a changed form is preserved in sessionStorage across one reload only.
 */
(function () {
  'use strict';

  var card = document.getElementById('mgd-card');
  var form = document.getElementById('mgd-form'); // absent when the owner publishes a form with no questions
  var app = document.getElementById('pb-app');
  var book = document.getElementById('pb-form');
  if (!card || !app || !book) { return; }

  var schedule = document.getElementById('mgd-schedule');
  var alertBox = document.getElementById('mgd-alert');
  var button = document.getElementById('mgd-continue');
  var KEY = 'mgd-answers';
  var widget = null;
  var busy = false;

  function setState(state) {
    card.setAttribute('data-state', state);
    schedule.hidden = state === 'form';
    if (form) { form.hidden = state !== 'form'; }
  }

  function clearErrors() {
    if (!alertBox) { return; }
    alertBox.hidden = true;
    [].forEach.call(card.querySelectorAll('[data-err]'), function (e) {
      if (e.closest('#pb-app')) { return; }
      e.hidden = true; e.textContent = '';
    });
  }

  function showErrors(errors, message) {
    clearErrors();
    var first = null;
    Object.keys(errors || {}).forEach(function (key) {
      var name = key.replace(/\..*$/, '');
      var target = form.querySelector('[data-err="' + name + '"]');
      if (target) { target.textContent = errors[key][0]; target.hidden = false; first = first || target; }
    });
    if (message) { alertBox.textContent = message; alertBox.hidden = false; }
    var focus = first ? first.closest('label, fieldset') : alertBox;
    if (focus) { var input = focus.querySelector && focus.querySelector('input, select, textarea'); (input || alertBox).focus && (input || alertBox).focus(); }
  }

  // ---- step 1 -> 2 --------------------------------------------------------

  if (form) { form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (busy) { return; }
    busy = true; button.disabled = true; clearErrors();
    fetch(form.action, {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (res) {
      return res.json().then(function (data) { return { status: res.status, data: data }; });
    }).then(function (r) {
      busy = false; button.disabled = false;
      if (r.status === 200) {
        book.elements.form_version.value = r.data.form_version;
        setState('schedule');
        var thanks = document.getElementById('mgd-thanks');
        thanks.scrollIntoView({ block: 'nearest' });
        thanks.focus();
        return;
      }
      showErrors(r.data.errors, r.data.message || 'Please check your details.');
    }).catch(function () {
      busy = false; button.disabled = false;
      showErrors({}, 'Something went wrong. Please try again.');
    });
  }); }

  var edit = document.getElementById('mgd-edit');
  if (edit && form) {
    edit.addEventListener('click', function () {
      setState('form');
      var first = form.querySelector('input, select, textarea');
      if (first) { first.focus(); }
    });
  }

  // ---- carry the answers into the booking write ----------------------------

  book.addEventListener('formdata', function (ev) {
    if (!form) { return; }
    new FormData(form).forEach(function (value, name) {
      if (name !== '_token') { ev.formData.append(name, value); }
    });
  });

  // ---- a refused booking can send the visitor back to step 1 ---------------

  app.addEventListener('pb:refused', function (ev) {
    var data = ev.detail && ev.detail.data || {};
    var errors = data.errors || {};
    var own = Object.keys(errors).filter(function (k) { return !/^(date|time|turnstile|website|attendee_first_name|attendee_email)$/.test(k); });
    if (!form) {
      // No questionnaire on this page, but the owner has since published one: ask for a reload.
      var notice = document.getElementById('pb-alert');
      if (data.form_changed && notice) { notice.textContent = 'We have updated this form. Please reload the page and try again.'; notice.hidden = false; }
      return;
    }
    if (data.form_changed) {
      try {
        var saved = {};
        new FormData(form).forEach(function (v, n) { if (n !== '_token') { (saved[n] = saved[n] || []).push(v); } });
        sessionStorage.setItem(KEY, JSON.stringify(saved));
      } catch (e) { /* storage unavailable: the visitor retypes */ }
      alertBox.textContent = (data.message || 'We have updated this form.') + ' ';
      var again = document.createElement('a');
      again.href = location.pathname; again.textContent = 'Reload the form';
      again.style.textDecoration = 'underline';
      alertBox.appendChild(again);
      setState('form'); alertBox.hidden = false; alertBox.focus && alertBox.focus();
    } else if (own.length) {
      setState('form'); showErrors(errors, data.message);
    }
  });

  // Restore answers saved before a reload, for the fields that still exist.
  try {
    var raw = form ? sessionStorage.getItem(KEY) : null;
    if (raw) {
      sessionStorage.removeItem(KEY);
      var saved = JSON.parse(raw);
      Object.keys(saved).forEach(function (name) {
        var els = form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]');
        [].forEach.call(els, function (el) {
          if (el.type === 'checkbox' || el.type === 'radio') { el.checked = saved[name].indexOf(el.value) !== -1; }
          else if (el.tagName === 'SELECT') { if ([].some.call(el.options, function (o) { return o.value === saved[name][0]; })) { el.value = saved[name][0]; } }
          else { el.value = saved[name][0]; }
        });
      });
    }
  } catch (e) { /* ignore */ }

  // ---- scheduler step -> card state, and the Turnstile lifecycle -----------

  var holder = document.getElementById('mgd-turnstile');

  function mountTurnstile(tries) {
    if (!holder || widget !== null) { return; }
    if (!window.turnstile) {
      if (tries < 40) { setTimeout(function () { mountTurnstile(tries + 1); }, 250); }
      return;
    }
    widget = window.turnstile.render(holder, { sitekey: holder.getAttribute('data-sitekey') });
  }

  function unmountTurnstile() {
    if (widget !== null && window.turnstile) { try { window.turnstile.remove(widget); } catch (e) { /* gone */ } }
    widget = null;
  }

  function onStep() {
    var step = app.getAttribute('data-step');
    if (step === 'done') { setState('done'); }
    if (step === 'details') { mountTurnstile(0); } else { unmountTurnstile(); }
  }
  new MutationObserver(onStep).observe(app, { attributes: true, attributeFilter: ['data-step'] });

  // Never post before the check has produced a token (capture phase: runs before the scheduler's own handler).
  app.addEventListener('submit', function (ev) {
    if (!holder) { return; }
    var token = book.querySelector('[name="cf-turnstile-response"]');
    if (!token || !token.value) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      var err = book.querySelector('[data-err="turnstile"]');
      err.textContent = 'Please wait for the security check to finish, then confirm again.'; err.hidden = false;
    }
  }, true);

  // The attendee inputs are only a fallback: when the questionnaire already holds the person's first name or email
  // (an optional question they did fill in), do not ask for it twice.
  function syncAttendee() {
    [].forEach.call(book.querySelectorAll('[data-attendee]'), function (wrap) {
      var key = wrap.getAttribute('data-attendee');
      var own = form && form.elements[key];
      var held = !!(own && own.value && own.value.trim() !== '');
      var input = wrap.querySelector('input');
      wrap.hidden = held;
      if (input) { input.required = !held; }
    });
  }
  new MutationObserver(function () { if (app.getAttribute('data-step') === 'details') { syncAttendee(); } })
    .observe(app, { attributes: true, attributeFilter: ['data-step'] });

  setState(form ? 'form' : 'schedule');
})();
