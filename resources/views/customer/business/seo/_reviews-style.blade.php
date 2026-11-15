{{--
    Styles for SEO > Reviews. Values come from the approved design (Reviews.dc.html): 14px cards, 44px buttons,
    8px segmented bar, 40px avatars. Everything is scoped to .rv-page; class prefix `rv-` is local to this page.
--}}
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    .rv-page {
        --rv-brand: #B4514A; --rv-brand-d: #8F3B35; --rv-ink: #1F1A19; --rv-mute: #5E5652; --rv-line: #E9E4DF;
        display: flex; flex-direction: column; gap: 24px;
        font-family: 'Figtree', var(--font-family-app, system-ui), system-ui, -apple-system, 'Segoe UI', sans-serif;
        color: var(--rv-ink);
    }
    .rv-page > * { margin-bottom: 0 !important; }
    .rv-page a:hover { text-decoration: none; }
    .rv-page .card { margin-bottom: 0; border: 1px solid var(--rv-line); border-radius: 14px; background: #fff; box-shadow: none; }
    .rv-page .card-body { padding: 0; }

    .rv-page .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 600; line-height: 1.2; white-space: nowrap; box-shadow: none; }
    .rv-page .btn:focus-visible { outline: 2px solid var(--rv-brand); outline-offset: 2px; box-shadow: none; }
    .rv-page .btn.btn-primary { border: 0; background: var(--rv-brand); color: #fff; }
    .rv-page .btn.btn-primary:hover { background: var(--rv-brand-d); color: #fff; }
    .rv-page .btn.btn-secondary, .rv-page .btn.btn-outline-secondary { border: 1px solid #D6CFC8; background: #fff; color: #2E2826; }
    .rv-page .btn.btn-secondary:hover, .rv-page .btn.btn-outline-secondary:hover { background: #FBF9F7; color: #2E2826; border-color: #D6CFC8; }
    .rv-page .rv-link-actions .btn.btn-outline-secondary { border-color: #E3C3BF; color: var(--rv-brand-d); }
    .rv-page .rv-link-actions .btn.btn-outline-secondary:hover { background: #F8E9E6; color: var(--rv-brand-d); border-color: #E3C3BF; }

    .rv-header { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; }
    .rv-header > div:first-child { display: flex; flex-direction: column; gap: 6px; }
    .rv-header h1 { margin: 0; font-size: 28px; line-height: 1.2; font-weight: 700; letter-spacing: -.015em; color: var(--rv-ink); }
    .rv-sub { margin: 0; font-size: 15px; color: var(--rv-mute); }
    .rv-header-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

    /* ---- stat cards ---- */
    .rv-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .rv-stat { display: flex; flex-direction: column; gap: 10px; min-width: 0; padding: 18px 20px; border: 1px solid var(--rv-line); border-radius: 14px; background: #fff; }
    .rv-stat > p { margin: 0; }
    .rv-stat-warm { border-color: #EBD6A8; background: #fff; }
    .rv-stat-label { font-size: 13px; font-weight: 500; color: var(--rv-mute); }
    .rv-stat-value { font-size: 32px; line-height: 1; font-weight: 700; letter-spacing: -.02em; color: var(--rv-ink); }
    .rv-stat-value small { font-size: 13px; font-weight: 400; letter-spacing: 0; color: var(--rv-mute); }
    .rv-stat-value-text { font-size: 26px; line-height: 32px; }
    .rv-stat-value-warn { color: #7A4A05; }
    .rv-stat-value-ok { color: #17693F; }
    .rv-stat-sub { font-size: 13px; line-height: 1.4; color: var(--rv-mute); }
    .rv-bar { display: flex; gap: 2px; height: 8px; border-radius: 999px; overflow: hidden; }
    .rv-bar > span { display: block; min-width: 4px; }
    .rv-bar-ok { background: #1F8A55; }
    .rv-bar-wait { background: #D9A441; }
    .rv-bar-off { background: #B9AFA8; }

    /* ---- record panel: opens from the header button via the :target hash ---- */
    .rv-record { display: none; }
    .rv-record:target { display: block; }
    .rv-record .card-body { padding: 22px 24px; }
    .rv-record-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 4px; }
    .rv-record-head h2 { font-size: 17px; font-weight: 700; }
    .rv-record-forms { display: grid; gap: 16px; }
    .rv-record-fields { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px; }
    .rv-record-fields .rv-field { margin-bottom: 0; min-width: 12rem; flex: 1 1 12rem; }
    .rv-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
    .rv-page .form-select, .rv-page .form-control { min-height: 44px; border-radius: 10px; border-color: #D6CFC8; }
    .rv-page .form-select:focus, .rv-page .form-control:focus { border-color: var(--rv-brand); box-shadow: 0 0 0 3px #F8E9E6; }

    /* ---- layout: requests (3) beside the link column (2) ---- */
    .rv-layout { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 24px; }
    .rv-col-main { flex: 3 1 540px; min-width: 0; order: 1; }
    .rv-col-side { flex: 2 1 320px; min-width: 0; order: 2; display: flex; flex-direction: column; gap: 16px; }
    @media (max-width: 991.98px) { .rv-col-side { order: 0; flex-basis: 100%; } .rv-col-main { flex-basis: 100%; } }

    /* ---- location review-link card ---- */
    .rv-location .card-body { display: flex; flex-direction: column; gap: 16px; padding: 22px 24px; }
    .rv-location:target { border-color: #E3C3BF; box-shadow: 0 4px 14px rgba(180, 81, 74, .12); }
    .rv-location-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .rv-location-title { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .rv-location-title p { margin: 0; }
    .rv-location-icon { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; flex: none; border-radius: 10px; background: #F8E9E6; color: var(--rv-brand-d); }
    .rv-location-icon .ds-icon { width: 18px; height: 18px; }
    .rv-location-badges { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 6px; flex: none; }
    .rv-page .badge { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 10px; border-radius: 999px; font-size: 12.5px; font-weight: 600; line-height: 1; white-space: nowrap; border: 0; }
    .rv-page .badge.bg-success, .rv-page .badge.badge-success, .rv-page .badge[class*="success"] { background: #E3F3EA !important; color: #17693F !important; }
    .rv-page .badge[class*="warning"] { background: #FDF1DC !important; color: #7A4A05 !important; }
    .rv-page .badge[class*="neutral"], .rv-page .badge[class*="secondary"] { background: #EDE8E3 !important; color: #4A4340 !important; }
    .rv-page .badge .ds-icon { width: 12px; height: 12px; stroke-width: 2.6; }
    .rv-section-label { font-size: 17px; font-weight: 700; color: var(--rv-ink); overflow-wrap: anywhere; }
    .rv-location .rv-stat-sub { font-size: 13px; }
    .rv-link-wrap { display: flex; flex-direction: column; gap: 16px; }
    .rv-link-row { display: flex; flex-direction: column; gap: 16px; }
    .rv-link-box { display: flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 6px 0 14px; border: 1px solid #E0DAD4; border-radius: 10px; background: #FBF9F7; }
    .rv-link-field { flex: 1; min-width: 0; padding: 8px 0; border: 0; outline: 0; background: transparent; font-family: inherit; font-size: 14px; color: var(--rv-ink); text-overflow: ellipsis; }
    .rv-link-copy { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border: 0; border-radius: 8px; background: transparent; color: var(--rv-brand-d); cursor: pointer; }
    .rv-link-copy:hover { background: #F8E9E6; }
    .rv-link-copy:focus-visible { outline: 2px solid var(--rv-brand); outline-offset: 0; }
    .rv-link-copy .ds-icon { width: 18px; height: 18px; }
    .rv-link-actions { display: flex; flex-wrap: wrap; gap: 10px; }
    .rv-link-actions > * { flex: 1 1 130px; }
    .rv-link-actions .btn { justify-content: center; }
    .rv-panel { margin: 0; }
    .rv-panel > summary { list-style: none; cursor: pointer; }
    .rv-panel > summary::-webkit-details-marker { display: none; }
    .rv-change { display: inline-flex; align-items: center; gap: 6px; min-height: 44px; font-size: 14px; font-weight: 600; color: var(--rv-brand-d); }
    .rv-change .ds-icon { width: 16px; height: 16px; }
    .rv-change:hover { color: var(--rv-brand); }
    .rv-change:focus-visible { outline: 2px solid var(--rv-brand); outline-offset: 2px; border-radius: 6px; }
    .rv-panel-body { margin-top: 4px; padding: 16px; border: 1px solid #E0DAD4; border-radius: 10px; background: #FBF9F7; }
    .rv-note { display: flex; gap: 12px; align-items: flex-start; padding: 16px 18px; border: 1px solid #E0DAD4; border-radius: 14px; background: #FBF9F7; color: #4A4340; font-size: 13.5px; line-height: 1.5; }
    .rv-note .ds-icon { flex: none; width: 18px; height: 18px; margin-top: 1px; }
    .rv-note strong { font-weight: 600; color: var(--rv-ink); }

    .rv-empty { display: flex; align-items: center; gap: 16px; padding: 16px; border: 1.5px dashed #CFC6BE; border-radius: 12px; background: #FBF9F7; }
    .rv-empty p { margin: 0; }
    .rv-empty-quiet { margin: 16px 24px; border-style: solid; border-width: 1px; border-color: #E0DAD4; }
    .rv-empty-icon { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; flex: none; border-radius: 50%; background: #F8E9E6; color: var(--rv-brand-d); }

    /* ---- request ledger ---- */
    .rv-ledger { overflow: hidden; }
    .rv-ledger-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 24px; }
    .rv-ledger-head > div:first-child { display: flex; flex-direction: column; gap: 2px; }
    .rv-page h2.text-section-heading, .rv-ledger-head h2 { margin: 0; font-size: 17px; font-weight: 700; color: var(--rv-ink); }
    .rv-pills { display: inline-flex; align-items: center; gap: 4px; padding: 3px; border-radius: 10px; background: #F4F0EC; }
    .rv-pill { height: 38px; padding: 0 14px; border: 0; border-radius: 8px; background: transparent; font-family: inherit; font-size: 13.5px; font-weight: 500; color: var(--rv-mute); cursor: pointer; }
    .rv-pill:hover { color: var(--rv-ink); }
    .rv-pill:focus-visible { outline: 2px solid var(--rv-brand); outline-offset: 1px; }
    .rv-pill.is-active { background: #fff; color: var(--rv-ink); font-weight: 600; box-shadow: 0 1px 2px rgba(31, 26, 25, .12); }

    .rv-request { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 14px; padding: 18px 24px; border-top: 1px solid #EEE9E4; }
    .rv-request.is-awaiting { background: #FEFAEE; }
    .rv-request[hidden] { display: none; }
    .rv-avatar { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; flex: none; border-radius: 50%; background: #F8E9E6; color: var(--rv-brand-d); font-size: 13px; font-weight: 700; }
    .rv-avatar .ds-icon { width: 18px; height: 18px; }
    .rv-request.is-awaiting .rv-avatar:has(.ds-icon) { background: #EDE8E3; color: #4A4340; }
    .rv-request-main { display: flex; flex-direction: column; gap: 4px; flex: 1 1 260px; min-width: 0; }
    .rv-request-who { font-size: 15px; font-weight: 600; color: var(--rv-ink); overflow-wrap: anywhere; }
    .rv-request-who strong { font-weight: 600; }
    .rv-request-meta { font-size: 13.5px; color: var(--rv-mute); overflow-wrap: anywhere; }
    .rv-request-links { display: flex; flex-wrap: wrap; gap: 4px 16px; font-size: 13px; font-weight: 600; }
    .rv-request-links a { display: inline-flex; align-items: center; min-height: 28px; color: var(--rv-brand-d); }
    .rv-request-links a:hover { color: var(--rv-brand); }
    .rv-request-links:empty { display: none; }
    .rv-request-state { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; text-align: right; }
    .rv-request-note { font-size: 12.5px; color: var(--rv-mute); }
    .rv-request-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
    .rv-request-actions form { margin: 0; }
    .rv-request-actions .btn .ds-icon { width: 16px; height: 16px; }
    .rv-request-actions form:first-child .btn .ds-icon { color: #17693F; stroke-width: 2.4; }
    .rv-more > summary { cursor: pointer; padding: 14px 24px; font-size: 14px; font-weight: 600; color: var(--rv-brand-d); border-top: 1px solid #EEE9E4; }
    .rv-filter-empty { padding: 32px 24px; margin: 0; text-align: center; color: var(--rv-mute); }
    .rv-trunc { margin: 0; padding: 12px 24px 16px; border-top: 1px solid #EEE9E4; }

    /* Awaiting rows: the state column and its two buttons stack on the right, as in the design. */
    @media (min-width: 576px) {
        .rv-request.is-awaiting { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; column-gap: 14px; row-gap: 10px; }
        .rv-request.is-awaiting .rv-avatar { grid-column: 1; grid-row: 1 / span 2; }
        .rv-request.is-awaiting .rv-request-main { grid-column: 2; grid-row: 1 / span 2; }
        .rv-request.is-awaiting .rv-request-state { grid-column: 3; grid-row: 1; }
        .rv-request.is-awaiting .rv-request-actions { grid-column: 3; grid-row: 2; }
    }

    @media (max-width: 575.98px) {
        .rv-page { gap: 20px; }
        .rv-header h1 { font-size: 24px; }
        .rv-header-actions, .rv-header-actions .btn { width: 100%; justify-content: center; }
        .rv-stat { padding: 16px; }
        .rv-ledger-head { padding-inline: 16px; }
        .rv-request { padding-inline: 16px; }
        .rv-request-state { align-items: flex-start; text-align: left; flex: 1 1 100%; flex-direction: row; flex-wrap: wrap; align-items: center; gap: 8px; }
        .rv-request-actions { flex: 1 1 100%; justify-content: stretch; }
        .rv-request-actions form { flex: 1 1 auto; }
        .rv-request-actions .btn { width: 100%; }
        .rv-location .card-body { padding: 18px; }
        .rv-empty { flex-direction: column; text-align: center; }
    }
</style>