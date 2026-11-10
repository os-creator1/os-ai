{{--
    Inline "Complete your business description" proposal (the move's ONE primary action lives here).
    The text is a deterministic draft from confirmed saved facts (no AI on load). "Improve with AI" is an explicit,
    metered click; "Approve & save" posts to the trusted opportunity workflow and reports success only once verified.
--}}
@php($d = $needs['description'])
<div class="hv2-desc" data-role="description-proposal" data-approve-url="{{ $d['approveUrl'] }}" @if($d['improveUrl']) data-improve-url="{{ $d['improveUrl'] }}" @endif data-base="{{ $d['base'] }}" data-opportunity="{{ $d['uid'] }}" data-min="50" data-max="5000">
    <label class="hv2-desc__label" for="hv2-desc-text">{{ $d['fromFacts'] ? 'Proposed description — built from your saved business details' : 'Your business description' }}</label>
    <textarea id="hv2-desc-text" class="form-control hv2-desc__text" rows="4" maxlength="5000" readonly data-role="description-text" placeholder="Write 2–3 sentences about what you do and where you work (at least 50 characters).">{{ $d['text'] }}</textarea>
    <div class="hv2-needs__actions hv2-desc__actions">
        <button type="button" class="btn btn-primary hv2-btn" data-role="description-approve">Approve &amp; save</button>
        <button type="button" class="btn btn-outline-secondary hv2-btn" data-role="description-edit">Edit description</button>
        @if($d['improveUrl'])<button type="button" class="btn btn-outline-secondary hv2-btn" data-role="description-improve"><span class="hv2-spark" aria-hidden="true">✦</span> Improve with AI</button>@endif
    </div>
    <p class="hv2-muted hv2-desc__note mb-0">Saved to your MotionGrove business profile. This does not automatically update Google or publish your website.</p>
    <p class="hv2-desc__status mb-0" role="status" aria-live="polite" data-role="description-status" hidden></p>
</div>
<style>
    .hv2 .hv2-desc { margin-top: .5rem; display: grid; gap: .5rem; }
    .hv2 .hv2-desc__label { font-size: .8125rem; font-weight: 600; margin: 0; }
    .hv2 .hv2-desc__text { font-size: .9375rem; line-height: 1.5; }
    .hv2 .hv2-desc__text[readonly] { background: var(--hv2-card); }
    .hv2 .hv2-desc__actions { display: flex; flex-wrap: wrap; gap: .5rem; }
    .hv2 .hv2-desc__note, .hv2 .hv2-desc__status { font-size: .8125rem; }
    @media (max-width: 575px) { .hv2 .hv2-desc__actions .hv2-btn { flex: 1 1 100%; min-height: 2.75rem; } }
</style>
<script>
(function () {
    var root = document.querySelector('[data-role="description-proposal"]');
    var q = function (r) { return root.querySelector('[data-role="' + r + '"]'); };
    var text = q('description-text'), status = q('description-status'), approve = q('description-approve'), improve = q('description-improve'), edit = q('description-edit');
    var token = document.querySelector('meta[name="csrf-token"]'), saved = text.value, dirty = false, busy = false, prevReadOnly = text.readOnly;
    var say = function (m, bad) { status.hidden = false; status.textContent = m; status.className = 'hv2-desc__status mb-0 ' + (bad ? 'text-danger' : 'text-success'); };
    var post = function (url, body) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j.http = r.status; return j; }); });
    };
    // While a request is in flight the text cannot change: it is read-only, and its previous editable/read-only state returns afterwards.
    var lock = function (on) { if (on) { prevReadOnly = text.readOnly; text.readOnly = true; } else { text.readOnly = prevReadOnly; } busy = on; approve.disabled = on; if (improve) improve.disabled = on; edit.disabled = on; };
    text.addEventListener('input', function () { dirty = true; });
    edit.addEventListener('click', function () { text.readOnly = false; text.focus(); });
    var poll = function (url, tries) {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            if (j.is_terminal) {
                if (j.opportunity_status === 'completed') { done(); } else { lock(false); say('We could not save the description. Nothing was changed.', true); }
            } else if (tries > 0) { setTimeout(function () { poll(url, tries - 1); }, 1500); } else { say('Still saving. Refresh in a moment to see the result.'); }
        }).catch(function () { lock(false); say('We could not confirm the save. Refresh to check.', true); });
    };
    var done = function () { text.readOnly = true; say('Saved and verified in your MotionGrove business profile.'); approve.hidden = true; edit.hidden = true; if (improve) improve.hidden = true; };
    approve.addEventListener('click', function () {
        if (busy) { return; }
        var v = text.value.trim();
        if (v.length < 50) { say('Write at least 50 characters before saving.', true); return; }
        lock(true); say('Saving…');
        post(root.dataset.approveUrl, { value: v, base: root.dataset.base }).then(function (j) {
            if (j.state === 'completed') { done(); }
            else if (j.state === 'pending') { poll(j.statusUrl, 20); }
            else { lock(false); say(j.message || 'We could not save the description.', true); }
        }).catch(function () { lock(false); say('We could not save the description.', true); });
    });
    if (improve) {
        improve.addEventListener('click', function () {
            if (busy) { return; }
            if (dirty && text.value.trim() !== saved.trim() && !window.confirm('Replace your edits with an AI suggestion?')) { return; }
            var before = text.value;
            lock(true); say('Asking AI for a suggestion…');
            post(root.dataset.improveUrl, { text: text.value, request_id: String(Date.now()) + Math.random().toString(36).slice(2) }).then(function (j) {
                lock(false);
                if (j.ok && j.text) {
                    // Newer customer text is never silently discarded.
                    if (text.value !== before && !window.confirm('Your text changed while AI was working. Replace it with the suggestion?')) { say('Suggestion discarded. Your text is unchanged.'); return; }
                    text.value = j.text; text.readOnly = false; dirty = false; saved = j.text; say('Suggestion ready. Review it, then approve to save.');
                }
                else { say(j.message || 'AI suggestions are not available right now.', true); }
            }).catch(function () { lock(false); say('AI suggestions are not available right now.', true); });
        });
    }
})();
</script>
