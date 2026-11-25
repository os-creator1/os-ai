{{--
    Conversations -> "New text" dialog (design: "Conversations — new text popup").

    It is the same compose the /conversations/new page offers, opened over the inbox. Nothing here sends on its own
    authority: it reads the compose state (FROM number, countries, idempotency token) from `conversations.new` as JSON,
    suggests contacts from `conversations.recipients`, pastes templates through the existing show_data endpoint, counts
    segments with the existing SmsCounter, and posts to the existing `conversations.sent` action, which still resolves
    the sender, checks opt-outs/blacklists and meters. Launch controls carry [data-role="new-text-launch"]; without
    JavaScript (or for a non-managed Business) they remain plain links to the full-page form.
--}}
@php
    $ntScope = [$workspaceUid, $businessUid];
    $ntUrls = [
        'compose'    => route('customer.workspaces.businesses.conversations.new', $ntScope),
        'recipients' => route('customer.workspaces.businesses.conversations.recipients', $ntScope),
        'sent'       => route('customer.workspaces.businesses.conversations.sent', $ntScope),
        'inbox'      => route('customer.workspaces.businesses.conversations.index', $ntScope),
        'template'   => route('customer.workspaces.businesses.outreach.templates.show_data', [$workspaceUid, $businessUid, '__ID__']),
    ];
    $ntAttention = __('locale.labels.attention');
