/*
 * Home V2 — action drawer and Ask MotionGrove client. See
 * resources/views/customer/dashboard/home/_drawer.blade.php for the contract.
 */
(function () {
    var root = document.querySelector('[data-role="command-center"]');
    if (!root || root.dataset.homeWired === '1') { return; }
    root.dataset.homeWired = '1';

    // The drawer shell is created here, not served with the page: it is only ever needed
    // once JavaScript and Bootstrap's offcanvas are present, and every trigger is a real link otherwise.
    var drawerEl = document.createElement('div');
    drawerEl.className = 'offcanvas offcanvas-end home-drawer';
    drawerEl.setAttribute('tabindex', '-1');
    drawerEl.setAttribute('aria-labelledby', 'home-drawer-title');
    drawerEl.setAttribute('data-role', 'home-drawer');
    drawerEl.innerHTML =
        '<div class="offcanvas-header border-bottom">' +
        '<h2 class="offcanvas-title h5 mb-0 text-section-heading" id="home-drawer-title" data-role="drawer-title">Action</h2>' +
        '<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close" data-role="drawer-close"></button>' +
        '</div>' +
        '<div class="offcanvas-body" data-role="drawer-body" aria-live="polite"></div>' +
        '<div class="border-top p-1" data-role="drawer-footer" hidden><a href="#" class="text-caption" data-role="drawer-full-link">Open full module</a></div>';
    root.appendChild(drawerEl);
    var bodyEl = drawerEl.querySelector('[data-role="drawer-body"]');
    var titleEl = drawerEl.querySelector('[data-role="drawer-title"]');
    var footerEl = drawerEl.querySelector('[data-role="drawer-footer"]');
    var fullEl = drawerEl.querySelector('[data-role="drawer-full-link"]');
    var opener = null;
    var dirty = false;
    var currentFull = null;

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) { return meta.content; }
        var input = root.querySelector('input[name="_token"]');
        return input ? input.value : '';
    }

    function offcanvas() {
        return window.bootstrap && window.bootstrap.Offcanvas ? window.bootstrap.Offcanvas.getOrCreateInstance(drawerEl) : null;
    }

    function setBody(html) { bodyEl.innerHTML = html; }

    function failureMarkup(full) {
        var p = document.createElement('p');
        p.className = 'mb-0';
        p.textContent = "We couldn't load this just now. ";
        if (full) {
            var a = document.createElement('a');
            a.href = full;
            a.textContent = 'Open the full page instead.';
            p.appendChild(a);
        }
        return p;
    }

    function load(url, full) {
        bodyEl.setAttribute('aria-busy', 'true');
        bodyEl.innerHTML = '<p class="text-muted mb-0" data-role="drawer-loading">Loading…</p>';

        return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
            .then(function (r) { if (!r.ok) { throw new Error('status ' + r.status); } return r.text(); })
            .then(function (html) { setBody(html); })
            .catch(function () { bodyEl.textContent = ''; bodyEl.appendChild(failureMarkup(full)); })
            .then(function () { bodyEl.removeAttribute('aria-busy'); });
    }

    function open(url, title, full, from) {
        var oc = offcanvas();
        if (!oc) { if (full) { window.location.href = full; } return; }

        opener = from || document.activeElement;
        currentFull = full || null;
        titleEl.textContent = title || 'Action';

        if (full) { fullEl.href = full; footerEl.hidden = false; } else { footerEl.hidden = true; }

        oc.show();
        load(url, full);
    }

    drawerEl.addEventListener('hidden.bs.offcanvas', function () {
        bodyEl.innerHTML = '';
        if (dirty) { window.location.reload(); return; }
        if (opener && document.body.contains(opener) && opener.focus) { opener.focus(); }
    });

    // Triggers (delegated): a normal click opens the drawer; a modified click (new tab) keeps the real link.
    root.addEventListener('click', function (e) {
        var t = e.target.closest('[data-home-drawer]');
        if (!t || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }
        if (!offcanvas()) { return; }
        e.preventDefault();
        open(t.getAttribute('data-home-drawer'), t.getAttribute('data-drawer-title') || t.textContent.trim(), t.getAttribute('href'), t);
    });

    function showResult(form, kind, message, link) {
        var box = form.querySelector('[data-role="drawer-result"]');
        if (!box) { box = document.createElement('div'); box.setAttribute('data-role', 'drawer-result'); box.setAttribute('role', kind === 'error' ? 'alert' : 'status'); form.appendChild(box); }
        box.className = 'home-drawer-result mt-1';
        box.setAttribute('data-kind', kind);
        box.textContent = message;
        if (link) {
            box.appendChild(document.createTextNode(' '));
            var a = document.createElement('a');
            a.href = link; a.textContent = 'View it';
            box.appendChild(a);
        }
        return box;
    }

    function errorText(body) {
        if (body && body.errors) {
            var first = Object.keys(body.errors)[0];
            if (first) { return [].concat(body.errors[first])[0]; }
        }
        return (body && body.message) || 'That did not go through. Nothing was changed.';
    }

    // Writes: always the module's own endpoint; success only on its own confirmation.
    bodyEl.addEventListener('submit', function (e) {
        var form = e.target.closest('form[data-home-form]');
        var search = e.target.closest('form[data-home-search]');

        if (search) {
            e.preventDefault();
            var url = new URL(search.getAttribute('data-fragment'), window.location.origin);
            new FormData(search).forEach(function (v, k) { if (k !== '_token' && v !== '') { url.searchParams.set(k, v); } });
            load(url.toString(), currentFull);
            return;
        }

        if (!form) { return; }
        e.preventDefault();

        var kind = form.getAttribute('data-home-form');
        var data = new FormData(form);
        var action = form.getAttribute('action');
        var select = form.querySelector('select[data-action-from]');
        if (select && select.selectedOptions[0]) { action = select.selectedOptions[0].getAttribute('data-url') || action; data.delete(select.name); }
        if (!action) { return; }

        var btn = form.querySelector('[type="submit"]');
        if (btn) { btn.disabled = true; }

        fetch(action, {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token(), 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
        }).then(function (res) {
            var b = res.body || {};

            if (res.ok && b.status === 'success') {
                dirty = true;
                showResult(form, 'success', b.message || 'Done.', kind === 'reply' ? null : b.url);
                if (kind === 'reply') {
                    form.querySelectorAll('textarea, button').forEach(function (n) { n.disabled = true; });
                } else {
                    form.querySelectorAll('input, select, textarea, button').forEach(function (n) { n.disabled = true; });
                }
                return;
            }

            if (res.ok && b.status === 'processing') {
                dirty = true;
                showResult(form, 'success', (b.message || 'This is being sent.') + ' Check the conversation for its final status.');
                return;
            }

            showResult(form, 'error', errorText(b));
            if (btn) { btn.disabled = false; }
        }).catch(function () {
            showResult(form, 'error', "We couldn't reach MotionGrove. Nothing was confirmed — please try again.");
            if (btn) { btn.disabled = false; }
        });
    });

    // ── Ask MotionGrove ─────────────────────────────────────────────────
    var askForm = root.querySelector('[data-role="ask-form"]');
    var askResult = root.querySelector('[data-role="ask-result"]');

    function renderAsk(r, from) {
        askResult.textContent = '';
        askResult.hidden = false;

        var title = document.createElement('p');
        title.className = 'fw-bold mb-25';
        title.textContent = r.title;
        askResult.appendChild(title);

        var msg = document.createElement('p');
        msg.className = 'mb-50';
        msg.textContent = r.message;
        askResult.appendChild(msg);

        if (r.status === 'drawer' && r.drawerUrl) {
            open(r.drawerUrl, r.title, r.url, from);
        } else if (r.status === 'link' && r.url) {
            var a = document.createElement('a');
            a.className = 'btn btn-primary btn-sm';
            a.href = r.url; a.textContent = 'Open';
            askResult.appendChild(a);
        } else if (r.status === 'post' && r.url) {
            // The Advisor can use AI allowance, so it is its own explicit, confirmed submit.
            var f = document.createElement('form');
            f.method = 'POST'; f.action = r.url;
            var t = document.createElement('input'); t.type = 'hidden'; t.name = '_token'; t.value = token(); f.appendChild(t);
            Object.keys(r.post || {}).forEach(function (k) {
                var h = document.createElement('input'); h.type = 'hidden'; h.name = k; h.value = r.post[k]; f.appendChild(h);
            });
            var b = document.createElement('button');
            b.type = 'submit'; b.className = 'btn btn-primary btn-sm'; b.textContent = 'Ask the Advisor';
            f.appendChild(b);
            askResult.appendChild(f);
        }
    }

    function ask(q, from) {
        askResult.hidden = false;
        askResult.textContent = 'Checking…';

        fetch(askForm.getAttribute('data-ask-url'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ q: q })
        }).then(function (r) { if (!r.ok) { throw new Error('status ' + r.status); } return r.json(); })
          .then(function (r) { renderAsk(r, from); })
          .catch(function () { askResult.textContent = "We couldn't process that just now. Nothing was changed."; });
    }

    if (askForm) {
        askForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = askForm.querySelector('[data-role="ask-input"]');
            if (input.value.trim() !== '') { ask(input.value.trim(), input); }
        });

        root.querySelectorAll('[data-role="ask-suggestion"]').forEach(function (chip) {
            chip.addEventListener('click', function () { ask(chip.getAttribute('data-q'), chip); });
        });
    }
})();
