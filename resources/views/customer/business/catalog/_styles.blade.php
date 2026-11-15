{{--
    Packages & Products — the shared visual components of the three catalog
    screens (the list, the "Add to catalog" dialog and "Prices by location"),
    reproducing the supplied MotionGrove designs. One partial, included once per
    page, so every screen draws its buttons, segmented controls, fields, cards
    and tiles from the same classes. Presentation only.

    Colours are the runtime theme tokens (so an agency white-label still
    applies); the hex value in each var() is the design's own value, used only
    as the fallback of a token that is always defined.
--}}
@once
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    .cg {
        --cg-brand: var(--color-primary, #B4514A);
        --cg-brand-dark: var(--color-primary-hover, #8F3B35);
        --cg-brand-soft: var(--color-primary-soft-bg, #F8E9E6);
        --cg-ink: #1F1A19;
        --cg-ink-2: #2E2826;
        --cg-muted: #6B625E;
        --cg-muted-2: #5E5652;
        --cg-faint: #8A817C;
        --cg-line: #E9E4DF;
        --cg-line-2: #D6CFC8;
        --cg-line-3: #F0EBE6;
        --cg-wash: #FBF9F7;
        font-family: 'Figtree', system-ui, sans-serif;
        color: var(--cg-ink);
        display: flex;
        flex-direction: column;
        gap: 18px;
    }
    .cg *, .cg *::before, .cg *::after { box-sizing: border-box; }
    .cg [hidden] { display: none !important; }
    .cg a { text-decoration: none; }
    .cg .cg-ic { width: 16px; height: 16px; flex: none; }

    /* Page head ------------------------------------------------------------ */
    .cg-head { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
    .cg-head-text { flex: 1 1 18rem; min-width: 0; }
    .cg-title { margin: 0; font-size: 28px; font-weight: 800; letter-spacing: -.02em; line-height: 1.2; color: var(--cg-ink); overflow-wrap: anywhere; }
    .cg-title.is-sub { font-size: 26px; font-weight: 700; letter-spacing: -.01em; }
    .cg-sub { margin: 2px 0 0; font-size: 14px; color: var(--cg-muted); }
    .cg-sub.is-lg { font-size: 14.5px; color: var(--cg-muted-2); }
    .cg-head-actions { display: flex; flex-wrap: wrap; gap: 10px; }
    .cg-back {
        align-self: flex-start; display: inline-flex; align-items: center; gap: 6px;
        min-height: 36px; font-size: 13.5px; font-weight: 600; color: var(--cg-brand-dark);
    }
    .cg-back .cg-ic { width: 14px; height: 14px; }
    .cg-back:hover { color: var(--cg-brand-dark); text-decoration: underline; }
    .cg-stack { display: flex; flex-direction: column; gap: 4px; }

    /* Buttons -------------------------------------------------------------- */
    .cg-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        min-height: 44px; padding: 0 16px; border-radius: 10px; border: 0;
        font: 600 14px 'Figtree', system-ui, sans-serif; white-space: nowrap; cursor: pointer;
        background: transparent; color: var(--cg-muted-2);
    }
    .cg-btn:hover { text-decoration: none; }
    .cg-btn:focus-visible, .cg-seg label:focus-within, .cg-link:focus-visible, .cg-back:focus-visible, .cg-iconbtn:focus-visible {
        outline: 2px solid var(--cg-brand); outline-offset: 2px;
    }
    .cg-btn.is-primary { background: var(--cg-brand); color: #fff; }
    .cg-btn.is-primary:hover { background: var(--cg-brand-dark); color: #fff; }
    .cg-btn.is-outline { border: 1px solid var(--cg-line-2); background: #fff; color: var(--cg-ink-2); }
    .cg-btn.is-outline:hover { border-color: var(--cg-brand); color: var(--cg-ink-2); }
    .cg-btn.is-danger { border: 1px solid var(--cg-line-2); background: #fff; color: #9B2C2C; }
    .cg-btn.is-sm { min-height: 36px; padding: 0 12px; font-size: 13px; }
    .cg-btn[disabled], .cg-btn[aria-disabled="true"] { opacity: .65; cursor: progress; }
    .cg-link {
        display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 6px; border: 0; background: transparent;
        font: 600 13px 'Figtree', system-ui, sans-serif; color: var(--cg-brand-dark); cursor: pointer; border-radius: 8px;
    }
    .cg-link:hover { text-decoration: underline; color: var(--cg-brand-dark); }
    .cg-iconbtn {
        width: 30px; height: 26px; display: inline-flex; align-items: center; justify-content: center;
        border: 1px solid var(--cg-line); border-radius: 8px; background: #fff; color: #4A4340; cursor: pointer; padding: 0;
    }
    .cg-iconbtn:hover { border-color: var(--cg-line-2); }

    /* Segmented control (type, pricing, list filter) ------------------------ */
    .cg-seg { display: flex; gap: 4px; padding: 4px; border-radius: 12px; background: #F1EDE9; }
    .cg-seg > label, .cg-seg > a {
        flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; position: relative;
        height: 42px; border-radius: 9px; font: 600 14.5px 'Figtree', system-ui, sans-serif; color: var(--cg-muted-2);
        cursor: pointer; margin: 0; padding: 0 12px; white-space: nowrap;
    }
    .cg-seg.is-sm > label, .cg-seg.is-sm > a { height: 36px; font-size: 13.5px; }
    .cg-seg input { position: absolute; opacity: 0; inset: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
    .cg-seg > label:has(input:checked), .cg-seg > a[aria-current="page"] {
        background: #fff; color: var(--cg-ink); box-shadow: 0 1px 3px rgba(31,26,25,.12);
    }
    .cg-seg > a:hover { color: var(--cg-ink); text-decoration: none; }
    .cg-seg.is-filter { display: inline-flex; }
    .cg-count { font-size: 12px; font-weight: 700; color: var(--cg-faint); }

    /* Item cards ------------------------------------------------------------ */
    .cg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 14px; margin: 0; padding: 0; list-style: none; }
    .cg-item { display: flex; flex-direction: column; gap: 8px; padding: 18px; border-radius: 14px; background: #fff; border: 1px solid var(--cg-line); min-width: 0; }
    .cg-item.is-muted { background: var(--cg-wash); }
    .cg-kind { font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--cg-faint); }
    .cg-name { font-size: 16px; font-weight: 700; color: var(--cg-ink); overflow-wrap: anywhere; }
    .cg a.cg-name { color: var(--cg-ink); }
    .cg a.cg-name:hover { color: var(--cg-brand-dark); }
    .cg-desc { font-size: 13.5px; color: var(--cg-muted); overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .cg-price { font-size: 18px; font-weight: 700; margin-top: 6px; font-variant-numeric: tabular-nums; }
    .cg-note { font-size: 12.5px; font-weight: 500; color: #7A4A05; }
    .cg-tools { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: auto; padding-top: 10px; border-top: 1px solid var(--cg-line-3); }
    .cg-tools form { margin: 0; display: inline-flex; }
    .cg-tools .cg-spacer { flex: 1; }
    .cg-empty { padding: 28px 20px; border: 1px dashed var(--cg-line-2); border-radius: 14px; background: #fff; font-size: 14px; color: var(--cg-muted-2); }
    .cg-section-title { margin: 8px 0 0; font-size: 16px; font-weight: 700; }
    .cg-section-sub { margin: 2px 0 0; font-size: 13.5px; color: var(--cg-muted); }

    /* Status messages (the catalog's own flash + error block) --------------- */
    .cg-msg { padding: 12px 16px; border-radius: 10px; font-size: 14px; border: 1px solid; }
    .cg-msg.is-ok { background: #E3F3EA; color: #17693F; border-color: #BFE3CE; }
    .cg-msg.is-err { background: #FDECEA; color: #9B2C2C; border-color: #F5C6C2; }

    /* Cards, location rows and the dashed hint ------------------------------ */
    .cg-card { background: #fff; border: 1px solid var(--cg-line); border-radius: 16px; max-width: 880px; overflow: hidden; width: 100%; }
    .cg-card.is-wide { max-width: none; }
    .cg-loc { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; padding: 18px 20px; border-top: 1px solid var(--cg-line-3); }
    .cg-loc:first-child { border-top: 0; }
    .cg-tile { width: 44px; height: 44px; border-radius: 12px; background: var(--cg-brand-soft); color: var(--cg-brand-dark); display: flex; align-items: center; justify-content: center; flex: none; }
    .cg-tile .cg-ic { width: 20px; height: 20px; }
    .cg-loc-text { flex: 1 1 260px; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
    .cg-loc-name { font-size: 16px; font-weight: 700; overflow-wrap: anywhere; }
    .cg-loc-meta { font-size: 13.5px; color: var(--cg-muted); }
    .cg-tag { display: inline-flex; align-items: center; height: 22px; padding: 0 8px; border-radius: 999px; background: #F1EDE9; color: var(--cg-muted-2); font-size: 12px; font-weight: 600; margin-left: 6px; vertical-align: middle; }
    .cg-hint { max-width: 880px; width: 100%; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 18px; border-radius: 14px; border: 1px dashed var(--cg-line-2); font-size: 13.5px; color: var(--cg-muted-2); }
    .cg-hint .cg-ic { color: var(--cg-faint); }
    .cg-hint > span { flex: 1 1 220px; }
    .cg-hint a { font-weight: 600; color: var(--cg-brand-dark); min-height: 36px; display: inline-flex; align-items: center; }

    /* Per-location editor table -------------------------------------------- */
    .cg-table-wrap { overflow-x: auto; }
    .cg-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .cg-table th { padding: 12px 16px; background: var(--cg-wash); font-size: 11.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--cg-muted); text-align: left; white-space: nowrap; }
    .cg-table td { padding: 14px 16px; border-top: 1px solid var(--cg-line-3); vertical-align: middle; }
    .cg-table form { margin: 0; display: inline-flex; gap: 8px; align-items: center; }
    .cg-badge { display: inline-flex; align-items: center; height: 24px; padding: 0 9px; border-radius: 999px; background: var(--cg-brand-soft); color: var(--cg-brand-dark); font-size: 12px; font-weight: 600; margin-left: 6px; }
    .cg-faint { color: var(--cg-faint); }

    /* Fields ---------------------------------------------------------------- */
    .cg-field { display: flex; flex-direction: column; gap: 6px; margin: 0; border: 0; padding: 0; min-width: 0; }
    .cg-field > label, .cg-field > legend { font-size: 13.5px; font-weight: 600; color: var(--cg-ink-2); margin: 0; padding: 0; float: none; width: auto; }
    .cg-field > label span, .cg-field > legend span { font-weight: 400; color: var(--cg-faint); }
    .cg-in {
        height: 46px; width: 100%; padding: 0 14px; border: 1px solid var(--cg-line-2); border-radius: 10px; background: #fff;
        font: 400 15px 'Figtree', system-ui, sans-serif; color: var(--cg-ink);
    }
    .cg-in::placeholder { color: #A39A94; }
    .cg-in:focus { outline: 0; border-color: var(--cg-brand); box-shadow: 0 0 0 3px var(--cg-brand-soft); }
    .cg-in.is-sm { height: 38px; width: 8rem; font-size: 14px; }
    textarea.cg-in { height: 84px; padding: 12px 14px; resize: none; line-height: 1.45; }
    .cg-in[aria-invalid="true"] { border-color: #C0392B; box-shadow: 0 0 0 3px #FDECEA; }
    .cg-err { margin: 0; font-size: 13px; color: #9B2C2C; }
    .cg-err[hidden] { display: none; }

    .cg-money { display: flex; }
    .cg-cc {
        position: relative; display: inline-flex; align-items: center; gap: 8px; height: 46px; padding: 0 10px 0 12px; flex: none;
        border: 1px solid var(--cg-line-2); border-right: 0; border-radius: 10px 0 0 10px; background: var(--cg-wash);
        font: 600 15px 'Figtree', system-ui, sans-serif; color: var(--cg-ink);
    }
    .cg-cc select { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; font-size: 16px; }
    .cg-cc:focus-within { outline: 2px solid var(--cg-brand); outline-offset: -1px; }
    .cg-cc .cg-ic { width: 14px; height: 14px; color: var(--cg-faint); }
    .cg-sym { display: flex; align-items: center; height: 46px; padding: 0 2px 0 14px; border: 1px solid var(--cg-line-2); border-left: 0; border-right: 0; font-size: 15px; color: var(--cg-muted); background: #fff; }
    .cg-money .cg-in { border-left: 0; border-radius: 0 10px 10px 0; padding-left: 4px; min-width: 0; }
    .cg-money:focus-within .cg-cc, .cg-money:focus-within .cg-sym { border-color: var(--cg-brand); }
    .cg-money.is-invalid .cg-cc, .cg-money.is-invalid .cg-sym { border-color: #C0392B; }

    /* Dialog ----------------------------------------------------------------- */
    dialog.cg-dialog {
        width: 560px; max-width: calc(100% - 32px); padding: 0; border: 0; border-radius: 18px; background: #fff; color: var(--cg-ink);
        box-shadow: 0 24px 64px rgba(31,26,25,.28); overflow: hidden; margin: 56px auto auto; max-height: calc(100dvh - 72px);
        font-family: 'Figtree', system-ui, sans-serif; flex-direction: column;
    }
    dialog.cg-dialog[open] { display: flex; }
    dialog.cg-dialog::backdrop { background: rgba(31,26,25,.38); }
    .cg-dialog form { display: flex; flex-direction: column; min-height: 0; margin: 0; }
    .cg-dlg-head { display: flex; align-items: flex-start; gap: 12px; padding: 22px 24px 4px; }
    .cg-dlg-head > div { flex: 1; min-width: 0; }
    .cg-dlg-title { margin: 0; font-size: 20px; font-weight: 800; color: var(--cg-ink); }
    .cg-dlg-sub { font-size: 13.5px; color: var(--cg-muted); }
    .cg-close { width: 36px; height: 36px; border: 0; border-radius: 10px; background: #F4F0EC; color: #4A4340; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
    .cg-close:hover { background: #ECE6E0; }
    .cg-close:focus-visible { outline: 2px solid var(--cg-brand); outline-offset: 2px; }
    .cg-dlg-body { padding: 16px 24px 20px; display: flex; flex-direction: column; gap: 16px; overflow-y: auto; min-height: 0; }
    .cg-dlg-foot { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 14px 24px; border-top: 1px solid var(--cg-line-3); background: var(--cg-wash); }
    .cg-dlg-foot .cg-btn.is-primary { padding: 0 20px; }
    .cg-spin { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255,255,255,.45); border-top-color: #fff; animation: cg-spin .7s linear infinite; }
    @keyframes cg-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .cg-spin { animation-duration: 2s; } }

    /* Edit-item dialog extras ------------------------------------------------ */
    dialog.cg-dialog.is-edit { margin-top: 44px; }
    .cg-info { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 10px; background: var(--cg-wash); border: 1px solid var(--cg-line-3); font-size: 13.5px; color: var(--cg-muted-2); }
    .cg-info .cg-ic { color: var(--cg-faint); }
    .cg-offer { margin: 0 24px 20px; display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid var(--cg-line); border-radius: 12px; }
    .cg-offer .cg-ic { width: 18px; height: 18px; color: var(--cg-brand-dark); }
    .cg-offer-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
    .cg-offer-text b { font-size: 14px; }
    .cg-offer-text span { font-size: 12.5px; color: var(--cg-muted); }
    .cg-offer a { font-size: 13.5px; font-weight: 600; color: var(--cg-brand-dark); min-height: 36px; display: inline-flex; align-items: center; }
    .cg-offer a:hover { text-decoration: underline; }
    .cg-del { flex: 1; justify-content: flex-start; padding: 0; font-size: 13.5px; color: #A3352A; background: transparent; }
    .cg-del:hover { text-decoration: underline; color: #A3352A; }

    @media (max-width: 575.98px) {
        .cg-offer { margin: 0 16px 16px; flex-wrap: wrap; }
        .cg-title { font-size: 24px; }
        .cg-head-actions { width: 100%; }
        .cg-head-actions .cg-btn { flex: 1 1 auto; }
        .cg-grid { grid-template-columns: 1fr; }
        .cg-loc .cg-btn { width: 100%; }
        dialog.cg-dialog { margin: 16px auto auto; max-height: calc(100dvh - 32px); }
        .cg-dlg-head { padding: 18px 16px 4px; }
        .cg-dlg-body { padding: 14px 16px 16px; }
        .cg-dlg-foot { padding: 12px 16px; }
    }
</style>
@endonce
