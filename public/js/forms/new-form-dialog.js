/*
 * New form dialog (Forms list) + shared behaviour of the New form card.
 *
 * Progressive enhancement: the card is a real <form> that POSTs to
 * FormsController::store(), which creates a DRAFT form and redirects into the
 * builder. Without this script the `forms.create` page serves the same card.
 * With it, [data-new-form] links open the card as a modal dialog instead:
 *   - focus moves to the name input, Tab/Shift+Tab stay inside, Escape and the
 *     backdrop close it, and focus returns to the control that opened it;
 *   - an empty name is refused inline (aria-invalid + role=alert) before any request;
 *   - while the request is in flight the buttons are disabled and busy, and a second
 *     submit is ignored. A server refusal bounces back to the list with the dialog
 *     re-opened (data-open=1) showing the server's message.
 */
(function () {
    'use strict';

    var form = document.getElementById('mgf-nf-form');
    if (!form) { return; }

    var back = document.getElementById('mgf-nf-back');            // absent on the fallback page
    var dialog = form.closest('.mgf-dlg');
    var nameInput = document.getElementById('mgf-nf-name');
    var err = document.getElementById('mgf-nf-err');
    var submitBtn = form.querySelector('[data-nf-submit]');
    var label = form.querySelector('[data-nf-label]');
    var opener = null;
    var busy = false;

    function showError(msg) {
        err.textContent = msg;
        err.hidden = !msg;
        nameInput.classList.toggle('is-invalid', !!msg);
        if (msg) { nameInput.setAttribute('aria-invalid', 'true'); } else { nameInput.removeAttribute('aria-invalid'); }
    }

    // Selected card styling (CSS :has() covers modern browsers; the class covers the rest).
    function syncCards() {
        [].forEach.call(form.querySelectorAll('.mgf-tpl'), function (c) {
            c.classList.toggle('is-on', c.querySelector('input').checked);
        });
    }
    [].forEach.call(form.querySelectorAll('.mgf-tpl input'), function (r) { r.addEventListener('change', syncCards); });
    syncCards();

    nameInput.addEventListener('input', function () { if (err && !err.hidden) { showError(''); } });

    form.addEventListener('submit', function (e) {
        if (busy) { e.preventDefault(); return; }
        if (nameInput.value.trim() === '') {
            e.preventDefault();
            showError('Give the form a name.');
            nameInput.focus();
            return;
        }
        busy = true;
        submitBtn.classList.add('is-busy');
        submitBtn.setAttribute('aria-busy', 'true');
        submitBtn.disabled = true;
        if (label) { label.textContent = 'Creating…'; }
        [].forEach.call(form.querySelectorAll('[data-nf-close]'), function (c) { c.setAttribute('aria-disabled', 'true'); c.style.pointerEvents = 'none'; });
        // Back/forward cache: re-enable if the browser restores this page from history.
        window.addEventListener('pageshow', function (ev) { if (ev.persisted) { reset(); } }, { once: true });
    });

    function reset() {
        busy = false;
        submitBtn.classList.remove('is-busy');
        submitBtn.removeAttribute('aria-busy');
        submitBtn.disabled = false;
        if (label) { label.textContent = 'Create & open builder'; }
        [].forEach.call(form.querySelectorAll('[data-nf-close]'), function (c) { c.removeAttribute('aria-disabled'); c.style.pointerEvents = ''; });
    }

    if (!back) { return; }   // fallback page: nothing more to do

    function focusables() {
        return [].filter.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'), function (el) {
            return el.offsetParent !== null && el.type !== 'hidden' && !(el.type === 'radio' && !el.checked && form.querySelector('input[name="' + el.name + '"]:checked'));
        });
    }

    function open(from) {
        opener = from || document.activeElement;
        back.hidden = false;
        back.classList.add('is-on');
        document.body.classList.add('mgf-lock');
        nameInput.focus();
    }

    function close() {
        if (busy) { return; }
        back.classList.remove('is-on');
        back.hidden = true;
        document.body.classList.remove('mgf-lock');
        showError('');
        if (opener && document.contains(opener)) { opener.focus(); }
    }

    [].forEach.call(document.querySelectorAll('[data-new-form]'), function (a) {
        a.addEventListener('click', function (e) {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) { return; }   // let "open in new tab" through
            e.preventDefault();
            open(a);
        });
    });
    [].forEach.call(dialog.querySelectorAll('[data-nf-close]'), function (c) {
        c.addEventListener('click', function (e) { e.preventDefault(); close(); });
    });
    back.addEventListener('mousedown', function (e) { if (e.target === back) { close(); } });

    document.addEventListener('keydown', function (e) {
        if (!back.classList.contains('is-on')) { return; }
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') { return; }
        var f = focusables();
        if (!f.length) { return; }
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
    });

    if (back.getAttribute('data-open') === '1') { open(document.querySelector('[data-new-form]')); }
})();
