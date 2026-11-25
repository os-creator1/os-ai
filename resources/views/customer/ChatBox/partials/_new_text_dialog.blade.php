{{--
    "New text" dialog. Visual spec: the "Conversations — new text popup" design export. It opens from the
    Conversations launch controls ([data-new-text-open]); each keeps its href, so without JavaScript (or for a
    Business whose legacy transport needs a sender / server choice) it still goes to the full compose page.

    Nothing here is a second send path: it loads the compose context from `conversations.new`, finds contacts through
    `conversations.recipients`, and posts to the same `conversations.sent` action the full page uses (Accept: JSON), so
    recipient normalisation, opt-out / blacklist checks, spam-word rules, idempotency and metering are the existing ones.
--}}
<style>
    .ntx-modal { --ntx-brand: #B4514A; --ntx-brand-d: #8F3B35; --ntx-ink: #1F1A19; --ntx-mute: #5E5652; --ntx-mute2: #6B625E; --ntx-faint: #8A817C; --ntx-line2: #F0EBE6; --ntx-soft: #FBF9F7; background: rgba(31, 26, 25, .38); font-family: inherit; }
    .ntx-modal .modal-dialog { width: 560px; max-width: calc(100% - 32px); margin: 0 auto; padding-top: 96px; }
    .ntx-modal.modal.fade .modal-dialog { transform: none; }
    .ntx-card { pointer-events: auto; margin: 0; border-radius: 18px; background: #fff; box-shadow: 0 24px 64px rgba(31, 26, 25, .28); color: var(--ntx-ink); font-family: inherit; }
    .ntx-ic { width: 16px; height: 16px; flex: none; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .ntx-head { display: flex; align-items: flex-start; gap: 12px; padding: 22px 24px 4px; }
    .ntx-head h2 { flex: 1; margin: 0; font-size: 20px; font-weight: 800; color: var(--ntx-ink); }
    .ntx-close { width: 36px; height: 36px; border: 0; border-radius: 10px; background: #F4F0EC; color: #4A4340; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
    .ntx-close:hover { background: #ECE6E0; }
    .ntx-body { padding: 14px 24px 20px; display: flex; flex-direction: column; gap: 14px; }
    .ntx-row { display: flex; align-items: center; gap: 12px; min-height: 44px; border-bottom: 1px solid var(--ntx-line2); }
    .ntx-row > .ntx-lbl { width: 44px; font-size: 13px; color: var(--ntx-faint); }
    .ntx-from { display: inline-flex; align-items: center; gap: 8px; font-size: 14.5px; font-weight: 600; }
    .ntx-from .ntx-ic { width: 15px; height: 15px; color: var(--ntx-brand-d); }
    .ntx-row .ntx-note { font-size: 12.5px; color: var(--ntx-faint); }
    .ntx-to-wrap { position: relative; }
    .ntx-to { display: flex; align-items: center; gap: 12px; min-height: 48px; border-bottom: 1px solid var(--ntx-line2); }
    .ntx-to:focus-within { border-bottom: 2px solid var(--ntx-brand); }
    .ntx-to > .ntx-lbl { width: 44px; font-size: 13px; color: var(--ntx-faint); }
    .ntx-cc { position: relative; display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 8px; border: 1px solid #E0DAD4; border-radius: 8px; background: var(--ntx-soft); font: inherit; font-size: 13.5px; font-weight: 600; color: var(--ntx-ink); }
    .ntx-cc .ntx-flag { width: 20px; height: 14px; border-radius: 2px; background: #2F5A8C; color: #fff; font-size: 8px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
    .ntx-cc .ntx-ic { width: 12px; height: 12px; color: var(--ntx-faint); }
    .ntx-to input { flex: 1; min-width: 0; height: 46px; border: 0; outline: 0; background: transparent; padding: 0; font: inherit; font-size: 15px; color: var(--ntx-ink); }
    .ntx-to input::placeholder { color: #B5ADA7; }
    .ntx-to .ntx-picked { font-size: 12.5px; color: var(--ntx-mute2); white-space: nowrap; }
    .ntx-pop { position: absolute; padding: 6px; border: 1px solid #E9E4DF; border-radius: 12px; background: #fff; box-shadow: 0 12px 28px rgba(31, 26, 25, .12); z-index: 5; }
    .ntx-sugg { left: 56px; right: 0; top: 54px; }
    .ntx-ccmenu { left: 56px; top: 44px; min-width: 220px; max-height: 240px; overflow-y: auto; }
    .ntx-opt { display: flex; align-items: center; gap: 10px; min-height: 46px; padding: 0 10px; border-radius: 9px; cursor: pointer; font-size: 13.5px; }
    .ntx-opt.is-active { background: #FBF0EE; }
    .ntx-opt[aria-disabled="true"] { cursor: not-allowed; opacity: .6; }
    .ntx-av { width: 30px; height: 30px; flex: none; border-radius: 50%; background: #E3F3EA; color: #17693F; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .ntx-opt > div { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .ntx-opt b { font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ntx-opt b u { text-decoration: underline; }
    .ntx-opt small { font-size: 12px; color: var(--ntx-mute2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ntx-opt.is-type { min-height: 42px; color: var(--ntx-brand-d); font-weight: 600; }
    .ntx-opt.is-type .ntx-ic { width: 14px; height: 14px; }
    .ntx-gap { height: 96px; }
    .ntx-composer { position: relative; border: 1px solid #D6CFC8; border-radius: 12px; }
    .ntx-composer:focus-within { border-color: var(--ntx-brand); box-shadow: 0 0 0 3px #F8E9E6; }
    .ntx-composer textarea { display: block; width: 100%; min-height: 96px; padding: 12px 14px; border: 0; outline: 0; resize: none; border-radius: 12px 12px 0 0; font: inherit; font-size: 15px; line-height: 1.5; color: var(--ntx-ink); background: #fff; }
    .ntx-composer textarea::placeholder { color: #A39A94; }
    .ntx-tools { display: flex; align-items: center; gap: 4px; padding: 6px 8px; border-top: 1px solid var(--ntx-line2); background: var(--ntx-soft); border-radius: 0 0 12px 12px; }
    .ntx-tpl { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 10px; border: 0; border-radius: 8px; background: transparent; font: inherit; font-size: 13px; font-weight: 600; color: #4A4340; }
    .ntx-tpl:hover { background: #F4F0EC; }
    .ntx-tpl .ntx-ic { width: 15px; height: 15px; color: var(--ntx-brand-d); }
    .ntx-tpl .ntx-ic:last-child { width: 12px; height: 12px; color: var(--ntx-faint); }
    .ntx-tools .ntx-sp { flex: 1; }
    .ntx-count { font-size: 12px; color: var(--ntx-faint); }
    .ntx-tplmenu { left: 8px; bottom: 44px; min-width: 240px; max-height: 220px; overflow-y: auto; }
    .ntx-hint { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--ntx-mute2); }
    .ntx-hint .ntx-ic { width: 14px; height: 14px; color: var(--ntx-faint); }
    .ntx-error { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border-radius: 10px; background: #FCEBEA; color: #8C1D18; font-size: 13px; }
    .ntx-error .ntx-ic { width: 15px; height: 15px; margin-top: 1px; }
    .ntx-foot { display: flex; align-items: center; gap: 10px; padding: 14px 24px; border-top: 1px solid var(--ntx-line2); background: var(--ntx-soft); border-radius: 0 0 18px 18px; }
    .ntx-foot > span { flex: 1; font-size: 12.5px; color: var(--ntx-mute2); }
    .ntx-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; box-sizing: border-box; padding: 0 16px; border-radius: 10px; border: 0; font-size: 14px; font-weight: 600; white-space: nowrap; background: transparent; color: var(--ntx-mute); }
    .ntx-btn:hover { background: #F4F0EC; }
    .ntx-btn.is-primary { background: var(--ntx-brand); color: #fff; padding: 0 20px; }
    .ntx-btn.is-primary:hover { background: var(--ntx-brand-d); }
    .ntx-btn.is-primary[disabled] { opacity: .55; cursor: not-allowed; background: var(--ntx-brand); }
    .ntx-btn[disabled] { cursor: not-allowed; }
    .ntx-btn .ntx-ic { width: 16px; height: 16px; }
    @media (max-width: 575.98px) {
        .ntx-modal .modal-dialog { padding-top: 40px; }
        .ntx-head, .ntx-body, .ntx-foot { padding-left: 16px; padding-right: 16px; }
        .ntx-sugg { left: 0; }
        .ntx-row { flex-wrap: wrap; row-gap: 2px; padding: 6px 0; }
        .ntx-foot { flex-wrap: wrap; }
        .ntx-foot > span { flex-basis: 100%; }
        .ntx-foot .ntx-btn { flex: 1; }
    }
</style>
<div class="modal ntx-modal" id="new-text-dialog" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="new-text-title" aria-hidden="true" data-bs-backdrop="false"
     data-context-url="{{ route('customer.workspaces.businesses.conversations.new', [$workspaceUid, $businessUid]) }}"
     data-recipients-url="{{ route('customer.workspaces.businesses.conversations.recipients', [$workspaceUid, $businessUid]) }}"
     data-sent-url="{{ route('customer.workspaces.businesses.conversations.sent', [$workspaceUid, $businessUid]) }}"
     data-template-url="{{ route('customer.workspaces.businesses.outreach.templates.show_data', [$workspaceUid, $businessUid, '__ID__']) }}"
     data-index-url="{{ route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) }}"
     data-role="new-text-dialog">
    <div class="modal-dialog">
        <form class="ntx-card" data-role="new-text-form" novalidate autocomplete="off">
            <div class="ntx-head">
                <h2 id="new-text-title">New text</h2>
                <button type="button" class="ntx-close" data-role="new-text-close" aria-label="Close"><svg class="ntx-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg></button>
            </div>
            <div class="ntx-body">
                <div class="ntx-row">
                    <span class="ntx-lbl">From</span>
                    <span class="ntx-from"><svg class="ntx-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"></path></svg><span data-role="new-text-from">Loading…</span></span>
                    <span class="ntx-note">Your texting number</span>
                </div>
                <div class="ntx-to-wrap">
                    <div class="ntx-to">
                        <label class="ntx-lbl" for="new-text-to">To</label>
                        <button type="button" class="ntx-cc" data-role="new-text-country" aria-haspopup="listbox" aria-expanded="false" aria-label="Country code"><span class="ntx-flag" data-role="new-text-flag">US</span><span data-role="new-text-dial">+1</span><svg class="ntx-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"></path></svg></button>
                        <input type="text" id="new-text-to" data-role="new-text-to" placeholder="Name or phone number" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="new-text-suggestions" maxlength="100" autocomplete="off">
                        <span class="ntx-picked" data-role="new-text-picked" hidden></span>
                    </div>
                    <div class="ntx-pop ntx-ccmenu" role="listbox" aria-label="Country code" data-role="new-text-country-menu" hidden></div>
                    <div class="ntx-pop ntx-sugg" id="new-text-suggestions" role="listbox" aria-label="Recipients" data-role="new-text-suggestions" hidden></div>
                </div>
                <div class="ntx-gap" aria-hidden="true"></div>
                <div class="ntx-composer">
                    <textarea data-role="new-text-message" rows="4" placeholder="Write your message…" aria-label="Message"></textarea>
                    <div class="ntx-tools">
                        <button type="button" class="ntx-tpl" data-role="new-text-template" aria-haspopup="listbox" aria-expanded="false"><svg class="ntx-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h10l4 4v12H5zM9 12h6M9 16h4"></path></svg>Use a template<svg class="ntx-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"></path></svg></button>
                        <span class="ntx-sp"></span>
                        <span class="ntx-count" data-role="new-text-count" aria-live="polite">0 / 160 · 1 text</span>
                    </div>
                    <div class="ntx-pop ntx-tplmenu" role="listbox" aria-label="Templates" data-role="new-text-template-menu" hidden></div>
                </div>
                <span class="ntx-hint"><svg class="ntx-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.500"></circle><path d="M12 11v5M12 8h.01"></path></svg>Templates are pasted as plain text — tags like @{{first name}} aren't filled in here.</span>
                <div class="ntx-error" role="alert" data-role="new-text-error" hidden><svg class="ntx-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.500"></circle><path d="M12 7.500v5M12 16h.01"></path></svg><span data-role="new-text-error-text"></span></div>
            </div>
            <div class="ntx-foot">
                <span>Opens in Conversations after sending.</span>
                <button type="button" class="ntx-btn" data-role="new-text-cancel">Cancel</button>
                <button type="submit" class="ntx-btn is-primary" data-role="new-text-send" disabled><svg class="ntx-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l16-8-6 16-2.500-6.500z"></path></svg>Send</button>
            </div>
        </form>
    </div>
</div>
<script src="{{ asset(mix('js/scripts/sms-counter.js')) }}"></script>
<script>
(function () {
    var root = document.getElementById('new-text-dialog');
    if (!root) { return; }
    var $ = function (role) { return root.querySelector('[data-role="' + role + '"]'); };
    var form = $('new-text-form'), toInput = $('new-text-to'), msg = $('new-text-message'), sendBtn = $('new-text-send');
    var sugg = $('new-text-suggestions'), ccBtn = $('new-text-country'), ccMenu = $('new-text-country-menu');
    var tplBtn = $('new-text-template'), tplMenu = $('new-text-template-menu'), picked = $('new-text-picked');
    var errBox = $('new-text-error'), errText = $('new-text-error-text');
    var csrf = '{{ csrf_token() }}';

    var ctx = null;            // compose context from the server; null until loaded
    var countryId = null;      // destination Country id (a formatting helper, as on the full page)
    var recipient = null;      // { contact: uid|null, name, national, countryId } once a contact is picked
    var results = [];          // contact suggestions currently listed
    var active = -1;
    var token = '';
    var inFlight = false;
    var opener = null;
    var searchSeq = 0, searchTimer = null;
    var launchHref = null;

    function modal() { return window.bootstrap.Modal.getOrCreateInstance(root); }
    function uuid() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
    }
    function countryById(id) { return ctx ? ctx.countries.filter(function (c) { return c.id === id; })[0] || null : null; }
    function digits(s) { return String(s || '').replace(/\D+/g, ''); }
    function showError(text) { errText.textContent = text || ''; errBox.hidden = !text; }
    function setBusy(on) {
        inFlight = on;
        sendBtn.setAttribute('aria-busy', on ? 'true' : 'false');
        [$('new-text-cancel'), $('new-text-close')].forEach(function (b) { b.disabled = on; });
        refresh();
    }

    // ---- send requirements: the existing ones (a recipient and a message), plus a usable context ----
    function typedNumber() { return recipient ? recipient.national : digits(toInput.value); }
    function ready() { return !!ctx && !!ctx.from && !!countryId && typedNumber().length >= 4 && msg.value.trim() !== ''; }
    function refresh() { sendBtn.disabled = inFlight || !ready(); updateCount(); }
    function updateCount() {
        var text = msg.value, n = { length: 0, per_message: 160, messages: 0 };
        if (window.SmsCounter) { n = SmsCounter.count(text.replace(/[\r\n]+/g, ''), true); }
        var cap = n.per_message * Math.max(1, n.messages);
        $('new-text-count').textContent = n.length + ' / ' + cap + ' · ' + Math.max(1, n.messages) + (Math.max(1, n.messages) === 1 ? ' text' : ' texts');
    }

    // ---- country picker ----
    function renderCountry() {
        var c = countryById(countryId);
        $('new-text-flag').textContent = c ? c.iso : '';
        $('new-text-dial').textContent = c ? '+' + c.dial : '+';
    }
    function closeCountry() { ccMenu.hidden = true; ccBtn.setAttribute('aria-expanded', 'false'); }
    ccBtn.addEventListener('click', function () {
        if (!ctx || recipient) { return; }
        if (!ccMenu.hidden) { closeCountry(); return; }
        closeSuggestions(); closeTemplates();
        ccMenu.innerHTML = '';
        ctx.countries.forEach(function (c) {
            var o = document.createElement('div');
            o.className = 'ntx-opt' + (c.id === countryId ? ' is-active' : '');
            o.setAttribute('role', 'option'); o.tabIndex = 0;
            o.textContent = c.iso + ' · ' + c.name + ' (+' + c.dial + ')';
            function choose() { countryId = c.id; renderCountry(); closeCountry(); ccBtn.focus(); refresh(); }
            o.addEventListener('click', choose);
            o.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(); } });
            ccMenu.appendChild(o);
        });
        ccMenu.hidden = false; ccBtn.setAttribute('aria-expanded', 'true');
        var cur = ccMenu.querySelector('.is-active'); if (cur) { cur.focus(); }
    });
    ccMenu.addEventListener('keydown', function (e) {
        var opts = Array.prototype.slice.call(ccMenu.children), i = opts.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); (opts[i + 1] || opts[0]).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); (opts[i - 1] || opts[opts.length - 1]).focus(); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeCountry(); ccBtn.focus(); }
    });

    // ---- recipient: a picked contact, or a number typed directly ----
    function initials(name) { var p = String(name || '').trim().split(/\s+/); return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase(); }
    function underline(text, q) {
        var b = document.createElement('b'), at = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1;
        if (at === 0) { var u = document.createElement('u'); u.textContent = text.slice(0, q.length); b.appendChild(u); b.appendChild(document.createTextNode(text.slice(q.length))); }
        else { b.textContent = text; }
        return b;
    }
    function closeSuggestions() { sugg.hidden = true; sugg.innerHTML = ''; results = []; active = -1; toInput.setAttribute('aria-expanded', 'false'); toInput.removeAttribute('aria-activedescendant'); }
    function setActive(i) {
        var opts = sugg.querySelectorAll('.ntx-opt:not([aria-disabled="true"])');
        if (!opts.length) { return; }
        active = (i + opts.length) % opts.length;
        sugg.querySelectorAll('.ntx-opt').forEach(function (o) { o.classList.remove('is-active'); o.setAttribute('aria-selected', 'false'); });
        opts[active].classList.add('is-active'); opts[active].setAttribute('aria-selected', 'true');
        toInput.setAttribute('aria-activedescendant', opts[active].id);
    }
    function pickContact(c) {
        if (!c.can_text) { return; }
        searchSeq++; clearTimeout(searchTimer); // a response still in flight must not reopen the list
        recipient = { contact: c.uid, name: c.name || c.phone, national: c.national, countryId: c.country_id };
        countryId = c.country_id; renderCountry();
        toInput.value = recipient.name; picked.textContent = c.phone; picked.hidden = false;
        closeSuggestions(); showError(''); refresh();
        msg.focus(); // the typed message is never touched by choosing a recipient
    }
    function typeNumberMode() {
        recipient = null; picked.hidden = true; toInput.value = ''; toInput.placeholder = 'Phone number'; toInput.setAttribute('inputmode', 'tel');
        closeSuggestions(); refresh(); toInput.focus();
    }
    function renderSuggestions(q, list) {
        sugg.innerHTML = ''; results = list; active = -1;
        list.slice(0, 4).forEach(function (c, n) {
            var o = document.createElement('div');
            o.className = 'ntx-opt'; o.id = 'new-text-opt-' + n; o.setAttribute('role', 'option'); o.setAttribute('data-role', 'new-text-contact'); o.setAttribute('data-uid', c.uid);
            o.setAttribute('aria-selected', 'false');
            if (!c.can_text) { o.setAttribute('aria-disabled', 'true'); }
            var av = document.createElement('span'); av.className = 'ntx-av'; av.textContent = initials(c.name || c.phone); o.appendChild(av);
            var col = document.createElement('div');
            col.appendChild(underline(c.name || c.phone, q));
            // Enough to tell two people with the same name apart: number first, then group / company / email.
            var sub = document.createElement('small');
            sub.textContent = [c.phone, c.group, c.company, c.email].filter(Boolean).join(' · ') + (c.can_text ? '' : ' · Can’t text this contact');
            col.appendChild(sub); o.appendChild(col);
            o.addEventListener('mousedown', function (e) { e.preventDefault(); });
            o.addEventListener('click', function () { pickContact(c); });
            sugg.appendChild(o);
        });
        var t = document.createElement('div');
        t.className = 'ntx-opt is-type'; t.id = 'new-text-opt-type'; t.setAttribute('role', 'option'); t.setAttribute('data-role', 'new-text-type-number'); t.setAttribute('aria-selected', 'false');
        t.innerHTML = '<svg class="ntx-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>';
        t.appendChild(document.createTextNode('Or type a phone number'));
        t.addEventListener('mousedown', function (e) { e.preventDefault(); });
        t.addEventListener('click', typeNumberMode);
        sugg.appendChild(t);
        sugg.hidden = false; toInput.setAttribute('aria-expanded', 'true');
        setActive(0);
    }
    function search(q) {
        var mine = ++searchSeq;
        fetch(root.getAttribute('data-recipients-url') + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
            .then(function (data) {
                if (mine !== searchSeq || recipient) { return; } // a newer keystroke or a pick owns the list
                var list = data.contacts || [];
                if (!list.length) { closeSuggestions(); return; }
                renderSuggestions(q, list);
            })
            .catch(function () { if (mine === searchSeq) { closeSuggestions(); } });
    }
    toInput.addEventListener('input', function () {
        // Editing after a pick drops the pick: the typed text is then what would be sent, never the old contact.
        if (recipient) { recipient = null; picked.hidden = true; }
        showError(''); refresh();
        clearTimeout(searchTimer);
        var q = toInput.value.trim();
        searchSeq++; closeSuggestions();
        if (q.length < 2) { return; }
        searchTimer = setTimeout(function () { search(q); }, 200);
    });
    toInput.addEventListener('keydown', function (e) {
        var opts = sugg.querySelectorAll('.ntx-opt:not([aria-disabled="true"])');
        if (e.key === 'ArrowDown' && !sugg.hidden) { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp' && !sugg.hidden) { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (!sugg.hidden && opts[active]) { opts[active].click(); }
            else if (ready() && !inFlight) { form.requestSubmit ? form.requestSubmit() : submit(); }
        } else if (e.key === 'Escape' && !sugg.hidden) {
            e.preventDefault(); e.stopPropagation(); closeSuggestions(); // first Escape closes the list, the next closes the dialog
        }
    });

    // ---- templates: the existing show-data endpoint, pasted as plain text at the caret ----
    function closeTemplates() { tplMenu.hidden = true; tplBtn.setAttribute('aria-expanded', 'false'); }
    tplBtn.addEventListener('click', function () {
        if (!tplMenu.hidden) { closeTemplates(); return; }
        closeSuggestions(); closeCountry();
        tplMenu.innerHTML = '';
        var list = ctx ? ctx.templates : [];
        if (!list.length) { var none = document.createElement('div'); none.className = 'ntx-opt'; none.textContent = 'No templates yet'; none.setAttribute('aria-disabled', 'true'); tplMenu.appendChild(none); }
        list.forEach(function (t) {
            var o = document.createElement('div'); o.className = 'ntx-opt'; o.tabIndex = 0; o.setAttribute('role', 'option'); o.textContent = t.name;
            function use() { closeTemplates(); insertTemplate(t.id); }
            o.addEventListener('click', use);
            o.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); use(); } });
            tplMenu.appendChild(o);
        });
        tplMenu.hidden = false; tplBtn.setAttribute('aria-expanded', 'true');
        var first = tplMenu.querySelector('[tabindex]'); if (first) { first.focus(); }
    });
    tplMenu.addEventListener('keydown', function (e) {
        var opts = Array.prototype.slice.call(tplMenu.querySelectorAll('[tabindex]')), i = opts.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); (opts[i + 1] || opts[0]).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); (opts[i - 1] || opts[opts.length - 1]).focus(); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeTemplates(); tplBtn.focus(); }
    });
    function insertTemplate(id) {
        fetch(root.getAttribute('data-template-url').replace('__ID__', encodeURIComponent(id)), { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (data.status !== 'success') { showError(data.message || 'That template could not be loaded.'); return; }
                var at = typeof msg.selectionStart === 'number' ? msg.selectionStart : msg.value.length;
                msg.value = msg.value.slice(0, at) + data.message + msg.value.slice(at);
                msg.focus(); showError(''); refresh();
            })
            .catch(function () { showError('That template could not be loaded. Try again.'); });
    }
    msg.addEventListener('input', function () { showError(''); refresh(); });

    // ---- send ----
    function submit() {
        if (inFlight || !ready()) { return; }
        setBusy(true); showError('');
        var body = new FormData();
        body.append('_token', csrf);
        body.append('sms_type', 'plain');
        body.append('country_code', String(countryId));
        body.append('recipient', typedNumber());
        body.append('message', msg.value);
        body.append('idempotency_token', token);
        if (ctx.sender_id) { body.append('sender_id', ctx.sender_id); }
        if (ctx.sending_server) { body.append('sending_server', ctx.sending_server); }
        fetch(root.getAttribute('data-sent-url'), { method: 'POST', body: body, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (data) { return { ok: r.ok, status: r.status, data: data }; }); })
            .then(function (res) {
                var d = res.data || {};
                if (res.ok && d.status === 'success') {
                    var uid = d.conversation_uid;
                    setBusy(false);
                    done = true; modal().hide();
                    document.dispatchEvent(new CustomEvent('new-text-sent', { detail: { uid: uid } }));
                    return;
                }
                // A refusal keeps the dialog and the draft. The token is reused only when the server says the same
                // reservation is still open ('retain'); otherwise the next attempt is a fresh send.
                if ((d.m5_token_action || 'clear') !== 'retain') { token = uuid(); }
                var m = d.message || (d.errors && d.errors[Object.keys(d.errors)[0]] && d.errors[Object.keys(d.errors)[0]][0]) || 'That text could not be sent. Check the number and try again.';
                setBusy(false); showError(m);
            })
            .catch(function () {
                // Network failure: the send may or may not have been accepted, so the same token stays (a retry resolves to it).
                setBusy(false); showError('We couldn’t reach the server, so that text may not have been sent. Check your connection and press Send again.');
            });
    }
    var done = false;
    form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });


    // Focus containment: Bootstrap 5.1 only reacts to focusin, so Tab off the last enabled control would land on <body> for a step.
    // Wrap explicitly instead.
    function trapTab(root) {
        root.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') { return; }
            var items = Array.prototype.filter.call(root.querySelectorAll('button, input, textarea, select, a[href], [tabindex]'), function (el) {
                return !el.disabled && el.type !== 'hidden' && el.getAttribute('tabindex') !== '-1' && el.offsetParent !== null;
            });
            if (!items.length) { return; }
            var first = items[0], last = items[items.length - 1];
            if (e.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
        });
    }
    trapTab(root);

    // ---- open / close ----
    function reset() {
        searchSeq++; clearTimeout(searchTimer);
        recipient = null; toInput.value = ''; toInput.placeholder = 'Name or phone number'; toInput.removeAttribute('inputmode'); picked.hidden = true;
        msg.value = ''; showError(''); closeSuggestions(); closeCountry(); closeTemplates();
        $('new-text-from').textContent = 'Loading…'; ctx = null; countryId = null; inFlight = false; sendBtn.setAttribute('aria-busy', 'false');
        [$('new-text-cancel'), $('new-text-close')].forEach(function (b) { b.disabled = false; });
        refresh();
    }
    function loadContext(href) {
        fetch(root.getAttribute('data-context-url'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
                if (!root.classList.contains('show') && !opening) { return; }
                var d = res.data || {};
                if (!res.ok) { $('new-text-from').textContent = '—'; showError(d.message || 'Texting isn’t available right now.'); return; }
                if (!d.dialog_supported) { window.location.href = href; return; } // this Business needs a sender / server choice: the full page asks
                ctx = d; token = d.idempotency_token || uuid();
                $('new-text-from').textContent = d.from_display || d.from || '—';
                var us = d.countries.filter(function (c) { return c.iso === 'US'; })[0] || d.countries[0];
                countryId = us ? us.id : null; renderCountry(); refresh();
            })
            .catch(function () { $('new-text-from').textContent = '—'; showError('Texting isn’t available right now. Try again in a moment.'); });
    }
    var opening = false;
    function open(trigger) {
        opener = trigger; opening = true; done = false;
        reset();
        modal().show();
        loadContext(trigger.getAttribute('href') || root.getAttribute('data-context-url'));
    }
    root.addEventListener('shown.bs.modal', function () { opening = false; toInput.focus(); });
    root.addEventListener('hidden.bs.modal', function () {
        opening = false; reset();
        var back = opener; opener = null;
        // After a send, the page opens the conversation and owns focus; otherwise focus returns to the launcher.
        if (!done && back && back.focus) { back.focus(); }
    });
    function requestClose() { if (!inFlight) { modal().hide(); } }
    $('new-text-cancel').addEventListener('click', requestClose);
    $('new-text-close').addEventListener('click', requestClose);
    // The overlay is the modal itself: a click on it, outside the card, cancels (never mid-send).
    root.addEventListener('mousedown', function (e) { if (e.target === root) { requestClose(); } });
    // Escape: while a send is in flight it must not close the dialog under it.
    root.addEventListener('keydown', function (e) { if (e.key === 'Escape' && inFlight) { e.preventDefault(); e.stopPropagation(); } }, true);
    document.addEventListener('click', function (e) {
        if (!ccMenu.hidden && !e.target.closest('[data-role="new-text-country"], [data-role="new-text-country-menu"]')) { closeCountry(); }
        if (!tplMenu.hidden && !e.target.closest('[data-role="new-text-template"], [data-role="new-text-template-menu"]')) { closeTemplates(); }
    });

    // Delegated, so the launch controls work wherever the page renders them (sidebar or empty-state button).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-new-text-open]');
        if (btn && !e.defaultPrevented && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) { e.preventDefault(); open(btn); }
    });
})();
</script>
