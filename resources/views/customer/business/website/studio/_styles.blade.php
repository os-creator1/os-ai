{{--
    The ONE scoped stylesheet for the Website builder surfaces: the studio shell + its five tabs (Website, Packages, Forms,
    Questionnaires, Settings) and the change-template flow (choose, confirm dialog, rebuilding panel).
    Everything is scoped under `.wb` (the wrapper the shell and the rebuild page emit), so nothing leaks into the rest of the
    app. Values (type scale, radii, borders, colours, spacing) follow the supplied design references.
    The generation-progress panel is styled here too, but only inside `.wb`, so other pages that include the same template keep
    their own look.
--}}
<style>
    .wb {
        --wb-brand: var(--color-primary, #B4514A);
        --wb-brand-ink: #8F3B35;
        --wb-brand-soft: #F8E9E6;
        --wb-text: #1F1A19;
        --wb-text-2: #5E5652;
        --wb-muted: #6B625E;
        --wb-faint: #8A817C;
        --wb-border: #E9E4DF;
        --wb-border-strong: #D6CFC8;
        --wb-hairline: #F0EBE6;
        --wb-surface: #FFFFFF;
        --wb-surface-2: #FBF9F7;
        --wb-ok: #1F8A55;
        --wb-ok-bg: #E3F3EA;
        --wb-ok-ink: #17693F;
        --wb-warn: #D9A441;
        --wb-warn-bg: #FDF6E3;
        --wb-warn-bg-2: #FDF1DC;
        --wb-warn-border: #EBD6A8;
        --wb-warn-ink: #7A4A05;
        font-family: 'Figtree', var(--font-family-sans, system-ui), system-ui, sans-serif;
        color: var(--wb-text);
        min-width: 0;
        max-width: 100%;
    }

    .wb *, .wb *::before, .wb *::after { box-sizing: border-box; }
    .wb a { text-decoration: none; }
    .wb svg { flex: none; }
    .wb .wb-muted { font-size: .78125rem; color: var(--wb-muted); }
    .wb .wb-sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

    /* ---------- Buttons (reference: 44px, 10px radius, 600) ---------- */
    .wb h1, .wb h2, .wb h3, .wb h4, .wb h5, .wb h6 { color: var(--wb-text); }

    .wb .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
        min-height: 44px; padding: 0 1rem; border-radius: 10px;
        font-size: .875rem; font-weight: 600; white-space: nowrap;
    }
    .wb .btn.btn-sm { min-height: 36px; padding: 0 .75rem; font-size: .8125rem; }
    .wb .btn-primary { background: var(--wb-brand); border-color: var(--wb-brand); color: #fff; }
    .wb .btn-primary:hover, .wb .btn-primary:focus-visible { background: var(--wb-brand-ink); border-color: var(--wb-brand-ink); color: #fff; }
    .wb .btn-outline,
    .wb .btn-secondary,
    .wb .btn-outline-secondary { background: #fff; border: 1px solid var(--wb-border-strong); color: #2E2826; }
    .wb .btn-outline:hover, .wb .btn-secondary:hover, .wb .btn-outline-secondary:hover { border-color: var(--wb-brand); color: var(--wb-brand-ink); background: #fff; }
    .wb .btn-ghost { background: transparent; border: 0; color: var(--wb-text-2); }

    /* ---------- Header + tabs ---------- */
    .wb-head { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .75rem; margin-bottom: 1.125rem; }
    .wb-head-text { flex: 1 1 18rem; min-width: 0; display: flex; flex-direction: column; gap: .375rem; }
    .wb-title { margin: 0; font-size: 1.75rem; font-weight: 800; letter-spacing: -.02em; line-height: 1.15; color: var(--wb-text); }
    .wb-subline { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; font-size: .84375rem; color: var(--wb-muted); min-width: 0; }
    .wb-subline > span { overflow-wrap: anywhere; }
    .wb-status { display: inline-flex; align-items: center; gap: .375rem; height: 24px; padding: 0 .5rem; border-radius: 999px; font-size: .71875rem; font-weight: 700; white-space: nowrap; background: #F1EDE9; color: var(--wb-text-2); }
    .wb-status::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .wb-status.is-published { background: var(--wb-ok-bg); color: var(--wb-ok-ink); }
    .wb-status.is-published::before { background: var(--wb-ok); }
    .wb-status.is-draft { background: var(--wb-warn-bg-2); color: var(--wb-warn-ink); }
    .wb-head-actions { display: flex; flex-wrap: wrap; gap: .625rem; }

    .website-tabs.nav {
        position: relative; display: flex; flex-wrap: nowrap; gap: 1.625rem; margin: 0 0 1.125rem; padding: 0;
        overflow-x: auto; scrollbar-width: none; border-bottom: 1px solid var(--wb-border); background: none;
    }
    .website-tabs::-webkit-scrollbar { display: none; }
    .website-tabs .nav-link {
        display: inline-flex; align-items: center; min-height: 44px; padding: 0; margin-bottom: -1px; white-space: nowrap;
        font-size: .90625rem; font-weight: 500; color: var(--wb-muted); background: none; border: 0; border-bottom: 2px solid transparent; border-radius: 0;
    }
    .website-tabs .nav-link:hover, .website-tabs .nav-link:focus-visible { color: var(--wb-brand-ink); }
    .website-tabs .nav-link.active { font-weight: 600; color: var(--wb-brand-ink); background: none; border-bottom-color: var(--wb-brand); box-shadow: none; }

    /* ---------- Cards ---------- */
    .wb .wb-card, .wb .card.wb-card { background: var(--wb-surface); border: 1px solid var(--wb-border); border-radius: 16px; box-shadow: none; }
    .wb .card { border-radius: 16px; border-color: var(--wb-border); box-shadow: none; }
    .wb-tile { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 42px; height: 42px; border-radius: 12px; background: var(--wb-brand-soft); color: var(--wb-brand-ink); }
    .wb-tile.is-amber { background: var(--wb-warn-bg-2); color: var(--wb-warn-ink); }

    /* ---------- Alerts (x-alert, restyled inside the builder) ---------- */
    .wb .alert { border-radius: 14px; padding: .875rem 1.25rem; font-size: .84375rem; }
    .wb .alert-warning, .wb .notice-warning { background: var(--wb-warn-bg); border: 1px solid var(--wb-warn-border); color: var(--wb-warn-ink); }

    /* ---------- Overview ---------- */
    .website-overview { display: flex; flex-direction: column; gap: 1.125rem; min-width: 0; }
    .website-overview > * { margin-top: 0 !important; }

    .website-actions {
        display: flex; flex-wrap: wrap; align-items: center; gap: .875rem; padding: 1rem 1.25rem;
        border-radius: 14px; background: var(--wb-surface); border: 1px solid var(--wb-border);
    }
    .website-actions.is-draft { background: var(--wb-warn-bg); border-color: var(--wb-warn-border); }
    .website-actions-text { flex: 1 1 18rem; min-width: 0; display: flex; align-items: center; gap: .875rem; }
    .website-actions-text > div { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .website-actions-text b { font-size: .9375rem; color: #3D2A05; }
    .website-actions-text span.sub { font-size: .8125rem; color: var(--wb-warn-ink); }
    .website-actions:not(.is-draft) .website-actions-text b { color: var(--wb-text); }
    .website-actions:not(.is-draft) .website-actions-text span.sub { color: var(--wb-muted); }
    .website-actions form { display: inline-flex; margin: 0; }

    .website-previews { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.125rem; }
    .website-preview-col { display: flex; flex-direction: column; gap: .5rem; min-width: 0; }
    .website-preview-label { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin: 0; font-size: .84375rem; font-weight: 600; color: var(--wb-text); }
    .website-preview-label .wb-label-note { margin-left: auto; font-weight: 400; font-size: .78125rem; color: var(--wb-muted); }
    .website-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--wb-border-strong); flex: none; }
    .website-dot.is-live { background: var(--wb-ok); }
    .website-dot.is-draft { background: var(--wb-warn); }
    .website-chip { padding: .1rem .55rem; border-radius: 999px; font-size: .71875rem; font-weight: 700; color: var(--wb-warn-ink); background: var(--wb-warn-bg-2); }

    .website-preview-frame {
        display: block; position: relative; aspect-ratio: 16 / 10; overflow: hidden;
        border: 1px solid var(--wb-border); border-radius: 12px; background: #0E1A33; box-shadow: none;
        transition: box-shadow .15s ease, border-color .15s ease;
    }
    a.website-preview-frame:hover, a.website-preview-frame:focus-visible { border-color: var(--wb-brand); box-shadow: 0 4px 14px rgba(180, 81, 74, .12); }
    .website-preview-frame iframe { position: absolute; top: 0; left: 0; border: 0; pointer-events: none; background: #fff; visibility: hidden; }
    .website-preview-frame.is-fitted iframe { visibility: visible; }
    .website-preview-frame.is-empty { display: flex; align-items: center; justify-content: center; padding: 1rem; text-align: center; background: var(--wb-surface-2); border-style: dashed; color: var(--wb-muted); }
    .website-preview-frame.is-quiet { aspect-ratio: auto; min-height: 5rem; }
    .website-preview-meta { align-self: flex-start; font-size: .8125rem; color: var(--wb-muted); overflow-wrap: anywhere; }

    .wb-split { display: flex; flex-wrap: wrap; gap: 1.125rem; align-items: flex-start; }
    .wb-split > * { flex: 1 1 24rem; min-width: 0; }
    .website-next-step.card, .website-health-fold {
        display: block; padding: 1.125rem 1.25rem; background: var(--wb-surface); border: 1px solid var(--wb-border); border-radius: 16px; box-shadow: none;
    }
    .website-next-step .card-body { padding: 0; }
    .wb-domain-next { display: flex; align-items: center; gap: .875rem; }
    .wb-domain-next .wb-grow { flex: 1 1 auto; min-width: 0; }
    .wb-domain-next h6 { margin: 0; font-size: .9375rem; font-weight: 700; }
    .wb-domain-next .wb-muted { margin: 0; }

    .website-health-fold > summary { cursor: pointer; list-style: none; display: flex; align-items: center; gap: .875rem; font-weight: 700; }
    .website-health-fold > summary::-webkit-details-marker { display: none; }
    .wb-health-main { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: .5rem; }
    .wb-health-line { display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem; }
    .wb-health-line b { font-size: .9375rem; }
    .wb-health-bar { display: flex; gap: 2px; height: 8px; border-radius: 999px; overflow: hidden; background: #EEE9E4; }
    .wb-health-bar span { display: block; min-width: 4px; }
    .wb-health-bar .ok { background: var(--wb-ok); }
    .wb-health-bar .warn { background: var(--wb-warn); }
    .wb-health-bar .fail { background: #C2410C; }
    .wb-link { display: inline-flex; align-items: center; gap: .25rem; font-size: .84375rem; font-weight: 600; color: var(--wb-brand-ink); white-space: nowrap; }
    .website-health-fold[open] .wb-link svg { transform: rotate(90deg); }
    .website-health-fold > .wb-health-body { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--wb-hairline); }

    /* ---------- Settings ---------- */
    .website-settings { display: flex; flex-direction: column; gap: 1.125rem; min-width: 0; }
    .website-settings > .mb-2 { margin-bottom: 0 !important; }
    .website-domain-card { display: flex; flex-wrap: wrap; align-items: center; gap: .875rem; padding: 1rem 1.25rem; border: 1px solid var(--wb-border); border-radius: 14px; background: var(--wb-surface); }
    .website-domain-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 42px; height: 42px; border-radius: 12px; background: var(--wb-warn-bg-2); color: var(--wb-warn-ink); }
    .website-domain-body { flex: 1 1 18rem; min-width: 0; }
    .website-domain-body strong { font-size: .9375rem; }
    .website-domain-body p { margin: .125rem 0 0; color: var(--wb-muted); }
    .website-domain-action { flex: 0 0 auto; white-space: nowrap; }

    .website-settings-grid { display: flex; flex-wrap: wrap; gap: 1.125rem; align-items: flex-start; }
    .website-settings-grid > div { min-width: 0; }
    .website-settings-grid > div:first-child { flex: 1 1 22.5rem; }
    .website-settings-grid > div:last-child { flex: 1.4 1 28.75rem; }
    .website-settings-grid .card { overflow: hidden; }
    .website-settings-heading { margin: 0; padding: 1rem 1.25rem; font-size: 1rem; font-weight: 700; }

    a.website-settings-row { display: flex; align-items: center; gap: .875rem; min-height: 64px; padding: .625rem 1.25rem; color: var(--wb-text); }
    .website-settings-list li { border-top: 1px solid var(--wb-hairline); }
    a.website-settings-row:hover, a.website-settings-row:focus-visible { color: var(--wb-text); background: #FCFAF8; }
    .website-settings-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 36px; height: 36px; border-radius: 10px; background: var(--wb-brand-soft); color: var(--wb-brand-ink); }
    .website-settings-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 auto; }
    .website-settings-title { font-weight: 600; font-size: .90625rem; }
    .website-settings-sub { font-size: .78125rem; color: var(--wb-muted); }
    .website-settings-meta { display: flex; flex-direction: column; align-items: flex-end; flex: 0 0 auto; font-size: .78125rem; color: var(--wb-muted); text-align: right; }
    .website-settings-chevron { flex: 0 0 auto; color: #B9AFA8; }

    /* Look card */
    .wb-look-title { margin: 0; font-size: 1rem; font-weight: 700; }
    .wb-look-group { margin: 1.125rem 0 0; display: flex; flex-direction: column; }
    .wb-eyebrow { display: block; margin-bottom: .625rem; font-size: .75rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--wb-muted); }
    .website-look-field { margin: 0; padding: .625rem 0; border-top: 1px solid var(--wb-hairline); }
    .wb-look-group > .website-look-field:first-of-type { border-top: 0; }
    .website-look-label { display: block; margin-bottom: .25rem; font-size: .84375rem; font-weight: 600; color: #2E2826; }
    details.website-look-field > summary { cursor: pointer; margin: 0; color: var(--wb-brand-ink); font-size: .78125rem; }
    .website-look-color { display: flex; align-items: center; gap: .75rem; max-width: 100%; }
    .website-look-color .form-control { max-width: 11rem; font-family: ui-monospace, Menlo, monospace; font-size: .8125rem; }
    .website-look-swatch { flex: 0 0 auto; width: 36px; height: 36px; padding: 0; border: 1px solid #E0DAD4; border-radius: 10px; background: none; cursor: pointer; }
    .website-look-swatch::-webkit-color-swatch-wrapper { padding: 0; }
    .website-look-swatch::-webkit-color-swatch { border: 0; border-radius: 9px; }
    .website-look-swatch::-moz-color-swatch { border: 0; border-radius: 9px; }
    .website-look-upload { display: flex; align-items: center; gap: .875rem; padding: .75rem; border: 1px solid var(--wb-border); border-radius: 12px; background: var(--wb-surface); }
    .website-look-upload-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 64px; height: 48px; border-radius: 8px; background: var(--wb-surface-2); border: 1px solid var(--wb-border); color: var(--wb-brand-ink); }
    .website-look-upload-text { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; font-size: .875rem; }
    .website-look-upload-text strong { font-weight: 600; }
    .website-look-upload-text .text-caption { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--wb-muted); }
    .website-look-choose { flex: 0 0 auto; }
    .website-look-thumb { flex: 0 0 auto; width: 64px; height: 48px; object-fit: cover; border-radius: 8px; border: 1px solid var(--wb-border); }
    .website-look-thumb--logo { object-fit: contain; background: #fff; }
    .wb .website-look-field .form-control { min-height: 46px; border-radius: 10px; border-color: var(--wb-border-strong); font-size: .9375rem; }
    .wb .website-look-field .form-control:focus { border-color: var(--wb-brand); box-shadow: 0 0 0 3px var(--wb-brand-soft); }
    .wb .website-look-color .form-control { font-size: .8125rem; }

    /* ---------- Packages ---------- */
    .mg-pkg-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .625rem; margin-bottom: 1.125rem; }
    .mg-pkg-sub { flex: 1 1 14rem; margin: 0; font-size: .875rem; color: var(--wb-text-2); }
    .mg-pkg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 1rem; align-items: stretch; }
    .mg-pkg { position: relative; display: flex; flex-direction: column; overflow: hidden; background: var(--wb-surface); border: 1px solid var(--wb-border); border-radius: 16px; }
    .mg-pkg.is-featured { border-color: var(--wb-brand); box-shadow: 0 0 0 3px var(--wb-brand-soft); }
    .mg-pkg-band { position: relative; height: 120px; background: linear-gradient(120deg, #4A6A94, #1C2D4F); }
    .mg-pkg-tag { position: absolute; left: 12px; top: 12px; display: inline-flex; align-items: center; gap: .25rem; height: 22px; padding: 0 .5rem; border-radius: 999px; font-size: .71875rem; font-weight: 700; background: rgba(255, 255, 255, .92); color: #2E2826; }
    .mg-pkg-main { display: flex; flex-direction: column; gap: .375rem; flex: 1 1 auto; padding: 1rem 1.125rem; }
    .mg-pkg-name { margin: 0; font-size: 1rem; font-weight: 700; overflow-wrap: anywhere; }
    .mg-pkg-body { flex: 1 1 auto; }
    .mg-pkg-desc { margin: 0; font-size: .84375rem; line-height: 1.45; color: var(--wb-text-2); overflow-wrap: anywhere; }
    .mg-pkg-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; font-size: .84375rem; color: var(--wb-text-2); }
    .mg-pkg-list li { display: flex; gap: .5rem; align-items: flex-start; overflow-wrap: anywhere; }
    .mg-pkg-list svg { margin-top: .2rem; color: var(--wb-ok); }
    .mg-pkg-foot { display: flex; align-items: center; gap: .625rem; margin-top: .5rem; }
    .mg-pkg-price { flex: 1 1 auto; min-width: 0; font-size: .9375rem; font-weight: 700; overflow-wrap: anywhere; }
    .mg-pkg-quote { font-weight: 700; }
    .mg-pkg-add { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .625rem; min-height: 260px; padding: 1rem; border: 1.5px dashed var(--wb-border-strong); border-radius: 16px; color: var(--wb-brand-ink); font-size: .90625rem; font-weight: 600; text-align: center; }
    .mg-pkg-add:hover, .mg-pkg-add:focus-visible { border-color: var(--wb-brand); color: var(--wb-brand-ink); background: var(--wb-surface-2); }
    .mg-pkg-add .wb-tile { width: 44px; height: 44px; }
    .mg-pkg-add small { font-weight: 400; font-size: .78125rem; color: var(--wb-muted); }

    /* ---------- Forms + Questionnaires ---------- */
    .wb-empty { display: flex; flex-direction: column; align-items: center; gap: .875rem; padding: 3.5rem 2rem; text-align: center; background: var(--wb-surface); border: 1px solid var(--wb-border); border-radius: 16px; }
    .wb-empty .wb-tile { width: 64px; height: 64px; border-radius: 18px; }
    .wb-empty h2 { margin: 0; font-size: 1.125rem; font-weight: 800; }
    .wb-empty p { margin: 0; max-width: 28rem; font-size: .875rem; line-height: 1.5; color: var(--wb-text-2); }
    .wb-empty-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: .625rem; }
    .wb-empty-hint { font-size: .78125rem; color: var(--wb-muted); }
    .wb-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr)); gap: 1rem; }
    .wb-list-card { display: flex; flex-direction: column; gap: .625rem; padding: 1.125rem; background: var(--wb-surface); border: 1px solid var(--wb-border); border-radius: 16px; min-width: 0; }
    .wb-list-card h2 { margin: 0; font-size: 1rem; font-weight: 700; overflow-wrap: anywhere; }
    .wb-list-card p { margin: 0; font-size: .8125rem; color: var(--wb-muted); }
    .wb-list-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: auto; padding-top: .25rem; }

    /* ---------- Change template ---------- */
    .wb-back { display: inline-flex; align-items: center; gap: .375rem; margin-bottom: .75rem; font-size: .84375rem; font-weight: 600; color: var(--wb-brand-ink); }
    .wb-back:hover, .wb-back:focus-visible { color: var(--wb-brand); }
    .wb-step { display: flex; align-items: center; gap: .625rem; margin: 0 0 .75rem; padding: 0; font-size: 1rem; font-weight: 700; }
    .wb-step-n { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: var(--wb-brand-soft); color: var(--wb-brand-ink); font-size: .75rem; font-weight: 800; }
    .wb-flow { display: flex; flex-direction: column; gap: 1.5rem; }
    .wb-flow fieldset { margin: 0; padding: 0; border: 0; min-width: 0; }
    .wb-template-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 230px), 1fr)); gap: .875rem; }
    .wb-template { display: flex; flex-direction: column; gap: .75rem; height: 100%; padding: .75rem; background: var(--wb-surface); border: 1px solid var(--wb-border); border-radius: 16px; cursor: pointer; position: relative; min-width: 0; }
    .wb-template:hover { border-color: var(--wb-border-strong); }
    .wb-template.is-selected, .wb-template:has(input:checked) { border-color: var(--wb-brand); box-shadow: 0 0 0 3px var(--wb-brand-soft); }
    .wb-template input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
    .wb-template input[type="radio"]:focus-visible + .wb-template-shot { outline: 2px solid var(--wb-brand); outline-offset: 2px; }
    .wb-template-shot { position: relative; overflow: hidden; border-radius: 10px; border: 1px solid #EEE9E4; aspect-ratio: 16 / 10; background: #F1F1F1; }
    .wb-template-meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .wb-template-name { display: flex; align-items: center; gap: .5rem; font-weight: 700; font-size: .9375rem; }
    .wb-template-name .check { margin-left: auto; color: var(--wb-brand); visibility: hidden; }
    .wb-template:has(input:checked) .check { visibility: visible; }
    .wb-template-tag { display: inline-flex; align-items: center; height: 20px; padding: 0 .5rem; border-radius: 999px; font-size: .6875rem; font-weight: 700; background: #F1EDE9; color: var(--wb-text-2); }
    .wb-template-tagline { font-size: .8125rem; color: var(--wb-text-2); }
    .wb-options { display: flex; flex-wrap: wrap; gap: .875rem; }
    .wb-option { display: flex; gap: .875rem; align-items: flex-start; flex: 1 1 18rem; min-width: 0; padding: 1rem 1.125rem; background: var(--wb-surface); border: 1px solid var(--wb-border); border-radius: 14px; cursor: pointer; }
    .wb-option input { position: absolute; opacity: 0; pointer-events: none; }
    .wb-option .rd { flex: none; width: 20px; height: 20px; margin-top: 1px; border: 1.5px solid #CFC6BE; border-radius: 50%; background: #fff; }
    .wb-option:has(input:checked) { border-color: var(--wb-brand); background: #FBF0EE; }
    .wb-option:has(input:checked) .rd { border: 6px solid var(--wb-brand); }
    .wb-option:has(input:focus-visible) { outline: 2px solid var(--wb-brand); outline-offset: 2px; }
    .wb-option-text { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .wb-option-text b { font-size: .9375rem; }
    .wb-bar { position: sticky; bottom: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; padding: .875rem 1.125rem; background: var(--wb-surface); border: 1px solid var(--wb-border); border-radius: 14px; box-shadow: 0 -4px 16px rgba(31, 26, 25, .05); }
    .wb-bar-text { flex: 1 1 18rem; min-width: 0; font-size: .875rem; }
    .wb-bar .btn-ghost { color: var(--wb-text-2); }

    /* Confirm dialog (Bootstrap modal, restyled) */
    #rebuild-confirm .modal-content { border: 0; border-radius: 18px; box-shadow: 0 24px 64px rgba(31, 26, 25, .28); overflow: hidden; font-family: 'Figtree', system-ui, sans-serif; }
    #rebuild-confirm .modal-header { display: none; }
    #rebuild-confirm .modal-body { padding: 1.5rem 1.5rem .375rem; display: flex; flex-direction: column; gap: .375rem; }
    #rebuild-confirm .wb-modal-icon { width: 44px; height: 44px; border-radius: 12px; background: #FDF1DC; color: #7A4A05; display: flex; align-items: center; justify-content: center; margin-bottom: .375rem; }
    #rebuild-confirm .wb-modal-title { margin: 0; font-size: 1.25rem; font-weight: 800; color: #1F1A19; }
    #rebuild-confirm .wb-modal-text { margin: 0; font-size: .875rem; line-height: 1.5; color: #5E5652; }
    #rebuild-confirm .wb-modal-points { list-style: none; margin: .75rem 0 1.25rem; padding: .875rem 1rem; display: flex; flex-direction: column; gap: .5rem; border-radius: 12px; background: #FBF9F7; border: 1px solid #F0EBE6; font-size: .84375rem; }
    #rebuild-confirm .wb-modal-points li { display: flex; gap: .5rem; align-items: flex-start; }
    #rebuild-confirm .wb-modal-points svg { margin-top: .15rem; color: #1F8A55; flex: none; }
    #rebuild-confirm .modal-footer { justify-content: flex-end; gap: .625rem; padding: .875rem 1.5rem; border-top: 1px solid #F0EBE6; background: #FBF9F7; }
    #rebuild-confirm .modal-footer .btn { min-height: 44px; border-radius: 10px; font-weight: 600; font-size: .875rem; }
    #rebuild-confirm .modal-footer .btn-primary { background: #B4514A; border-color: #B4514A; color: #fff; padding: 0 1.25rem; }
    #rebuild-confirm .modal-footer .btn-outline-secondary { border-color: transparent; background: transparent; color: #5E5652; }

    /* Rebuilding panel (the shared generation-progress template, inside the builder) */
    .wb .wb-progress { max-width: 32.5rem; margin: 1.25rem auto; padding: 1.75rem; text-align: left !important; background: var(--wb-surface); border: 1px solid var(--wb-border); border-radius: 16px; display: flex; flex-direction: column; gap: 1.125rem; }
    .wb .wb-progress > .wb-only { display: flex !important; }
    .wb .wb-progress > .wb-progress-track { display: block !important; }
    .wb .wb-progress .motiongrove { display: none; }
    .wb .wb-progress [data-testid="generation-progress-text"] { margin: 0 !important; font-size: .90625rem; font-weight: 600; }
    .wb .wb-progress-track { position: relative; height: 8px; border-radius: 999px; background: #EEE9E4; overflow: hidden; }
    .wb .wb-progress-track span { position: absolute; top: 0; bottom: 0; width: 40%; border-radius: 999px; background: var(--wb-brand); animation: wb-slide 1.4s ease-in-out infinite; }
    .wb .wb-progress-step { display: flex; align-items: center; gap: .75rem; font-size: .90625rem; font-weight: 600; }
    .wb .wb-progress-spin { flex: none; width: 26px; height: 26px; border-radius: 50%; border: 3px solid var(--wb-brand-soft); border-top-color: var(--wb-brand); animation: wb-spin .9s linear infinite; }
    .wb .wb-progress-note { display: flex; align-items: center; gap: .625rem; padding: .75rem .875rem; border-radius: 12px; background: var(--wb-warn-bg); font-size: .84375rem; color: var(--wb-warn-ink); }
    @keyframes wb-slide { 0% { left: -40%; } 100% { left: 100%; } }
    @keyframes wb-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
        .wb .wb-progress-track span { animation: none; left: 0; width: 100%; opacity: .6; }
        .wb .wb-progress-spin { animation: none; }
    }

    /* ---------- Responsive ---------- */
    @media (max-width: 767.98px) {
        .website-previews { grid-template-columns: minmax(0, 1fr); }
        .wb-title { font-size: 1.5rem; }
        .website-actions .btn, .website-actions form, .website-actions form .btn { flex: 1 1 auto; width: auto; }
        .website-actions form .btn { width: 100%; }
        .wb-head-actions .btn { flex: 1 1 auto; }
        .website-domain-action { width: 100%; }
        .wb-empty { padding: 2.5rem 1.25rem; }
        .website-look-upload { flex-wrap: wrap; }
        .website-look-upload-text { flex-basis: calc(100% - 5rem); }
        .wb-bar .btn { flex: 1 1 auto; }
    }
</style>
