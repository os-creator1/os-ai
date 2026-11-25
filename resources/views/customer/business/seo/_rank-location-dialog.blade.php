{{--
    "Where do customers search from?" — the one chooser for every place that needs a search
    location (Add keyword, Start tracking, Track suggestions). Visual spec: the
    "Search keywords — choose tracking city" design export. Behaviour (Bootstrap modal focus
    trap, Escape, focus return) lives in the page script; the dialog never submits a typed
    string, only a location code picked from the cached catalogue.
--}}
<style>
    .kwd-modal { --kwd-brand: #B4514A; --kwd-brand-d: #8F3B35; --kwd-ink: #1F1A19; --kwd-mute: #5E5652; --kwd-mute2: #6B625E; --kwd-faint: #8A817C; --kwd-line: #E9E4DF; --kwd-line2: #F0EBE6; --kwd-soft: #FBF9F7; background: rgba(31, 26, 25, .38); font-family: inherit; }
    .kwd-modal .modal-dialog { width: 520px; max-width: calc(100% - 32px); margin: 0 auto; padding-top: 120px; }
    .kwd-modal.modal.fade .modal-dialog { transform: none; }
    .kwd-modal .kwd-card { pointer-events: auto; border: 0; border-radius: 18px; background: #fff; box-shadow: 0 24px 64px rgba(31, 26, 25, .28); overflow: visible; color: var(--kwd-ink); }
    .kwd-head { display: flex; align-items: flex-start; gap: 12px; padding: 22px 24px 6px; }
    .kwd-head > div { flex: 1; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .kwd-head h2 { margin: 0; font-size: 20px; font-weight: 800; line-height: 1.25; color: var(--kwd-ink); }
    .kwd-head span { font-size: 13.5px; color: var(--kwd-mute); }
    .kwd-close { width: 36px; height: 36px; flex: none; border: 0; border-radius: 10px; background: #F4F0EC; color: #4A4340; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
    .kwd-close:hover { background: #ECE6E0; }
    .kwd-ic { width: 16px; height: 16px; flex: none; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .kwd-ic-sm { width: 14px; height: 14px; }
    .kwd-body { padding: 12px 24px 20px; display: flex; flex-direction: column; gap: 12px; }
    .kwd-kw { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; background: var(--kwd-soft); border: 1px solid var(--kwd-line2); }
    .kwd-kw > i { width: 28px; height: 28px; flex: none; border-radius: 8px; background: #F8E9E6; color: var(--kwd-brand-d); display: flex; align-items: center; justify-content: center; font-style: normal; font-weight: 800; }
    .kwd-kw b { flex: 1; min-width: 0; font-size: 14.5px; overflow-wrap: anywhere; }
    .kwd-kw span { font-size: 12.5px; color: var(--kwd-mute2); white-space: nowrap; }
    .kwd-field-wrap { position: relative; display: flex; flex-direction: column; gap: 12px; }
    .kwd-field { display: flex; align-items: center; gap: 8px; height: 48px; margin: 0; padding: 0 14px; border: 1px solid #D6CFC8; border-radius: 12px; background: #fff; color: var(--kwd-faint); }
    .kwd-field:focus-within { border-color: var(--kwd-brand); box-shadow: 0 0 0 3px #F8E9E6; }
    .kwd-field input { flex: 1; min-width: 0; height: 100%; border: 0; outline: 0; background: transparent; padding: 0; font: inherit; font-size: 15px; color: var(--kwd-ink); }
    .kwd-field input::placeholder { color: #B5ADA7; }
    .kwd-results { margin-top: -6px; padding: 6px; border: 1px solid var(--kwd-line); border-radius: 12px; background: #fff; box-shadow: 0 12px 28px rgba(31, 26, 25, .12); max-height: 264px; overflow-y: auto; }
    .kwd-opt { display: flex; align-items: center; gap: 10px; min-height: 44px; padding: 0 12px; border-radius: 9px; cursor: pointer; }
    .kwd-opt .kwd-ic { width: 15px; height: 15px; color: var(--kwd-brand-d); }
    .kwd-opt b { flex: 1; min-width: 0; font-size: 14px; font-weight: 600; }
    .kwd-opt b u { text-decoration: underline; }
    .kwd-opt span { font-size: 12.5px; color: var(--kwd-mute2); text-align: right; }
    .kwd-opt.is-active { background: #FBF0EE; }
    .kwd-hintrow { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--kwd-mute2); }
    .kwd-hintrow .kwd-ic { color: var(--kwd-mute2); }
    .kwd-hintrow .kwd-hint { flex: 1; min-width: 0; }
    .kwd-hintrow .kwd-hint.text-danger { color: #B3261E !important; }
    .kwd-fromkw { white-space: nowrap; }
    .kwd-fromkw button { border: 0; background: transparent; padding: 0; font: inherit; font-weight: 600; color: var(--kwd-brand-d); }
    .kwd-fromkw button:hover { text-decoration: underline; }
    .kwd-foot { display: flex; align-items: center; gap: 10px; padding: 14px 24px; border-top: 1px solid var(--kwd-line2); background: var(--kwd-soft); border-radius: 0 0 18px 18px; }
    .kwd-skip { flex: 1; text-align: left; min-height: 44px; padding: 0; border: 0; background: transparent; font-size: 13.5px; font-weight: 600; color: var(--kwd-mute2); }
    .kwd-skip:hover { color: var(--kwd-brand-d); }
    .kwd-foot .kwd-spacer { flex: 1; }
    .kwd-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; box-sizing: border-box; padding: 0 16px; border-radius: 10px; border: 0; font-size: 14px; font-weight: 600; white-space: nowrap; background: transparent; color: var(--kwd-mute); }
    .kwd-btn:hover { background: #F4F0EC; }
    .kwd-btn.is-primary { background: var(--kwd-brand); color: #fff; }
    .kwd-btn.is-primary:hover { background: var(--kwd-brand-d); }
    .kwd-btn.is-primary[disabled] { opacity: .55; cursor: not-allowed; background: var(--kwd-brand); }
    .kwd-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    @media (max-width: 575.98px) {
        .kwd-modal .modal-dialog { padding-top: 56px; }
        .kwd-head, .kwd-body, .kwd-foot { padding-left: 16px; padding-right: 16px; }
        .kwd-foot { flex-wrap: wrap; }
        .kwd-skip { flex-basis: 100%; min-height: 36px; }
        .kwd-foot .kwd-btn { flex: 1; }
        .kwd-hintrow { flex-wrap: wrap; }
        .kwd-kw { flex-wrap: wrap; }
    }
</style>
<div class="modal kwd-modal" id="rank-location-dialog" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="rank-location-dialog-label" aria-hidden="true" data-bs-backdrop="false" data-role="rank-location-dialog">
    <div class="modal-dialog">
        <section class="kwd-card" role="document">
            <div class="kwd-head">
                <div>
                    <h2 id="rank-location-dialog-label">Where do customers search from?</h2>
                    <span>We'll check Google rank as if searching from this city.</span>
                </div>
                <button type="button" class="kwd-close" data-bs-dismiss="modal" aria-label="Close"><svg class="kwd-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg></button>
            </div>
            <div class="kwd-body">
                <div class="kwd-kw" data-role="rank-location-keyword">
                    <i aria-hidden="true">#</i><b data-role="rank-location-keyword-phrase"></b><span data-role="rank-location-keyword-scope"></span>
                </div>
                @include('customer.business.seo._rank-location-field', ['url' => $url, 'fieldId' => 'rank-dialog-location'])
                <span class="kwd-sr" id="rank-location-confirm-hint" data-role="rank-location-confirm-hint">Start tracking turns on once you pick a city from the list.</span>
            </div>
            <div class="kwd-foot">
                <button type="button" class="kwd-skip d-none" data-role="rank-location-skip">Add without tracking</button>
                <span class="kwd-spacer" data-role="rank-location-spacer"></span>
                <button type="button" class="kwd-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="kwd-btn is-primary" data-role="rank-location-confirm" aria-describedby="rank-location-confirm-hint" disabled>Start tracking</button>
            </div>
        </section>
    </div>
</div>
