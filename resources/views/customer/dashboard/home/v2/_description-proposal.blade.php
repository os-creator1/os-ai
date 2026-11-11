{{--
    Business-description proposal INSIDE the red Best Next Move card (the move's ONE primary action lives here).
    The text is a deterministic draft from confirmed saved facts (no AI on load). "Improve with AI" is an explicit, metered click;
    "Approve & save" posts to the trusted opportunity workflow. The result panel distinguishes four truthful states and never shows
    success before the workflow itself reports `completed`: working (approval being sent) → pending (approval received, saving not
    confirmed) → completed (verified) | failed. There is deliberately NO Undo: no safe undo exists for an approved profile change.
--}}
@php($d = $needs['description'])
@php($next = $next ?? null)
@php($layout = $layout ?? 'core')
@if($layout !== 'growth')
{{-- Core Home: the original compact presentation (hv2 classes only; the Growth stylesheet is never loaded here). Same behaviour and roles. --}}
<div class="hv2-desc" data-role="description-proposal" data-approve-url="{{ $d['approveUrl'] }}" @if($d['improveUrl']) data-improve-url="{{ $d['improveUrl'] }}" @endif data-base="{{ $d['base'] }}" data-opportunity="{{ $d['uid'] }}" data-min="50" data-max="5000">
    <label class="hv2-desc__label" for="hv2-desc-text">{{ $d['fromFacts'] ? 'Proposed description — built from your saved business details' : 'Your business description' }}</label>
    <textarea id="hv2-desc-text" class="form-control hv2-desc__text" rows="4" maxlength="5000" readonly data-role="description-text" placeholder="Write 2–3 sentences about what you do and where you work (at least 50 characters).">{{ $d['text'] }}</textarea>
    <div class="hv2-needs__actions hv2-desc__actions" data-role="description-actions">
        <button type="button" class="btn btn-primary hv2-btn" data-role="description-approve">Approve &amp; save</button>
        <button type="button" class="btn btn-outline-secondary hv2-btn" data-role="description-edit">Edit description</button>
        @if($d['improveUrl'])<button type="button" class="btn btn-outline-secondary hv2-btn" data-role="description-improve"><span class="hv2-spark" aria-hidden="true">✦</span> Improve with AI</button>@endif
    </div>
    <p class="hv2-muted hv2-desc__note mb-0">Saved to your MotionGrove business profile. This does not automatically update Google or publish your website.</p>
    <p class="hv2-desc__status mb-0" role="status" aria-live="polite" data-role="description-status" data-state="idle" hidden><b data-role="description-status-title"></b> <span data-role="description-status-detail"></span></p>
</div>
<style>
    .hv2 .hv2-desc { margin-top: .5rem; display: grid; gap: .5rem; }
    .hv2 .hv2-desc__label { font-size: .8125rem; font-weight: 600; margin: 0; }
    .hv2 .hv2-desc__text { font-size: .9375rem; line-height: 1.5; }
    .hv2 .hv2-desc__text[readonly] { background: var(--hv2-card); }
    .hv2 .hv2-desc__actions { display: flex; flex-wrap: wrap; gap: .5rem; }
    .hv2 .hv2-desc__actions[hidden], .hv2 .hv2-desc__status[hidden] { display: none; }
    .hv2 .hv2-desc__note, .hv2 .hv2-desc__status { font-size: .8125rem; }
    .hv2 .hv2-desc__status[data-state="completed"] { color: var(--color-status-success, #1E7B3C); }
    .hv2 .hv2-desc__status[data-state="failed"] { color: var(--color-status-danger, #B3261E); }
    @media (max-width: 575px) { .hv2 .hv2-desc__actions .hv2-btn { flex: 1 1 100%; min-height: 2.75rem; } }
</style>
@else
<div class="gh-desc" data-role="description-proposal" data-approve-url="{{ $d['approveUrl'] }}" @if($d['improveUrl']) data-improve-url="{{ $d['improveUrl'] }}" @endif data-base="{{ $d['base'] }}" data-opportunity="{{ $d['uid'] }}" data-min="50" data-max="5000">
    <div class="gh-desc__panel" data-role="description-panel">
        <label class="gh-desc__label" for="hv2-desc-text">{{ $d['fromFacts'] ? 'Prepared by MotionGrove · from your saved business details' : 'Your business description' }}</label>
        <textarea id="hv2-desc-text" class="gh-desc__text" rows="1" maxlength="5000" readonly data-role="description-text" placeholder="Write 2–3 sentences about what you do and where you work (at least 50 characters).">{{ $d['text'] }}</textarea>
    </div>
    <div class="gh-desc__actions" data-role="description-actions">
        <button type="button" class="gh-btn gh-btn--light" data-role="description-approve"><svg class="gh-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>Approve &amp; save</button>
        <button type="button" class="gh-btn gh-btn--ghost" data-role="description-edit">Edit first</button>
        @if($d['improveUrl'])<button type="button" class="gh-btn gh-btn--ghost" data-role="description-improve"><span aria-hidden="true">✦</span> Improve with AI</button>@endif
        <span class="gh-desc__spacer" aria-hidden="true"></span>
        <span class="gh-desc__note">Saves to your MotionGrove profile only. Google and your website are not changed.</span>
    </div>
    <div class="gh-desc__result" role="status" aria-live="polite" data-role="description-status" data-state="idle" hidden>
        <span class="gh-desc__badge" aria-hidden="true"></span>
        <div class="gh-desc__result-text"><b data-role="description-status-title"></b><span data-role="description-status-detail"></span></div>
    </div>
    @if($next !== null && ($next['url'] ?? null) !== null)
        <div class="gh-desc__next" data-role="description-next" hidden>
            <span class="gh-eyebrow">Up next</span>
            <span class="gh-desc__next-title">{{ $next['title'] }}</span>
            <a class="gh-btn gh-btn--light gh-btn--sm" href="{{ $next['url'] }}">Start <svg class="gh-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        </div>
    @endif
</div>
@endif
<script>
(function () {
    var root = document.querySelector('[data-role="description-proposal"]');
    if (!root) { return; }
    var q = function (r) { return root.querySelector('[data-role="' + r + '"]'); };
    var text = q('description-text'), box = q('description-status'), title = q('description-status-title'), detail = q('description-status-detail');
    var approve = q('description-approve'), improve = q('description-improve'), edit = q('description-edit'), actions = q('description-actions'), next = q('description-next');
    var token = document.querySelector('meta[name="csrf-token"]'), saved = text.value, dirty = false, busy = false, prevReadOnly = text.readOnly;
    var grow = root.classList.contains('gh-desc');
    var size = function () { if (!grow) { return; } text.style.height = 'auto'; text.style.height = text.scrollHeight + 'px'; };
    // state: working | pending | completed | failed. Only `completed` is ever presented as success, and only once the workflow says so.
    var show = function (state, head, body) { box.hidden = false; box.setAttribute('data-state', state); title.textContent = head; detail.textContent = body || ''; };
    var post = function (url, body) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j.http = r.status; return j; }); });
    };
    // While a request is in flight the text cannot change: it is read-only, and its previous editable/read-only state returns afterwards.
    var lock = function (on) { if (on) { prevReadOnly = text.readOnly; text.readOnly = true; } else { text.readOnly = prevReadOnly; } busy = on; approve.disabled = on; if (improve) { improve.disabled = on; } edit.disabled = on; };
    text.addEventListener('input', function () { dirty = true; size(); });
    edit.addEventListener('click', function () { text.readOnly = false; root.classList.add('is-editing'); text.focus(); });
    var done = function () {
        text.readOnly = true; root.classList.remove('is-editing'); actions.hidden = true;
        show('completed', 'Saved and verified in your MotionGrove business profile.', 'Your Google listing and live website were not changed by this.');
        if (next) { next.hidden = false; }
    };
    var failed = function (message) { lock(false); show('failed', message, 'Nothing was changed.'); };
    var poll = function (url, tries) {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            if (j.is_terminal) {
                if (j.opportunity_status === 'completed') { done(); } else { failed('We could not save the description.'); }
            } else if (tries > 0) { setTimeout(function () { poll(url, tries - 1); }, 1500); }
            else { show('pending', 'Approval received. Saving has not been confirmed yet.', 'Refresh in a moment to see the result.'); }
        }).catch(function () { lock(false); show('pending', 'We could not confirm the save.', 'Your approval was received. Refresh to check the result.'); });
    };
    approve.addEventListener('click', function () {
        if (busy) { return; }
        var v = text.value.trim();
        if (v.length < 50) { show('failed', 'Write at least 50 characters before saving.', ''); return; }
        lock(true); show('working', 'Sending your approval…', '');
        post(root.dataset.approveUrl, { value: v, base: root.dataset.base }).then(function (j) {
            if (j.state === 'completed') { done(); }
            else if (j.state === 'pending') { show('pending', 'Approval received. Saving to your profile…', 'This is not confirmed until MotionGrove verifies it.'); poll(j.statusUrl, 20); }
            else { failed(j.message || 'We could not save the description.'); }
        }).catch(function () { failed('We could not save the description.'); });
    });
    if (improve) {
        improve.addEventListener('click', function () {
            if (busy) { return; }
            if (dirty && text.value.trim() !== saved.trim() && !window.confirm('Replace your edits with an AI suggestion?')) { return; }
            var before = text.value;
            lock(true); show('working', 'Asking AI for a suggestion…', '');
            post(root.dataset.improveUrl, { text: text.value, request_id: String(Date.now()) + Math.random().toString(36).slice(2) }).then(function (j) {
                lock(false);
                if (j.ok && j.text) {
                    // Newer customer text is never silently discarded.
                    if (text.value !== before && !window.confirm('Your text changed while AI was working. Replace it with the suggestion?')) { show('working', 'Suggestion discarded.', 'Your text is unchanged.'); return; }
                    text.value = j.text; text.readOnly = false; root.classList.add('is-editing'); dirty = false; saved = j.text; size(); show('working', 'Suggestion ready.', 'Review it, then approve to save.'); }
                else { show('failed', j.message || 'AI suggestions are not available right now.', ''); }
            }).catch(function () { lock(false); show('failed', 'AI suggestions are not available right now.', ''); });
        });
    }
    size();
    window.addEventListener('resize', size);
})();
</script>
