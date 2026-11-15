/* MotionGrove — Edit contact modal + country picker. Plain JS, no dependencies. */
(function () {
  'use strict';
  var root = document.getElementById('mgx-edit-contact');
  if (!root) { return; }

  var cfg = {};
  try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
  var countries = cfg.countries || [];
  var byIso = {};
  countries.forEach(function (c) { byIso[c.iso] = c; });

  var dialog = root.querySelector('.mgx-dialog');
  var form = root.querySelector('[data-mgx-form]');
  var fields = root.querySelector('[data-mgx-fields]');
  var phone = root.querySelector('[data-mgx-phone]');
  var ccBtn = root.querySelector('[data-mgx-cc]');
  var flagEl = root.querySelector('[data-mgx-flag]');
  var codeEl = root.querySelector('[data-mgx-code]');
  var pick = root.querySelector('[data-mgx-pick]');
  var pickSearch = root.querySelector('[data-mgx-pick-search]');
  var list = root.querySelector('[data-mgx-list]');
  var status = root.querySelector('[data-mgx-status]');
  var loading = root.querySelector('[data-mgx-loading]');
  var save = root.querySelector('[data-mgx-save]');
  var del = root.querySelector('[data-mgx-delete]');
  var confirmBox = root.querySelector('[data-mgx-confirm]');
  var groupSel = root.querySelector('[data-mgx-group]');
  form.elements.notes.placeholder = 'Event date, venue, how they found you\u2026';
  var region = byIso[cfg.defaultRegion] ? cfg.defaultRegion : 'US';
  var contact = null;
  var opener = null;
  var busy = false;
  var dirty = false;
  var token = 0;

  function setRegion(iso) {
    if (!byIso[iso]) { iso = cfg.defaultRegion || 'US'; }
    region = iso;
    var c = byIso[iso];
    flagEl.textContent = c.flag;
    flagEl.title = c.name;
    codeEl.textContent = c.code;
    ccBtn.setAttribute('aria-label', 'Country code: ' + c.name + ' ' + c.code);
    if (c.example) { phone.placeholder = c.example; }
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }

  function option(c) {
    var o = el('div', 'mgx-opt');
    o.setAttribute('role', 'option');
    o.setAttribute('data-iso', c.iso);
    o.setAttribute('aria-selected', c.iso === region ? 'true' : 'false');
    o.appendChild(el('span', 'mgx-flag', c.flag));
    o.appendChild(el('b', '', c.name));
    o.appendChild(el('span', 'cd', c.code));
    return o;
  }

  function renderList(query) {
    var q = String(query || '').trim().toLowerCase().replace(/^\+/, '');
    list.textContent = '';
    var matches = countries.filter(function (c) {
      return q === '' || c.name.toLowerCase().indexOf(q) !== -1 || c.iso.toLowerCase() === q || c.code.replace('+', '').indexOf(q) === 0;
    });
    if (matches.length === 0) { list.appendChild(el('div', 'mgx-none', 'No country matches “' + query + '”.')); return; }
    var common = q === '' ? matches.filter(function (c) { return c.common; }) : [];
    if (common.length) {
      list.appendChild(el('div', 'mgx-gl', 'Common'));
      common.forEach(function (c) { list.appendChild(option(c)); });
      list.appendChild(el('div', 'mgx-gl', 'All countries'));
    }
    matches.forEach(function (c) { list.appendChild(option(c)); });
  }

  function openPick() { pick.hidden = false; ccBtn.setAttribute('aria-expanded', 'true'); pickSearch.value = ''; renderList(''); pickSearch.focus(); }
  function closePick(focusBack) {
    if (pick.hidden) { return; }
    pick.hidden = true;
    ccBtn.setAttribute('aria-expanded', 'false');
    if (focusBack) { ccBtn.focus(); }
  }
  function choose(iso) { setRegion(iso); closePick(false); phone.focus(); clearError('phone'); }

  ccBtn.addEventListener('click', function () { pick.hidden ? openPick() : closePick(true); });
  pickSearch.addEventListener('input', function () { renderList(pickSearch.value); });
  pickSearch.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); var first = list.querySelector('.mgx-opt'); if (first) { choose(first.getAttribute('data-iso')); } }
  });
  list.addEventListener('click', function (e) { var o = e.target.closest('.mgx-opt'); if (o) { choose(o.getAttribute('data-iso')); } });

  function errEl(name) { return root.querySelector('[data-mgx-err="' + name + '"]'); }
  function fieldFor(name) { return form.elements[name]; }
  function showError(name, message) {
    var e = errEl(name);
    if (e) { e.textContent = message; e.classList.add('is-on'); }
    var f = fieldFor(name);
    if (f) { f.classList.add('is-bad'); f.setAttribute('aria-invalid', 'true'); }
  }
  function clearError(name) {
    var e = errEl(name);
    if (e) { e.textContent = ''; e.classList.remove('is-on'); }
    var f = fieldFor(name);
    if (f) { f.classList.remove('is-bad'); f.removeAttribute('aria-invalid'); }
  }
  function clearErrors() { ['phone', 'email', 'group'].forEach(clearError); }
  function setStatus(text, bad) {
    status.hidden = !text;
    status.textContent = text || '';
    status.classList.toggle('is-err', !!bad);
  }
  ['phone', 'email', 'group'].forEach(function (n) {
    var f = fieldFor(n);
    if (f) { f.addEventListener('input', function () { clearError(n); }); f.addEventListener('change', function () { clearError(n); }); }
  });

  function focusables() {
    return Array.prototype.filter.call(dialog.querySelectorAll('button, input, select, textarea, a[href]'), function (n) {
      return !n.disabled && !n.closest('[hidden]') && n.offsetParent !== null;
    });
  }

  function lock(on) {
    fields.disabled = on;
    save.disabled = on;
    del.disabled = on;
  }

  function fillGroups(groups, current) {
    groupSel.textContent = '';
    groups.forEach(function (g) {
      var o = document.createElement('option');
      o.value = g.uid; o.textContent = g.name;
      groupSel.appendChild(o);
    });
    groupSel.value = current;
  }

  function populate(d) {
    contact = d;
    setRegion(d.country);
    phone.value = d.phone || '';
    form.elements.first_name.value = d.first_name || '';
    form.elements.last_name.value = d.last_name || '';
    form.elements.email.value = d.email || '';
    form.elements.email.placeholder = d.email_ambiguous ? 'Several addresses are stored; leave blank to keep them' : 'name@example.com';
    form.elements.company.value = d.company || '';
    form.elements.notes.value = d.notes || '';
    fillGroups(d.groups || [], d.group || '');
    del.hidden = !d.can_delete;
  }

  function resetConfirm() { confirmBox.hidden = true; del.hidden = !(contact && contact.can_delete); }

  function open(trigger) {
    var uid = trigger.getAttribute('data-uid');
    if (!uid) { return false; }
    opener = trigger;
    contact = null;
    token += 1;
    var mine = token;
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    clearErrors();
    setStatus('');
    resetConfirm();
    del.hidden = true;
    form.reset();
    lock(true);
    loading.hidden = false;
    loading.classList.remove('is-err');
    loading.textContent = 'Loading contact…';
    dialog.focus();

    fetch(cfg.editUrl.replace('__UID__', encodeURIComponent(uid)), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
      .then(function (d) {
        if (mine !== token) { return; }
        populate(d);
        loading.hidden = true;
        lock(false);
        phone.focus();
      })
      .catch(function (err) {
        if (mine !== token) { return; }
        loading.classList.add('is-err');
        loading.textContent = String(err && err.message) === '404' ? 'This contact could not be found, or you no longer have access to it.' : 'This contact could not be loaded. Close this window and try again.';
      });
    return true;
  }

  function close() {
    if (busy) { return; }
    token += 1;
    closePick(false);
    root.hidden = true;
    document.body.style.overflow = '';
    if (dirty) { window.location.reload(); return; }
    if (opener && opener.focus) { opener.focus(); }
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-mgx-edit-contact]');
    if (t && !e.defaultPrevented && !(e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1)) {
      if (open(t)) { e.preventDefault(); }
    }
  });
  root.addEventListener('mousedown', function (e) { if (e.target === root) { close(); } });
  root.addEventListener('click', function (e) {
    if (e.target.closest('[data-mgx-close]')) { close(); }
    if (!pick.hidden && !e.target.closest('[data-mgx-pick]') && !e.target.closest('[data-mgx-cc]')) { closePick(false); }
  });
  document.addEventListener('keydown', function (e) {
    if (root.hidden) { return; }
    if (e.key === 'Escape') { e.preventDefault(); if (!pick.hidden) { closePick(true); } else { close(); } return; }
    if (e.key === 'Tab') {
      var f = focusables();
      if (!f.length) { e.preventDefault(); dialog.focus(); return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body || {})
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) { return { status: res.status, json: json }; });
    });
  }

  function failureText(r) {
    if (r.status === 419) { return 'Your session expired. Refresh the page and try again.'; }
    if (r.status === 403) { return (r.json && r.json.message) || 'You do not have permission to do that.'; }
    if (r.status === 404) { return 'This contact could not be found, or you no longer have access to it.'; }
    return (r.json && r.json.message) || 'Something went wrong. Nothing was changed.';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy || !contact) { return; }
    clearErrors();
    setStatus('');
    if (phone.value.trim() === '') { showError('phone', 'Enter a phone number.'); phone.focus(); return; }

    busy = true;
    lock(true);
    save.textContent = 'Saving…';
    post(contact.updateUrl, {
      country: region,
      phone: phone.value,
      first_name: form.elements.first_name.value,
      last_name: form.elements.last_name.value,
      email: form.elements.email.value,
      company: form.elements.company.value,
      group: groupSel.value,
      notes: form.elements.notes.value
    }).then(function (r) {
      if (r.status >= 200 && r.status < 300 && r.json.status === 'success') {
        dirty = true;
        busy = false;
        setStatus('Saved.');
        close();
        return;
      }
      var errors = (r.json && r.json.errors) || {};
      var any = false;
      Object.keys(errors).forEach(function (k) {
        var key = k.toLowerCase();
        if (errEl(key)) { showError(key, [].concat(errors[k])[0]); any = true; }
      });
      setStatus(any ? '' : failureText(r), !any);
      var firstBad = form.querySelector('.is-bad');
      if (firstBad) { firstBad.focus(); }
    }).catch(function () {
      setStatus('We could not reach the server. Nothing was confirmed saved; check the Contacts list before trying again.', true);
    }).then(function () {
      busy = false;
      if (!root.hidden) { lock(false); }
      save.textContent = 'Save changes';
    });
  });

  del.addEventListener('click', function () {
    del.hidden = true;
    confirmBox.hidden = false;
    confirmBox.querySelector('[data-mgx-delete-no]').focus();
  });
  root.querySelector('[data-mgx-delete-no]').addEventListener('click', function () { resetConfirm(); del.focus(); });
  root.querySelector('[data-mgx-delete-yes]').addEventListener('click', function () {
    if (busy || !contact) { return; }
    busy = true;
    lock(true);
    setStatus('Deleting…');
    post(contact.deleteUrl, {}).then(function (r) {
      if (r.status >= 200 && r.status < 300 && r.json.status === 'success') {
        window.location.assign(r.json.redirect || window.location.href);
        return;
      }
      busy = false;
      lock(false);
      resetConfirm();
      setStatus(failureText(r), true);
    }).catch(function () {
      busy = false;
      lock(false);
      resetConfirm();
      setStatus('We could not reach the server. Check the Contacts list before trying again.', true);
    });
  });

  if (/[?&]edit=([A-Za-z0-9]+)/.test(window.location.search)) {
    var m = window.location.search.match(/[?&]edit=([A-Za-z0-9]+)/);
    var ghost = document.createElement('button');
    ghost.setAttribute('data-uid', m[1]);
    open(ghost);
  }
})();
