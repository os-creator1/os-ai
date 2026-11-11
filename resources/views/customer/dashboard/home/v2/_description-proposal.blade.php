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
    <p class="hv2-desc__status mb-0" role="status" aria-live="polite" data-role="description-status" data-state="idle" hidden><b data-role="description-status-title"></b> <span data-role="description-status-detail"></span> <button type="button" class="hv2-desc__refresh" data-role="description-refresh" hidden>Refresh to check</button></p>
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
    .hv2 .hv2-desc__status[data-state="unconfirmed"] { color: var(--color-status-warning, #8A5A00); }
    .hv2 .hv2-desc__refresh { background: none; border: 0; padding: 0 .25rem; font: inherit; text-decoration: underline; color: inherit; cursor: pointer; }
    .hv2 .hv2-desc__refresh[hidden] { display: none; }
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
        <div class="gh-desc__result-text"><b data-role="description-status-title"></b><span data-role="description-status-detail"></span><button type="button" class="gh-desc__refresh" data-role="description-refresh" hidden>Refresh to check</button></div>
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
    var approve = q('description-approve'), improve = q('description-improve'), edit = q('description-edit'), actions = q('description-actions'), next = q('description-next'), refresh = q('description-refresh');
    var token = document.querySelector('meta[name="csrf-token"]'), saved = text.value, dirty = false, busy = false, prevReadOnly = text.readOnly;
    var grow = root.classList.contains('gh-desc');
    var size = function () { if (!grow) { return; } text.style.height = 'auto'; text.style.height = text.scrollHeight + 'px'; };
    // state: working | pending | unconfirmed | completed | failed. Only `completed` is ever presented as success, and only once the workflow says so;
    // only `failed` says nothing was changed, and only when the server itself refused the request or the workflow definitively failed.
    var show = function (state, head, body) { box.hidden = false; box.setAttribute('data-state', state); title.textContent = head; detail.textContent = body || ''; if (refresh) { refresh.hidden = state !== 'unconfirmed'; } };
    if (refresh) { refresh.addEventListener('click', function () { window.location.reload(); }); }
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
    // The outcome is UNKNOWN (lost response, network error, server error, an answer that cannot say what happened). The approval may already be
    // received or even saved, so nothing is claimed either way, the controls stay locked (no resubmission) and the customer refreshes to check.
    var unconfirmed = function (more) { show('unconfirmed', "We couldn't confirm whether your approval was saved.", more || "It may have been received. Refresh to check its status, and don't approve again until you have."); };
    var poll = function (url, tries) {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { if (!r.ok) { throw new Error('status ' + r.status); } return r.json(); }).then(function (j) {
            if (j.is_terminal) {
                if (j.opportunity_status === 'completed') { done(); }
                else if (j.execution_status === 'failed') { failed('We could not save the description.'); }
                else { unconfirmed(); }
            } else if (tries > 0) { setTimeout(function () { poll(url, tries - 1); }, 1500); }
            else { show('pending', 'Approval received. Saving has not been confirmed yet.', 'Refresh in a moment to see the result.'); }
        }).catch(function () { unconfirmed('Your approval was received, but its result could not be read. Refresh to check it.'); });
    };
    approve.addEventListener('click', function () {
        if (busy) { return; }
        var v = text.value.trim();
        if (v.length < 50) { show('failed', 'Write at least 50 characters before saving.', ''); return; }
        lock(true); show('working', 'Sending your approval…', '');
        post(root.dataset.approveUrl, { value: v, base: root.dataset.base }).then(function (j) {
            var st = j.state, http = j.http || 0;
            if (st === 'completed') { done(); }
            else if (st === 'pending' && j.statusUrl) { show('pending', 'Approval received. Saving to your profile…', 'This is not confirmed until MotionGrove verifies it.'); poll(j.statusUrl, 20); }
            else if (st === 'pending') { unconfirmed('Your approval was received, but its status could not be located. Refresh to check it.'); }
            else if (st === 'failed') { failed(j.message || 'We could not save the description.'); }
            else if (st === 'busy') { unconfirmed(j.message ? j.message + ' Refresh to check its status.' : ''); }
            // An explicit refusal: the server answered 4xx (its own rejection states, or middleware such as an expired session). Nothing ran.
            else if (http >= 400 && http < 500 && http !== 408) { failed(j.message || 'The request was not accepted.'); }
            // Anything else (5xx, a timeout, a 2xx that says nothing usable) cannot establish what happened.
            else { unconfirmed(); }
        }).catch(function () { unconfirmed(); });
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