@endphp
<style>
    body.nt-backdrop .modal-backdrop { --bs-backdrop-bg: #1F1A19; --bs-backdrop-opacity: .38; }
    .nt-modal { color: #1F1A19; }
    .modal.nt-modal .modal-dialog.nt-dialog { width: 560px; max-width: calc(100% - 32px); margin: 96px auto 24px; padding: 0; }
    .nt-modal .nt-card { border: 0; border-radius: 18px; background: #fff; box-shadow: 0 24px 64px rgba(31,26,25,.28); overflow: visible; }
    .nt-modal .nt-ic { width: 16px; height: 16px; flex: none; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .nt-head { display: flex; align-items: flex-start; gap: 12px; padding: 22px 24px 4px; }
    .nt-title { flex: 1; margin: 0; font-size: 20px; font-weight: 800; line-height: 1.25; color: #1F1A19; }
    .nt-x { width: 36px; height: 36px; flex: none; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 10px; background: #F4F0EC; color: #4A4340; cursor: pointer; }
    .nt-x:hover { background: #ECE6E0; }
    .nt-body { padding: 14px 24px 20px; display: flex; flex-direction: column; gap: 14px; }
    .nt-row { display: flex; align-items: center; gap: 12px; min-height: 44px; border-bottom: 1px solid #F0EBE6; }
    .nt-row-to { min-height: 48px; border-bottom: 2px solid #D6CFC8; }
    .nt-row-to:focus-within { border-bottom-color: #B4514A; }
    .nt-lbl { width: 44px; flex: none; font-size: 13px; color: #8A817C; }
    .nt-from { display: inline-flex; align-items: center; gap: 8px; font-size: 14.5px; font-weight: 600; min-width: 0; }
    .nt-from .nt-ic { width: 15px; height: 15px; color: #8F3B35; }
    .nt-note { font-size: 12.5px; color: #8A817C; }
    .nt-to-wrap { position: relative; }
    .nt-cc { position: relative; flex: none; }
    .nt-cc-btn { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 8px; border: 1px solid #E0DAD4; border-radius: 8px; background: #FBF9F7; font: inherit; font-size: 13.5px; font-weight: 600; color: #1F1A19; cursor: pointer; }
    .nt-flag { min-width: 20px; height: 14px; padding: 0 2px; border-radius: 2px; background: #2F5A8C; color: #fff; font-size: 8px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
    .nt-cc-btn .nt-ic { width: 12px; height: 12px; color: #8A817C; }
    .nt-menu { position: absolute; z-index: 3; top: calc(100% + 6px); left: 0; min-width: 150px; max-height: 240px; overflow-y: auto; padding: 6px; border: 1px solid #E9E4DF; border-radius: 12px; background: #fff; box-shadow: 0 12px 28px rgba(31,26,25,.12); }
    .nt-menu[hidden] { display: none; }
    .nt-menu-item { display: flex; align-items: center; gap: 8px; width: 100%; min-height: 36px; padding: 0 10px; border: 0; border-radius: 8px; background: transparent; font: inherit; font-size: 13.5px; text-align: left; color: #1F1A19; cursor: pointer; }
    .nt-menu-item:hover, .nt-menu-item.is-active, .nt-menu-item[aria-selected="true"] { background: #FBF0EE; }
    .nt-to-input { flex: 1; min-width: 0; height: 100%; padding: 0; border: 0; outline: 0; background: transparent; font: inherit; font-size: 15px; color: #1F1A19; box-shadow: none; }
    .nt-to-input::placeholder { color: #A39A94; }
    .nt-drop { position: absolute; left: 56px; right: 0; top: 54px; z-index: 2; padding: 6px; border: 1px solid #E9E4DF; border-radius: 12px; background: #fff; box-shadow: 0 12px 28px rgba(31,26,25,.12); }
    .nt-drop[hidden] { display: none; }
    .nt-opt { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 46px; padding: 0 10px; border: 0; border-radius: 9px; background: transparent; font: inherit; text-align: left; color: #1F1A19; cursor: pointer; }
    .nt-opt.is-active { background: #FBF0EE; }
    .nt-opt[aria-disabled="true"] { cursor: not-allowed; opacity: .6; }
    .nt-av { width: 30px; height: 30px; flex: none; border-radius: 50%; background: #E3F3EA; color: #17693F; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .nt-opt-copy { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .nt-opt-name { font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .nt-opt-name u { text-decoration: underline; }
    .nt-opt-sub { font-size: 12px; color: #6B625E; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .nt-opt-more { min-height: 42px; font-size: 13.5px; font-weight: 600; color: #8F3B35; }
    .nt-opt-more .nt-ic { width: 14px; height: 14px; }
    .nt-gap { min-height: 96px; margin: -14px 0; display: flex; align-items: flex-start; }
    .nt-picked { font-size: 12.5px; color: #6B625E; padding: 8px 0 0 56px; }
    .nt-compose { border: 1px solid #D6CFC8; border-radius: 12px; }
    .nt-compose:focus-within { border-color: #B4514A; box-shadow: 0 0 0 3px #F8E9E6; }
    .nt-msg { display: block; width: 100%; min-height: 96px; padding: 12px 14px; border: 0; border-radius: 12px 12px 0 0; outline: 0; resize: vertical; font: inherit; font-size: 15px; line-height: 1.5; color: #1F1A19; background: transparent; box-shadow: none; }
    .nt-msg::placeholder { color: #A39A94; }
    .nt-tools { display: flex; align-items: center; gap: 4px; padding: 6px 8px; border-top: 1px solid #F0EBE6; background: #FBF9F7; border-radius: 0 0 12px 12px; }
    .nt-tool { position: relative; display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 10px; border: 0; border-radius: 8px; background: transparent; font: inherit; font-size: 13px; font-weight: 600; color: #4A4340; cursor: pointer; }
    .nt-tool:hover:not(:disabled) { background: #F0EBE6; }
    .nt-tool:disabled { opacity: .5; cursor: not-allowed; }
    .nt-tool .nt-ic { width: 15px; height: 15px; }
    .nt-tool .nt-ic:first-child { color: #8F3B35; }
    .nt-tool .nt-ic.nt-caret { width: 12px; height: 12px; color: #8A817C; }
    .nt-tpl-wrap { position: relative; }
    .nt-tpl-wrap .nt-menu { top: auto; bottom: calc(100% + 6px); min-width: 220px; }
    .nt-count { margin-left: auto; font-size: 12px; color: #8A817C; white-space: nowrap; }
    .nt-info { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: #6B625E; }
    .nt-info .nt-ic { width: 14px; height: 14px; color: #8A817C; }
    .nt-error { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border-radius: 10px; background: #FCEBEA; color: #8C1D18; font-size: 13.5px; }
    .nt-error[hidden] { display: none; }
    .nt-error .nt-ic { margin-top: 2px; }
    .nt-foot { display: flex; align-items: center; gap: 10px; padding: 14px 24px; border-top: 1px solid #F0EBE6; background: #FBF9F7; border-radius: 0 0 18px 18px; }
    .nt-foot-note { flex: 1; font-size: 12.5px; color: #6B625E; }
    .nt-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 16px; border: 0; border-radius: 10px; font: inherit; font-size: 14px; font-weight: 600; white-space: nowrap; cursor: pointer; background: transparent; color: #5E5652; }
    .nt-btn:hover:not(:disabled) { background: #F0EBE6; }
    .nt-btn-primary { padding: 0 20px; background: #B4514A; color: #fff; }
    .nt-btn-primary:hover:not(:disabled) { background: #8F3B35; }
    .nt-btn-primary:disabled { opacity: .55; cursor: not-allowed; }
    .nt-btn .nt-ic { width: 16px; height: 16px; }
    .nt-modal button:focus-visible { outline: 2px solid #B4514A; outline-offset: 2px; }
    @media (max-width: 575.98px) {
        .modal.nt-modal .modal-dialog.nt-dialog { margin-top: 12px; }
        .nt-head { padding: 18px 16px 4px; }
        .nt-body { padding: 12px 16px 16px; }
        .nt-foot { padding: 12px 16px; flex-wrap: wrap; }
        .nt-foot-note { flex: 1 0 100%; order: 3; }
        .nt-foot .nt-btn { flex: 1; }
        .nt-drop { left: 0; }
        .nt-picked { padding-left: 0; }
        .nt-gap { min-height: 72px; }
        .nt-note { display: none; }
    }
</style>

<div class="modal fade nt-modal" id="new-text-dialog" tabindex="-1" aria-labelledby="new-text-title" aria-hidden="true" data-role="new-text-dialog">
    <div class="modal-dialog nt-dialog">
        <div class="modal-content nt-card">
            <div class="nt-head">
                <h2 class="nt-title" id="new-text-title">New text</h2>
                <button type="button" class="nt-x" data-bs-dismiss="modal" aria-label="Close"><svg class="nt-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg></button>
            </div>
            <div class="nt-body">
                <div class="nt-row" data-role="nt-from-row">
                    <span class="nt-lbl">From</span>
                    <span class="nt-from"><svg class="nt-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"></path></svg><span data-role="nt-from">…</span></span>
                    <span class="nt-note">Your texting number</span>
                </div>
                <div class="nt-to-wrap">
                    <div class="nt-row nt-row-to">
                        <label class="nt-lbl" for="nt-to">To</label>
                        <div class="nt-cc">
                            <button type="button" class="nt-cc-btn" data-role="nt-cc-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Country code">
                                <span class="nt-flag" data-role="nt-flag">US</span><span data-role="nt-cc-code">+1</span><svg class="nt-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"></path></svg>
                            </button>
                            <div class="nt-menu" role="listbox" aria-label="Country code" data-role="nt-cc-menu" hidden></div>
                        </div>
                        <input class="nt-to-input" id="nt-to" type="text" inputmode="text" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="80"
                               placeholder="Name or phone number" data-role="nt-to"
                               role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="nt-drop">
                    </div>
                    <div class="nt-drop" id="nt-drop" role="listbox" aria-label="Recipients" data-role="nt-drop" hidden></div>
                </div>
                <div class="nt-gap"><div class="nt-picked" data-role="nt-picked" role="status" aria-live="polite" hidden></div></div>
                <div class="nt-compose">
                    <textarea class="nt-msg" data-role="nt-message" rows="4" aria-label="Message" placeholder="Write your message…"></textarea>
                    <div class="nt-tools">
                        <div class="nt-tpl-wrap">
                            <button type="button" class="nt-tool" data-role="nt-tpl-btn" aria-haspopup="menu" aria-expanded="false">
                                <svg class="nt-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h10l4 4v12H5zM9 12h6M9 16h4"></path></svg>Use a template<svg class="nt-ic nt-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"></path></svg>
                            </button>
                            <div class="nt-menu" role="menu" aria-label="Templates" data-role="nt-tpl-menu" hidden>
                                @forelse($templates as $template)
                                    <button type="button" class="nt-menu-item" role="menuitem" data-template-id="{{ $template->id }}">{{ $template->name }}</button>
                                @empty
                                    <span class="nt-menu-item" aria-disabled="true">No templates yet</span>
                                @endforelse
                            </div>
                        </div>
                        {{-- Texts sent from here are plain SMS (the existing send action); images are attached inside a conversation. --}}
                        <button type="button" class="nt-tool" disabled aria-disabled="true" aria-label="Attach image (available inside a conversation)" title="Attach images from inside a conversation">
                            <svg class="nt-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3.500" y="5" width="17" height="14" rx="2.500"></rect><circle cx="9" cy="10" r="1.700"></circle><path d="M20.500 15l-5-5-9 9"></path></svg>
                        </button>
                        <span class="nt-count" data-role="nt-count" aria-live="polite">0 / 160 · 1 text</span>
                    </div>
                </div>
                <span class="nt-info"><svg class="nt-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.500"></circle><path d="M12 11v5M12 8h.01"></path></svg>Templates are pasted as plain text — tags like @{{first name}} aren't filled in here.</span>
                <div class="nt-error" role="alert" data-role="nt-error" hidden><svg class="nt-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.500"></circle><path d="M12 8v5M12 16h.01"></path></svg><span data-role="nt-error-text"></span></div>
            </div>
            <div class="nt-foot">
                <span class="nt-foot-note">Opens in Conversations after sending.</span>
                <button type="button" class="nt-btn" data-bs-dismiss="modal" data-role="nt-cancel">Cancel</button>
                <button type="button" class="nt-btn nt-btn-primary" data-role="nt-send" disabled><svg class="nt-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l16-8-6 16-2.500-6.500z"></path></svg>Send</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var el = document.getElementById('new-text-dialog');
    if (!el) { return; }
    var urls = @json($ntUrls);
    var csrf = @json(csrf_token());
    function q(role) { return el.querySelector('[data-role="' + role + '"]'); }
    var fromEl = q('nt-from'), ccBtn = q('nt-cc-btn'), ccMenu = q('nt-cc-menu'), flagEl = q('nt-flag'), ccCode = q('nt-cc-code');
    var toEl = q('nt-to'), dropEl = q('nt-drop'), pickedEl = q('nt-picked'), msgEl = q('nt-message');
    var tplBtn = q('nt-tpl-btn'), tplMenu = q('nt-tpl-menu'), countEl = q('nt-count'), errBox = q('nt-error'), errText = q('nt-error-text'), sendBtn = q('nt-send');

    var state, launcher = null, opening = false;
    function fresh() { return { from: null, countries: [], country: null, token: null, contact: null, sending: false, options: [], active: -1 }; }
    state = fresh();

    function uuid() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
    }
    function modal() { return window.bootstrap.Modal.getOrCreateInstance(el); }
    function fmtPhone(p) {
        var d = String(p || '').replace(/\D+/g, '');
        if (d.length === 11 && d.charAt(0) === '1') { return '+1 (' + d.slice(1, 4) + ') ' + d.slice(4, 7) + '-' + d.slice(7); }
        return d ? '+' + d : '';
    }
    function digitsOf(v) { return String(v || '').replace(/\D+/g, ''); }
    function phoneLike(v) { return /^[\d\s().+\-]+$/.test(v) && digitsOf(v).length > 0; }
    function showError(msg) { errText.textContent = msg; errBox.hidden = !msg; }
    function initials(name) {
        var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) { return '#'; }
        return ((parts[0].charAt(0) || '') + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
    }

    // ---- recipient ----------------------------------------------------------------------------------------------
    function recipientDigits() { return state.contact ? state.contact.national : digitsOf(toEl.value); }
    function canSend() {
        return !state.sending && !!state.from && !!state.country && recipientDigits() !== '' && msgEl.value.trim() !== '' && !(state.contact && state.contact.opted_out);
    }
    function refresh() { sendBtn.disabled = !canSend(); }

    function setCountry(c) {
        state.country = c;
        flagEl.textContent = c ? c.iso : '';
        ccCode.textContent = c ? '+' + c.code : '';
    }
    function countryFor(code, preferred) {
        var rows = state.countries.filter(function (c) { return String(c.code) === String(code); });
        if (!rows.length) { return null; }
        return rows.filter(function (c) { return preferred && c.id === preferred.id; })[0] || rows[0];
    }
    function renderCountries() {
        ccMenu.innerHTML = '';
        state.countries.forEach(function (c) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'nt-menu-item'; b.setAttribute('role', 'option');
            b.setAttribute('aria-selected', state.country && state.country.id === c.id ? 'true' : 'false');
            var f = document.createElement('span'); f.className = 'nt-flag'; f.textContent = c.iso;
            b.appendChild(f); b.appendChild(document.createTextNode('+' + c.code));
            b.addEventListener('click', function () {
                setCountry(c); closeCountries(); clearContact(); renderCountries(); toEl.focus(); refresh();
            });
            ccMenu.appendChild(b);
        });
    }
    function openCountries() { ccMenu.hidden = false; ccBtn.setAttribute('aria-expanded', 'true'); var s = ccMenu.querySelector('[aria-selected="true"]') || ccMenu.firstElementChild; if (s) { s.focus(); } }
    function closeCountries() { ccMenu.hidden = true; ccBtn.setAttribute('aria-expanded', 'false'); }
    ccBtn.addEventListener('click', function () { ccMenu.hidden ? openCountries() : closeCountries(); });
    ccMenu.addEventListener('keydown', function (e) {
        var items = Array.prototype.slice.call(ccMenu.children), i = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeCountries(); ccBtn.focus(); }
    });

    function clearContact() { state.contact = null; pickedEl.hidden = true; pickedEl.textContent = ''; }

    var timer = null, seq = 0, controller = null;
    function closeDrop() {
        dropEl.hidden = true; dropEl.innerHTML = ''; state.options = []; state.active = -1;
        toEl.setAttribute('aria-expanded', 'false'); toEl.removeAttribute('aria-activedescendant');
    }
    function setActive(i) {
        state.active = i;
        Array.prototype.forEach.call(dropEl.children, function (n, k) { n.classList.toggle('is-active', k === i); n.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
        if (i >= 0 && dropEl.children[i]) { toEl.setAttribute('aria-activedescendant', dropEl.children[i].id); }
    }
    function plus() {
        var ns = 'http://www.w3.org/2000/svg', svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'nt-ic'); svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS(ns, 'path'); p.setAttribute('d', 'M12 5v14M5 12h14'); svg.appendChild(p);
        return svg;
    }
    function pickContact(c) {
        var country = countryFor(c.country_code, state.country);
        if (!c.national || !country) {
            showError('We can’t text ' + (c.name || c.phone) + ' — ' + c.phone + ' isn’t a number this business can send to from here. Type a different number instead.');
            return;
        }
        showError('');
        state.contact = c; setCountry(country); renderCountries();
        toEl.value = c.name || fmtPhone(c.phone);
        pickedEl.textContent = fmtPhone(c.phone) + (c.group ? ' · ' + c.group : '');
        pickedEl.hidden = false;
        closeDrop(); refresh(); msgEl.focus();
    }
    function render(contacts, text) {
        closeDrop();
        var opts = [];
        contacts.forEach(function (c, n) {
            var row = document.createElement('div');
            row.className = 'nt-opt'; row.id = 'nt-opt-' + n; row.setAttribute('role', 'option'); row.setAttribute('aria-selected', 'false'); row.tabIndex = -1;
            if (c.opted_out) { row.setAttribute('aria-disabled', 'true'); }
            var av = document.createElement('span'); av.className = 'nt-av'; av.textContent = initials(c.name);
            var copy = document.createElement('div'); copy.className = 'nt-opt-copy';
            var name = document.createElement('b'); name.className = 'nt-opt-name';
            var label = c.name || fmtPhone(c.phone), at = label.toLowerCase().indexOf(text.toLowerCase());
            if (at === 0 && text.length) { var u = document.createElement('u'); u.textContent = label.slice(0, text.length); name.appendChild(u); name.appendChild(document.createTextNode(label.slice(text.length))); }
            else { name.textContent = label; }
            var sub = document.createElement('span'); sub.className = 'nt-opt-sub';
            sub.textContent = [fmtPhone(c.phone), c.group].filter(Boolean).join(' · ') + (c.opted_out ? ' · Opted out of texts' : '');
            copy.appendChild(name); copy.appendChild(sub); row.appendChild(av); row.appendChild(copy);
            row.addEventListener('mousedown', function (e) { e.preventDefault(); });
            row.addEventListener('mouseenter', function () { setActive(n); });
            row.addEventListener('click', function () { if (c.opted_out) { showError(c.name ? c.name + ' has opted out of text messages, so nothing can be sent.' : 'This contact has opted out of text messages, so nothing can be sent.'); return; } pickContact(c); });
            dropEl.appendChild(row);
            opts.push({ contact: c });
        });
        var more = document.createElement('div');
        more.className = 'nt-opt nt-opt-more'; more.id = 'nt-opt-more'; more.setAttribute('role', 'option'); more.setAttribute('aria-selected', 'false'); more.tabIndex = -1;
        more.appendChild(plus());
        more.appendChild(document.createTextNode(phoneLike(text) ? 'Use ' + text.trim() + ' as the number' : 'Or type a phone number'));
        more.addEventListener('mousedown', function (e) { e.preventDefault(); });
        more.addEventListener('mouseenter', function () { setActive(contacts.length); });
        more.addEventListener('click', function () { useTyped(); });
        dropEl.appendChild(more);
        opts.push({ typed: true });
        state.options = opts;
        dropEl.hidden = false; toEl.setAttribute('aria-expanded', 'true');
        setActive(0);
    }
    function useTyped() {
        // "Or type a phone number": drop a name query so digits can be typed; a typed number simply stays as is.
        if (!phoneLike(toEl.value)) { toEl.value = ''; }
        closeDrop(); refresh(); toEl.focus();
    }

    toEl.addEventListener('input', function () {
        clearContact(); showError(''); refresh();
        var text = toEl.value.trim();
        // A leading "+<calling code>" selects that country; the rest stays as the national number.
        if (/^\+/.test(text) && phoneLike(text)) {
            var d = digitsOf(text), best = null;
            state.countries.forEach(function (c) { if (d.indexOf(String(c.code)) === 0 && (!best || String(c.code).length > String(best.code).length)) { best = c; } });
            if (best && d.length > String(best.code).length) { setCountry(countryFor(best.code, state.country)); renderCountries(); toEl.value = d.slice(String(best.code).length); text = toEl.value; refresh(); }
        }
        clearTimeout(timer); if (controller) { controller.abort(); }
        var mine = ++seq;
        closeDrop();
        if (text.length < 2) { return; }
        timer = setTimeout(function () {
            controller = window.AbortController ? new AbortController() : null;
            fetch(urls.recipients + '?q=' + encodeURIComponent(text), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', signal: controller ? controller.signal : undefined })
                .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
                .then(function (data) {
                    // A newer keystroke, or a contact already picked, owns the field: a late answer is dropped.
                    if (mine !== seq || toEl.value.trim() !== text || state.contact) { return; }
                    render(data.contacts || [], text);
                })
                .catch(function () { if (mine === seq) { closeDrop(); } });
        }, 200);
    });
    toEl.addEventListener('keydown', function (e) {
        var open = !dropEl.hidden && state.options.length > 0;
        if (e.key === 'ArrowDown' && open) { e.preventDefault(); setActive((state.active + 1) % state.options.length); }
        else if (e.key === 'ArrowUp' && open) { e.preventDefault(); setActive((state.active - 1 + state.options.length) % state.options.length); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (open && state.active >= 0) {
                var o = state.options[state.active];
                if (o.typed) { useTyped(); } else if (o.contact.opted_out) { showError('This contact has opted out of text messages, so nothing can be sent.'); } else { pickContact(o.contact); }
            } else if (recipientDigits() !== '') { msgEl.focus(); }
        }
        else if (e.key === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); closeDrop(); } // first Escape closes the list, the next closes the dialog
    });
    toEl.addEventListener('blur', function () { setTimeout(function () { if (document.activeElement !== toEl) { closeDrop(); } }, 120); });

    // ---- composer -----------------------------------------------------------------------------------------------
    function count() {
        var r = (typeof SmsCounter !== 'undefined') ? SmsCounter.count(msgEl.value) : { length: msgEl.value.length, per_message: 160, messages: msgEl.value.length ? 1 : 0 };
        var msgs = Math.max(1, r.messages);
        countEl.textContent = r.length + ' / ' + (r.per_message * msgs) + ' · ' + msgs + (msgs === 1 ? ' text' : ' texts');
    }
    msgEl.addEventListener('input', function () { count(); showError(''); refresh(); });
    function closeTpl() { tplMenu.hidden = true; tplBtn.setAttribute('aria-expanded', 'false'); }
    tplBtn.addEventListener('click', function () { var open = tplMenu.hidden; tplMenu.hidden = !open; tplBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); if (open && tplMenu.firstElementChild) { tplMenu.firstElementChild.focus(); } });
    tplMenu.addEventListener('keydown', function (e) {
        var items = Array.prototype.slice.call(tplMenu.querySelectorAll('button')), i = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown' && items.length) { e.preventDefault(); items[(i + 1) % items.length].focus(); }
        else if (e.key === 'ArrowUp' && items.length) { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeTpl(); tplBtn.focus(); }
    });
    tplMenu.addEventListener('click', function (e) {
        var b = e.target.closest('[data-template-id]');
        if (!b) { return; }
        closeTpl();
        fetch(urls.template.replace('__ID__', encodeURIComponent(b.getAttribute('data-template-id'))), {
            method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.status !== 'success') { showError(data.message || 'That template could not be loaded.'); return; }
            // Pasted at the caret as plain text; the draft around it is kept.
            var at = msgEl.selectionStart == null ? msgEl.value.length : msgEl.selectionStart, end = msgEl.selectionEnd == null ? at : msgEl.selectionEnd;
            msgEl.value = msgEl.value.slice(0, at) + data.message + msgEl.value.slice(end);
            var pos = at + String(data.message).length; msgEl.setSelectionRange(pos, pos);
            count(); refresh(); msgEl.focus();
        }).catch(function () { showError('That template could not be loaded. Your message is unchanged.'); });
    });

    // ---- send ---------------------------------------------------------------------------------------------------
    function failMessage(status, body) {
        if (body && body.errors) { var k = Object.keys(body.errors)[0]; if (k && body.errors[k][0]) { return body.errors[k][0]; } }
        if (body && body.message) { return body.message; }
        if (status === 419) { return 'This page timed out. Copy your message, reload the page and try again.'; }
        if (status === 429) { return 'Too many attempts. Wait a moment, then press Send again — your message is kept.'; }
        return 'The text was not sent. Check the number and press Send again — your message is kept.';
    }
    sendBtn.addEventListener('click', function () {
        if (!canSend()) { return; }
        state.sending = true; refresh(); showError('');
        sendBtn.setAttribute('aria-busy', 'true');
        var body = new URLSearchParams();
        body.set('sms_type', 'plain');
        body.set('country_code', String(state.country.id));
        body.set('recipient', recipientDigits());
        body.set('message', msgEl.value);
        body.set('idempotency_token', state.token);
        fetch(urls.sent, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, credentials: 'same-origin', body: body.toString() })
            .then(function (r) { return r.json().catch(function () { return null; }).then(function (j) { return { status: r.status, ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (res.ok && res.body && res.body.status === 'success') {
                    // Navigate to the stored conversation: it is read back from the server, so the message appears exactly once.
                    window.location.href = res.body.conversation_uid ? urls.inbox + (urls.inbox.indexOf('?') === -1 ? '?' : '&') + 'open=' + encodeURIComponent(res.body.conversation_uid) : urls.inbox;
                    return;
                }
                // Failed: keep the dialog and the draft. The same token is reused only when the server holds an open reservation for it.
                state.token = (res.body && res.body.retry_token) || uuid();
                state.sending = false; sendBtn.removeAttribute('aria-busy'); refresh();
                showError(failMessage(res.status, res.body));
            })
            .catch(function () {
                // The outcome is unknown: keep the SAME token so a retry cannot double-send.
                state.sending = false; sendBtn.removeAttribute('aria-busy'); refresh();
                showError('Couldn’t reach the server, so we don’t know if this was sent. Check your connection and press Send again — your message is kept and it will not be sent twice.');
            });
    });

    // ---- open / close -------------------------------------------------------------------------------------------
    function reset() {
        state = fresh(); toEl.value = ''; msgEl.value = ''; clearContact(); closeDrop(); closeCountries(); closeTpl(); showError('');
        sendBtn.removeAttribute('aria-busy'); count(); fromEl.textContent = '…'; ccMenu.innerHTML = '';
    }
    // Tab wraps inside the dialog even when the last control (Send) is disabled.
    el.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') { return; }
        var f = Array.prototype.filter.call(el.querySelectorAll('button, input, textarea, [tabindex]:not([tabindex="-1"])'), function (n) { return !n.disabled && n.offsetParent !== null && n.type !== 'hidden'; });
        if (!f.length) { return; }
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    });
    el.addEventListener('show.bs.modal', function () { document.body.classList.add('nt-backdrop'); });
    el.addEventListener('shown.bs.modal', function () { toEl.focus(); });
    el.addEventListener('hide.bs.modal', function (e) { if (state.sending) { e.preventDefault(); } }); // never close mid-send
    el.addEventListener('hidden.bs.modal', function () {
        document.body.classList.remove('nt-backdrop');
        reset();
        var back = launcher; launcher = null;
        if (back && document.body.contains(back)) { back.focus(); }
    });
    // Keep a closed popup from lingering: a click elsewhere in the dialog closes the menus.
    el.addEventListener('click', function (e) {
        if (!e.target.closest('.nt-cc')) { closeCountries(); }
        if (!e.target.closest('.nt-tpl-wrap')) { closeTpl(); }
    });

    function open(link) {
        if (opening) { return; }
        opening = true; launcher = link;
        fetch(urls.compose, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return null; }).then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                opening = false;
                if (!res.ok || !res.body || res.body.status !== 'success') {
                    // Not ready (no number / plan): say why here instead of opening a dialog that cannot send.
                    var msg = res.body && res.body.message ? res.body.message : null;
                    if (msg && window.toastr) { toastr['warning'](msg, @json($ntAttention)); return; }
                    window.location.href = link.href; return;
                }
                if (!res.body.managed) { window.location.href = link.href; return; } // legacy sender/server choice lives on the full page
                reset();
                state.from = res.body.from; state.countries = res.body.countries || []; state.token = res.body.idempotency_token || uuid();
                fromEl.textContent = fmtPhone(state.from);
                setCountry(countryFor('1') || state.countries[0] || null);
                renderCountries(); count(); refresh();
                modal().show();
            })
            .catch(function () { opening = false; window.location.href = link.href; });
    }
    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-role="new-text-launch"]');
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button > 0) { return; }
        e.preventDefault();
        open(link);
    });
})();
</script>
