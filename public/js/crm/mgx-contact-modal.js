/* MotionGrove — New contact modal + country picker. Plain JS, no dependencies. */
(function () {
  'use strict';
  var root = document.getElementById('mgx-new-contact');
  if (!root) { return; }

  var cfg = {};
  try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
  var countries = cfg.countries || [];
  var byIso = {};
  countries.forEach(function (c) { byIso[c.iso] = c; });

  var dialog = root.querySelector('.mgx-dialog');
  var form = root.querySelector('[data-mgx-form]');
  var phone = root.querySelector('[data-mgx-phone]');
  var ccBtn = root.querySelector('[data-mgx-cc]');
  var flagEl = root.querySelector('[data-mgx-flag]');
  var codeEl = root.querySelector('[data-mgx-code]');
  var pick = root.querySelector('[data-mgx-pick]');
  var pickSearch = root.querySelector('[data-mgx-pick-search]');
  var list = root.querySelector('[data-mgx-list]');
  var status = root.querySelector('[data-mgx-status]');
  var save = root.querySelector('[data-mgx-save]');
  var another = root.querySelector('[data-mgx-another]');
  var region = byIso[cfg.defaultRegion] ? cfg.defaultRegion : 'US';
  var opener = null;
  var dirty = false;
  var busy = false;

  function setRegion(iso) {
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

  function openPick() {
    pick.hidden = false;
    ccBtn.setAttribute('aria-expanded', 'true');
    pickSearch.value = '';
    renderList('');
    pickSearch.focus();
  }
  function closePick(focusBack) {
    if (pick.hidden) { return; }
    pick.hidden = true;
    ccBtn.setAttribute('aria-expanded', 'false');
    if (focusBack) { ccBtn.focus(); }
  }

  ccBtn.addEventListener('click', function () { pick.hidden ? openPick() : closePick(true); });
  pickSearch.addEventListener('input', function () { renderList(pickSearch.value); });
  pickSearch.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      var first = list.querySelector('.mgx-opt');
      if (first) { choose(first.getAttribute('data-iso')); }
    }
  });
  list.addEventListener('click', function (e) {
    var o = e.target.closest('.mgx-opt');
    if (o) { choose(o.getAttribute('data-iso')); }
  });
  function choose(iso) {
    setRegion(iso);
    closePick(false);
    phone.focus();
    clearError('phone');
  }

  function errEl(name) { return root.querySelector('[data-mgx-err="' + name + '"]'); }
  function fieldFor(name) { return form.elements[name] || (name === 'phone' ? phone : null); }
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
  function clearErrors() { ['phone', 'email', 'group', 'location'].forEach(clearError); }
  function setStatus(text, bad) {
    status.hidden = !text;
    status.textContent = text || '';
    status.classList.toggle('is-err', !!bad);
  }

  ['phone', 'email', 'group', 'location'].forEach(function (n) {
    var f = fieldFor(n);
    if (f) { f.addEventListener('input', function () { clearError(n); }); f.addEventListener('change', function () { clearError(n); }); }
  });

  function focusables() {
    return Array.prototype.filter.call(dialog.querySelectorAll('button, input, select, textarea, a[href]'), function (n) {
      return !n.disabled && n.offsetParent !== null;
    });
  }

  var groupSel = root.querySelector('[data-mgx-group]');
  var locWrap = root.querySelector('[data-mgx-location-wrap]');
  var locSel = root.querySelector('[data-mgx-location]');
  var optionsLoaded = false;

  function fill(select, items, placeholder) {
    select.textContent = '';
    if (placeholder) { var p = document.createElement('option'); p.value = ''; p.textContent = placeholder; select.appendChild(p); }
    items.forEach(function (i) { var o = document.createElement('option'); o.value = i.uid; o.textContent = i.name; select.appendChild(o); });
  }

  function loadOptions() {
    if (optionsLoaded) { return; }
    fetch(cfg.optionsUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) { throw new Error('options'); } return r.json(); })
      .then(function (d) {
        optionsLoaded = true;
        if (d.groups.length) { fill(groupSel, d.groups); }
        else { groupSel.textContent = ''; var o = document.createElement('option'); o.value = ''; o.textContent = d.canCreateFirstList ? 'Contacts (created for you)' : 'No group available'; groupSel.appendChild(o); }
        groupSel.disabled = false;
        if (d.locations.length) { fill(locSel, d.locations, 'Choose a location'); locWrap.hidden = false; }
      })
      .catch(function () { groupSel.textContent = ''; var o = document.createElement('option'); o.value = ''; o.textContent = 'Could not load groups'; groupSel.appendChild(o); setStatus('Groups could not be loaded. Close and reopen this window.', true); });
  }

  function open(trigger) {
    opener = trigger || document.activeElement;
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    setRegion(region);
    setStatus('');
    clearErrors();
    loadOptions();
    phone.focus();
  }

  function close() {
    if (busy) { return; }
    closePick(false);
    root.hidden = true;
    document.body.style.overflow = '';
    if (dirty) { window.location.reload(); return; }
    if (opener && opener.focus) { opener.focus(); }
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-mgx-open-contact]');
    if (t) { e.preventDefault(); open(t); return; }
    var call = e.target.closest('[data-mgx-call]');
    if (call) {
      e.preventDefault();
      if (window.MGSoftphone && typeof window.MGSoftphone.callContact === 'function') {
        window.MGSoftphone.callContact({ uid: call.getAttribute('data-uid'), label: call.getAttribute('data-label'), phone: call.getAttribute('data-phone'), initials: call.getAttribute('data-initials') });
      } else {
        window.alert('Calling could not load on this page. Refresh and try again.');
      }
    }
  });
  root.addEventListener('mousedown', function (e) { if (e.target === root) { close(); } });
  root.addEventListener('click', function (e) {
    if (e.target.closest('[data-mgx-close]')) { close(); }
    if (!pick.hidden && !e.target.closest('[data-mgx-pick]') && !e.target.closest('[data-mgx-cc]')) { closePick(false); }
  });
  document.addEventListener('keydown', function (e) {
    if (root.hidden) { return; }
    if (e.key === 'Escape') {
      e.preventDefault();
      if (!pick.hidden) { closePick(true); } else { close(); }
      return;
    }
    if (e.key === 'Tab') {
      var f = focusables();
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) { return; }
    clearErrors();
    setStatus('');
    if (phone.value.trim() === '') { showError('phone', 'Enter a phone number.'); phone.focus(); return; }

    busy = true;
    save.disabled = true;
    save.textContent = 'Saving…';
    var body = {
      country: region,
      phone: phone.value,
      first_name: form.elements.first_name.value,
      last_name: form.elements.last_name.value,
      email: form.elements.email.value,
      group: form.elements.group ? form.elements.group.value : '',
      notes: form.elements.notes.value,
      location: form.elements.location ? form.elements.location.value : ''
    };
    fetch(cfg.storeUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) { return { status: res.status, json: json }; });
    }).then(function (r) {
      if (r.status >= 200 && r.status < 300 && r.json.status === 'success') {
        dirty = true;
        busy = false;
        if (another.checked) {
          ['phone', 'first_name', 'last_name', 'email', 'notes'].forEach(function (n) { var f = fieldFor(n); if (f) { f.value = ''; } });
          setStatus('Contact saved. Add the next one.');
          phone.focus();
        } else {
          close();
        }
        return;
      }
      var errors = (r.json && r.json.errors) || {};
      var any = false;
      Object.keys(errors).forEach(function (k) {
        var key = k.toLowerCase();
        if (errEl(key)) { showError(key, [].concat(errors[k])[0]); any = true; }
      });
      if (!any) { setStatus((r.json && r.json.message) || (r.status === 419 ? 'Your session expired. Refresh the page and try again.' : 'The contact could not be saved. Nothing was created.'), true); }
      else { setStatus('', false); }
      var firstBad = form.querySelector('.is-bad');
      if (firstBad) { firstBad.focus(); }
    }).catch(function () {
      setStatus('We could not reach the server. Nothing was confirmed saved; check the Contacts list before trying again.', true);
    }).then(function () {
      busy = false;
      save.disabled = false;
      save.textContent = 'Save contact';
    });
  });

  if (/[?&]new=1\b/.test(window.location.search)) { open(null); }
})();
