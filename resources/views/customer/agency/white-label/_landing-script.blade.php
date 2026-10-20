<script>
(function () {
    var root = document.getElementById('wl-site');
    if (!root) { return; }
    var urls = JSON.parse(root.getAttribute('data-urls'));
    var csrf = root.getAttribute('data-csrf');
    var canEdit = root.getAttribute('data-can-edit') === '1';
    var step = parseInt(root.getAttribute('data-step'), 10) || 1;
    var stateEl = document.getElementById('wl-save-state');
    var iframe = document.getElementById('wl-preview');
    var timers = {};

    function setState(text, isError) { if (stateEl) { stateEl.textContent = text; stateEl.className = 'text-caption' + (isError ? ' text-danger' : ''); } }

    function post(url, body) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected response.' }; }).then(function (j) { j.httpStatus = r.status; return j; }); });
    }

    function refreshPreview() {
        if (!iframe) { return; }
        var sel = document.getElementById('wl-preview-page');
        var base = sel ? sel.value : urls.preview;
        iframe.src = base + (base.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
    }

    function applyState(j) {
        if (j.checklist) {
            var ul = document.getElementById('wl-checklist');
            if (ul) {
                ul.innerHTML = '';
                j.checklist.forEach(function (it) {
                    var li = document.createElement('li');
                    li.className = 'd-flex gap-1 mb-1'; li.setAttribute('data-check', it.key); li.setAttribute('data-status', it.status);
                    var mark = document.createElement('span'); mark.setAttribute('aria-hidden', 'true'); mark.textContent = it.status === 'pass' ? '✓' : (it.status === 'warn' ? '!' : '✕');
                    var body = document.createElement('span'); var b = document.createElement('strong'); b.textContent = it.label;
                    var d = document.createElement('span'); d.className = 'text-caption' + (it.status === 'fail' ? ' text-danger' : ''); d.textContent = it.detail;
                    body.appendChild(b); body.appendChild(document.createElement('br')); body.appendChild(d); li.appendChild(mark); li.appendChild(body); ul.appendChild(li);
                });
            }
            var perStep = {};
            j.checklist.forEach(function (it) { var cur = perStep[it.step]; if (it.status === 'fail' || (it.status === 'warn' && cur !== 'fail')) { perStep[it.step] = it.status; } else if (!cur) { perStep[it.step] = 'pass'; } });
            document.querySelectorAll('[data-step-button]').forEach(function (btn) {
                var n = btn.getAttribute('data-step-button'); var dot = btn.querySelector('[data-dot]'); if (dot) { dot.remove(); }
                if (perStep[n] === 'fail' || perStep[n] === 'warn') {
                    var s = document.createElement('span'); s.setAttribute('data-dot', ''); s.className = perStep[n] === 'fail' ? 'text-danger' : 'text-warning'; s.textContent = ' ●'; btn.appendChild(s);
                }
            });
        }
        if (j.status) {
            var badge = document.querySelector('[data-role="site-status"]');
            if (badge) { badge.textContent = j.isLive ? (j.unpublishedChanges ? 'Published · unpublished changes' : 'Published') : (j.status === 'unpublished' ? 'Unpublished' : 'Draft'); }
        }
        var publish = document.getElementById('wl-publish'); if (publish && typeof j.isLive !== 'undefined') { publish.textContent = j.isLive ? 'Update website' : 'Publish website'; }
    }

    // One queue for every save: requests apply in the order they were made, what counts as "saved" is what was
    // SUBMITTED, and every save resolves {ok, message} (never rejects). See public/js/agency-site-saver.js.
    var saver = AgencySiteSaver.create({
        send: function (body) { return post(urls.save, body).then(function (j) { return { status: j.httpStatus, body: j }; }); }
    });

    function save(answers, opts) {
        if (!canEdit) { return Promise.resolve({ ok: true }); }
        setState('Saving…');
        return saver.save(answers, { step: step }).then(function (r) {
            if (r.ok) {
                if (saver.pending() === 0) { setState('All changes saved'); }
                applyState(r.body); refreshPreview();
                if (opts && opts.after) { opts.after(r.body); }
            } else { setState(r.message || 'Could not save', true); }
            return r;
        });
    }

    // ---------- reading fields
    function cleaned(v) { v = (v || '').trim(); return v === '' ? null : v; }
    function readField(el) {
        var type = el.getAttribute('data-type');
        if (type === 'strings') {
            return Array.prototype.map.call(el.querySelectorAll('[data-item] [data-cell]'), function (i) { return i.value; });
        }
        if (type === 'rows') {
            return Array.prototype.map.call(el.querySelectorAll('[data-item]'), function (item) {
                var row = {}; item.querySelectorAll('[data-cell]').forEach(function (c) { row[c.getAttribute('data-cell')] = c.value; }); return row;
            });
        }
        var input = el.querySelector('[data-input]'); return input ? cleaned(input.value) : null;
    }
    // Only answers the Agency actually changed are sent, so untouched fields keep following the defaults.
    document.querySelectorAll('[data-field]').forEach(function (f) { saver.remember(f.getAttribute('data-path'), readField(f)); });
    function collectFields(scope) {
        var all = {};
        scope.querySelectorAll('[data-field]').forEach(function (f) { all[f.getAttribute('data-path')] = readField(f); });
        return saver.changed(all);
    }

    // ---------- generic list controls (strings / rows)
    function makeItem(list) {
        var kind = list.getAttribute('data-list');
        var item = document.createElement('div'); item.setAttribute('data-item', '');
        if (kind === 'strings') {
            item.className = 'input-group mb-1';
            var inp = document.createElement('input'); inp.className = 'form-control'; inp.type = 'text'; inp.maxLength = parseInt(list.getAttribute('data-length'), 10) || 120; inp.setAttribute('data-cell', '');
            var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'btn btn-outline-secondary'; rm.setAttribute('data-remove', ''); rm.textContent = '×'; rm.setAttribute('aria-label', 'Remove');
            item.appendChild(inp); item.appendChild(rm); return item;
        }
        item.className = 'border rounded p-2 mb-1';
        var cols = JSON.parse(list.getAttribute('data-columns')); var labels = JSON.parse(list.getAttribute('data-labels'));
        cols.forEach(function (c, i) {
            var l = document.createElement('label'); l.className = 'small text-muted mb-0'; l.textContent = labels[i];
            var t = (parseInt(list.getAttribute('data-length'), 10) > 160 && i > 0) ? 'textarea' : 'input';
            var f = document.createElement(t); f.className = 'form-control form-control-sm mb-1'; if (t === 'textarea') { f.rows = 2; } else { f.type = 'text'; }
            f.maxLength = parseInt(list.getAttribute('data-length'), 10) || 400; f.setAttribute('data-cell', c);
            item.appendChild(l); item.appendChild(f);
        });
        var bar = document.createElement('div'); bar.className = 'd-flex gap-1';
        [['data-up', '↑', 'btn-outline-secondary', 'Move up'], ['data-down', '↓', 'btn-outline-secondary', 'Move down'], ['data-remove', 'Remove', 'btn-outline-danger', null]].forEach(function (b) {
            var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'btn btn-sm ' + b[2]; btn.setAttribute(b[0], ''); btn.textContent = b[1]; if (b[3]) { btn.setAttribute('aria-label', b[3]); } bar.appendChild(btn);
        });
        item.appendChild(bar); return item;
    }

    function move(item, dir) {
        var sib = dir < 0 ? item.previousElementSibling : item.nextElementSibling;
        if (!sib) { return false; }
        if (dir < 0) { item.parentNode.insertBefore(item, sib); } else { item.parentNode.insertBefore(sib, item); }
        return true;
    }

    // ---------- saving a scope
    // Whole-list answers are sent only when they differ from what the server will hold.
    function saveChanged(answers) { var c = saver.changed(answers); return Object.keys(c).length ? save(c) : Promise.resolve({ ok: true }); }
    function saveScope(scope) {
        var kind = scope.getAttribute('data-scope');
        if (kind === 'fields') { var changed = collectFields(scope); return Object.keys(changed).length ? save(changed) : Promise.resolve({ ok: true }); }
        if (kind === 'home') {
            var order = [], hidden = [], shown = [];
            scope.querySelectorAll(':scope > li').forEach(function (li) {
                var key = li.getAttribute('data-key'); if (li.getAttribute('data-movable') === '1') { order.push(key); }
                if (li.getAttribute('data-hideable') !== '1') { return; }
                var on = li.querySelector('[data-toggle-visible]').checked, defHidden = li.getAttribute('data-default-hidden') === '1';
                if (defHidden && on) { shown.push(key); } else if (!defHidden && !on) { hidden.push(key); }
            });
            return saveChanged({ 'x.home_order': order, 'x.home_hidden': hidden, 'x.home_shown': shown });
        }
        if (kind === 'product') {
            var porder = [], phidden = [], featured = null;
            scope.querySelectorAll(':scope > li').forEach(function (li) {
                var key = li.getAttribute('data-key'); porder.push(key);
                if (!li.querySelector('[data-toggle-visible]').checked) { phidden.push(key); }
                if (li.querySelector('[data-featured]').checked) { featured = key; }
            });
            var answers = collectFields(scope); answers['x.product_order'] = porder; answers['x.product_hidden'] = phidden; answers['x.featured_feature'] = featured;
            return saveChanged(answers);
        }
        if (kind === 'testimonials') {
            return saveChanged({ 'x.testimonials': Array.prototype.map.call(scope.querySelectorAll('[data-testimonial]'), function (t) {
                var row = {}; t.querySelectorAll('[data-t]').forEach(function (i) { row[i.getAttribute('data-t')] = i.type === 'checkbox' ? i.checked : i.value; }); return row;
            }) });
        }
        if (kind === 'plans') {
            return saveChanged({ 'x.plans': Array.prototype.map.call(scope.querySelectorAll('[data-plan]'), function (p) {
                return { uid: p.getAttribute('data-plan'), visible: p.querySelector('[data-plan-visible]').checked, featured: p.querySelector('[data-plan-featured]').checked,
                    badge: cleaned(p.querySelector('[data-plan-badge]').value), cta: cleaned(p.querySelector('[data-plan-cta]').value), tagline: cleaned(p.querySelector('[data-plan-tagline]').value),
                    features: p.querySelector('[data-plan-features]').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean) };
            }) });
        }
        if (kind === 'pages-hidden') {
            var hid = []; scope.querySelectorAll('[data-page-toggle]').forEach(function (c) { if (!c.checked) { hid.push(c.getAttribute('data-page-toggle')); } });
            return saveChanged({ 'x.pages_hidden': hid });
        }
        return Promise.resolve({ ok: true });
    }
    function scopeOf(el) { return el.closest('[data-scope]'); }
    function schedule(scope) {
        var id = scope.getAttribute('data-scope') + (scope.getAttribute('data-group') || '');
        clearTimeout(timers[id]); setState('Unsaved changes…');
        timers[id] = setTimeout(function () { delete timers[id]; saveScope(scope); }, 700);
    }
    function flushAll() { Object.keys(timers).forEach(function (k) { clearTimeout(timers[k]); delete timers[k]; }); }

    /**
     * Save every pending edit NOW and report whether ALL of it was persisted: {ok, message}. Waits for the queue, so a
     * result of ok means the server holds what is on screen. A failure leaves the edits in place and still unsaved.
     */
    function saveEverything() {
        flushAll();
        var scopes = []; document.querySelectorAll('[data-scope]').forEach(function (s) { if (s.getAttribute('data-scope') !== 'booking') { scopes.push(s); } });
        return Promise.all(scopes.map(saveScope)).then(function (results) {
            return saver.idle().then(function () {
                var failed = results.filter(function (r) { return !r || r.ok !== true; })[0];
                return failed ? { ok: false, message: failed.message || saver.lastFailure() || 'Could not save your changes.' } : { ok: true };
            });
        });
    }

    root.addEventListener('input', function (ev) { var s = scopeOf(ev.target); if (s && canEdit && s.getAttribute('data-scope') !== 'booking') { schedule(s); } });
    root.addEventListener('change', function (ev) {
        var t = ev.target, s = scopeOf(t); if (!s || !canEdit) { return; }
        if (s.getAttribute('data-scope') === 'booking' && t.hasAttribute('data-booking-option')) { save({ 'x.booking_type_uid': t.value }, { after: function (j) { renderBooking(j.booking); } }); return; }
        schedule(s);
    });

    root.addEventListener('click', function (ev) {
        var t = ev.target.closest('button, a'); if (!t) { return; }
        var item = t.closest('[data-item], [data-testimonial], [data-plan], [data-home-list] > li, [data-product-list] > li');
        if (t.hasAttribute('data-remove') && item) { var s = scopeOf(item); item.remove(); if (s) { schedule(s); } return; }
        if ((t.hasAttribute('data-up') || t.hasAttribute('data-down')) && item) { var sc = scopeOf(item); if (move(item, t.hasAttribute('data-up') ? -1 : 1) && sc) { schedule(sc); } return; }
        if (t.hasAttribute('data-add')) {
            var list = t.parentNode.querySelector('[data-list="' + t.getAttribute('data-add') + '"]');
            if (list.querySelectorAll('[data-item]').length < parseInt(list.getAttribute('data-max'), 10)) { var it = makeItem(list); list.appendChild(it); var f = it.querySelector('[data-cell]'); if (f) { f.focus(); } }
            return;
        }
        if (t.hasAttribute('data-add-testimonial')) {
            var tpl = document.getElementById('wl-testimonial-template'); var wrap = document.querySelector('[data-testimonials]');
            wrap.appendChild(tpl.content.firstElementChild.cloneNode(true)); return;
        }
        if (t.hasAttribute('data-open-section')) { var body = t.closest('li').querySelector('[data-section-body]'); if (body) { body.hidden = !body.hidden; } return; }
        if (t.hasAttribute('data-reset-group')) {
            if (!confirm('Return this section to its default content?')) { return; }
            post(urls.reset, { group: t.getAttribute('data-reset-group') }).then(function (j) { if (j.ok) { setState('Section reset'); window.location.reload(); } else { setState(j.message || 'Could not reset', true); } });
            return;
        }
        if (t.hasAttribute('data-reset-field')) {
            var field = t.closest('[data-field]'); var o = {}; o[field.getAttribute('data-path')] = null;
            save(o, { after: function () { window.location.reload(); } }); return;
        }
        if (t.hasAttribute('data-step-button')) { go(parseInt(t.getAttribute('data-step-button'), 10)); return; }
        if (t.hasAttribute('data-step-prev')) { go(step - 1); return; }
        if (t.hasAttribute('data-step-next')) { go(step + 1); return; }
        if (t.hasAttribute('data-device')) {
            var mobile = t.getAttribute('data-device') === 'mobile'; iframe.style.width = mobile ? '390px' : '100%';
            document.querySelectorAll('[data-device]').forEach(function (b) { b.className = 'btn ' + (b === t ? 'btn-primary' : 'btn-outline-secondary'); }); return;
        }
        if (t.hasAttribute('data-mobile-tab')) { setPane(t.getAttribute('data-mobile-tab')); return; }
        if (t.id === 'wl-save-draft') {
            setState('Saving…');
            saveEverything().then(function (r) { if (r.ok) { setState('Draft saved'); } else { setState('Not saved — ' + r.message + ' Your edits are still on this page.', true); } });
            return;
        }
        if (t.id === 'wl-publish') { publish(); return; }
        if (t.id === 'wl-unpublish') { if (confirm('Take your website offline?')) { post(urls.unpublish, {}).then(function (j) { if (j.ok) { applyState(j); setState('Website unpublished'); } else { setState(j.message, true); } }); } return; }
    });

    // ---------- steps
    function go(n) {
        if (n < 1 || n > 10) { return; }
        step = n;
        document.querySelectorAll('[data-step-panel]').forEach(function (p) { p.hidden = parseInt(p.getAttribute('data-step-panel'), 10) !== n; });
        document.querySelectorAll('[data-step-button]').forEach(function (b) {
            var on = parseInt(b.getAttribute('data-step-button'), 10) === n; b.classList.toggle('btn-primary', on); b.classList.toggle('btn-outline-secondary', !on); if (on) { b.setAttribute('aria-current', 'step'); } else { b.removeAttribute('aria-current'); }
        });
        var pageForStep = { 2: 'home', 3: 'product', 4: 'solutions', 5: 'how-it-works', 6: 'customers', 7: 'pricing' }[n];
        var sel = document.getElementById('wl-preview-page');
        if (pageForStep && sel) { Array.prototype.forEach.call(sel.options, function (o) { if (o.value.split('?')[0].split('/').pop() === pageForStep) { sel.value = o.value; } }); refreshPreview(); }
        if (canEdit) { post(urls.step, { step: n }); }
        window.scrollTo({ top: root.offsetTop - 10, behavior: 'smooth' });
    }
    var sel = document.getElementById('wl-preview-page'); if (sel) { sel.addEventListener('change', refreshPreview); }

    function setPane(which) {
        document.querySelectorAll('[data-pane]').forEach(function (p) {
            var pane = p.getAttribute('data-pane'); var show = pane === which || window.innerWidth >= 992;
            p.style.display = show ? '' : 'none';
        });
    }
    window.addEventListener('resize', function () { setPane(document.querySelector('[data-mobile-tab].btn-primary') ? document.querySelector('[data-mobile-tab].btn-primary').getAttribute('data-mobile-tab') : 'edit'); });
    document.querySelectorAll('[data-mobile-tab]').forEach(function (b) { b.addEventListener('click', function () { document.querySelectorAll('[data-mobile-tab]').forEach(function (x) { x.className = 'btn btn-sm ' + (x === b ? 'btn-primary' : 'btn-outline-secondary'); }); }); });
    setPane('edit');

    // ---------- booking
    function renderBooking(b) {
        var box = document.getElementById('wl-booking-status'); if (!box || !b) { return; }
        box.textContent = '';
        var p = document.createElement('p');
        if (!b.selected) { p.className = 'text-warning'; p.textContent = 'No booking type selected. Booking buttons are not shown on your site.'; }
        else if (b.readiness.ready) { p.className = 'text-success'; p.setAttribute('data-role', 'booking-ready'); p.textContent = 'Visitors book into your calendar at ' + b.url; }
        else { p.className = 'text-danger'; p.setAttribute('data-role', 'booking-invalid'); p.textContent = "The selected booking type can't take bookings: " + b.readiness.reason + ' Booking buttons are hidden until it is fixed.'; }
        box.appendChild(p);
    }
    var bform = document.getElementById('wl-booking-form');
    if (bform) {
        bform.addEventListener('submit', function (ev) {
            ev.preventDefault(); var body = {}; new FormData(bform).forEach(function (v, k) { body[k] = v; }); body.default_hours = bform.elements.default_hours.checked ? 1 : 0;
            setState('Creating booking type…');
            post(urls.booking, body).then(function (j) { if (j.ok) { setState('Booking type created and selected'); window.location.reload(); } else { setState(j.message || 'Could not create the booking type', true); } });
        });
    }

    // ---------- publish
    function showPublishResult(ok, heading, items) {
        var result = document.getElementById('wl-publish-result');
        result.style.display = ''; result.textContent = ''; result.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
        var h = document.createElement('strong'); h.textContent = heading; result.appendChild(h);
        if (items && items.length) { var ul = document.createElement('ul'); ul.className = 'mb-0'; items.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); }); result.appendChild(ul); }
    }
    function publish() {
        document.getElementById('wl-publish-result').style.display = 'none';
        setState('Saving…');
        // Publish only what is provably saved: every pending save must succeed first.
        saveEverything().then(function (saved) {
            if (!saved.ok) {
                setState('Not saved — nothing was published.', true);
                showPublishResult(false, 'Not published: your latest edits could not be saved.', [saved.message, 'Your edits are still on this page. Fix the problem and try again.']);
                return null;
            }
            return post(urls.publish, {}).then(function (j) {
                if (j.ok) { showPublishResult(true, 'Your website is published at ' + j.hostedUrl); applyState(j); setState('Published'); }
                else { showPublishResult(false, j.message || 'Could not publish', (j.blockers || []).map(function (b) { return b.label + ': ' + b.detail; })); if (j.blockers) { go(10); } }
            }, function () { showPublishResult(false, 'Could not publish', ['Could not reach the server.']); });
        });
    }
    window.addEventListener('beforeunload', function (ev) { if (saver.pending() > 0 || Object.keys(timers).length > 0) { ev.preventDefault(); ev.returnValue = ''; } });
})();
</script>
