{{--
    Public scheduler styles. Standalone on purpose: a guest page carries no
    Business OS shell, so the design-system tokens are repeated here as
    fallbacks (accent #B5524C, canvas #F7F6F2, ink #262522) and the page's
    accent is the Booking Type's own colour when it has one.
--}}
<style>
    @font-face {
        font-family: 'Geist Variable';
        font-style: normal;
        font-display: swap;
        font-weight: 100 900;
        src: url('/fonts/geist/geist-latin-wght-normal.woff2') format('woff2-variations');
    }

    :root {
        --pb-accent: #B5524C;
        --pb-accent-ink: #ffffff;
        --pb-ink: #262522;
        --pb-muted: #6F6D67;
        --pb-line: #E5E1DA;
        --pb-canvas: #F7F6F2;
        --pb-surface: #ffffff;
        --pb-danger: #A51D24;
    }

    * { box-sizing: border-box; }

    body.pb {
        --pb-accent-soft: color-mix(in srgb, var(--pb-accent) 12%, #ffffff);
        --pb-accent-hover: color-mix(in srgb, var(--pb-accent) 88%, #000000);
        margin: 0;
        min-height: 100vh;
        background: var(--pb-canvas);
        color: var(--pb-ink);
        font-family: 'Geist Variable', -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
        font-size: 15px;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }

    .pb-shell {
        display: flex;
        justify-content: center;
        padding: 40px 16px 56px;
    }

    .pb-card {
        display: grid;
        grid-template-columns: 300px minmax(0, 1fr);
        width: 100%;
        max-width: 960px;
        background: var(--pb-surface);
        border: 1px solid var(--pb-line);
        border-radius: 14px;
        box-shadow: 0 10px 30px rgba(38, 37, 34, .07);
        overflow: hidden;
    }


    /* ---- left: who / what ---- */
    .pb-info {
        padding: 32px 28px;
        border-right: 1px solid var(--pb-line);
    }

    .pb-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }

    .pb-logo {
        flex: none;
        display: grid;
        place-items: center;
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: var(--pb-accent);
        color: var(--pb-accent-ink);
        font-size: 20px;
        font-weight: 650;
    }

    .pb-business { font-weight: 600; line-height: 1.25; }
    .pb-staff { color: var(--pb-muted); font-size: 13px; }

    .pb-title { margin: 0 0 14px; font-size: 24px; line-height: 1.2; font-weight: 650; letter-spacing: -.01em; }

    .pb-meta { list-style: none; margin: 0 0 18px; padding: 0; color: var(--pb-muted); }
    .pb-meta li { display: flex; gap: 10px; align-items: flex-start; margin: 8px 0; }
    .pb-meta svg { flex: none; width: 18px; height: 18px; margin-top: 2px; }

    .pb-desc { margin: 0; color: var(--pb-muted); white-space: pre-line; }

    /* ---- right: scheduler ---- */
    .pb-main { padding: 28px 32px 32px; min-width: 0; }
    .pb-heading { margin: 0 0 18px; font-size: 19px; font-weight: 650; }

    .pb-stage { display: flex; gap: 28px; align-items: flex-start; }
    .pb-calendar { flex: 1 1 340px; min-width: 0; }
    .pb-times { flex: 0 0 200px; min-width: 0; }

    .pb-cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .pb-month { font-weight: 600; font-size: 16px; }
    .pb-nav { display: flex; gap: 6px; }

    .pb-icon-btn {
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: var(--pb-accent);
        cursor: pointer;
    }

    .pb-icon-btn:hover:not(:disabled) { background: var(--pb-accent-soft); }
    .pb-icon-btn:disabled { color: #bdb9b1; cursor: default; }
    .pb-icon-btn svg { width: 20px; height: 20px; }

    .pb-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 4px;
        transition: opacity .12s;
    }

    .pb-grid.is-loading { opacity: .45; pointer-events: none; }

    .pb-dow {
        padding: 6px 0;
        text-align: center;
        color: var(--pb-muted);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
    }

    .pb-day {
        position: relative;
        aspect-ratio: 1;
        min-height: 40px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: #b3afa6;
        font: inherit;
        cursor: default;
    }

    .pb-day.is-open {
        background: var(--pb-accent-soft);
        color: var(--pb-accent);
        font-weight: 650;
        cursor: pointer;
    }

    .pb-day.is-open:hover { background: color-mix(in srgb, var(--pb-accent) 22%, #ffffff); }
    .pb-day.is-selected, .pb-day.is-selected:hover { background: var(--pb-accent); color: var(--pb-accent-ink); }

    .pb-day.is-today::after {
        content: '';
        position: absolute;
        bottom: 6px;
        width: 4px;
        height: 4px;
        border-radius: 50%;
        background: currentColor;
        opacity: .65;
    }

    .pb-day:focus-visible, .pb-slot:focus-visible, .pb-icon-btn:focus-visible, .pb-btn:focus-visible, .pb-link:focus-visible {
        outline: 2px solid var(--pb-accent);
        outline-offset: 2px;
    }

    .pb-tz { margin-top: 22px; }
    .pb-tz label { display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; color: var(--pb-muted); }
    .pb-tz select { width: 100%; max-width: 340px; }

    .pb-times-title { margin: 0 0 12px; font-weight: 600; }
    .pb-slot-list { display: flex; flex-direction: column; gap: 8px; max-height: 392px; overflow-y: auto; padding: 2px 4px 2px 2px; }

    .pb-slot {
        padding: 11px 8px;
        border: 1px solid var(--pb-accent);
        border-radius: 8px;
        background: #fff;
        color: var(--pb-accent);
        font: inherit;
        font-weight: 650;
        text-align: center;
        cursor: pointer;
        transition: background .1s, color .1s;
    }

    .pb-slot:hover, .pb-slot.is-chosen { background: var(--pb-accent); color: var(--pb-accent-ink); }

    .pb-slot-fallback { display: block; margin: 0; }
    .pb-slot-fallback input { margin-right: 6px; }

    .pb-empty { margin: 0; padding: 14px; border-radius: 8px; background: var(--pb-canvas); color: var(--pb-muted); }
    .pb-skeleton { height: 42px; border-radius: 8px; background: linear-gradient(90deg, #f0eee9, #f8f7f4, #f0eee9); background-size: 200% 100%; animation: pb-shimmer 1.1s linear infinite; }
    @keyframes pb-shimmer { to { background-position: -200% 0; } }

    /* ---- details + confirmation ---- */
    .pb-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin: 0 0 14px -6px;
        padding: 6px 10px 6px 6px;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: var(--pb-accent);
        font: inherit;
        font-weight: 600;
        cursor: pointer;
    }

    .pb-back:hover { background: var(--pb-accent-soft); }
    .pb-back svg { width: 18px; height: 18px; }

    .pb-picked { margin: 0 0 20px; padding: 12px 14px; border-radius: 10px; background: var(--pb-accent-soft); font-weight: 600; }
    .pb-picked small { display: block; font-weight: 400; color: var(--pb-muted); }

    .pb-field { margin: 0 0 14px; }
    .pb-field label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 14px; }
    .pb-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    .pb-card input[type=text], .pb-card input[type=email], .pb-card input[type=tel], .pb-card input[type=date], .pb-card select {
        width: 100%;
        padding: 11px 12px;
        border: 1px solid #cfcac1;
        border-radius: 8px;
        background: #fff;
        color: var(--pb-ink);
        font: inherit;
    }

    .pb-card input:focus, .pb-card select:focus { outline: 2px solid var(--pb-accent); outline-offset: 0; border-color: transparent; }
    .pb-field .pb-err { margin: 4px 0 0; color: var(--pb-danger); font-size: 13px; }
    .pb-alert { margin: 0 0 14px; padding: 11px 14px; border-radius: 8px; background: #fbeaea; color: var(--pb-danger); }
    .pb-alert[hidden], .pb-err[hidden] { display: none; }

    .pb-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 12px 22px;
        border: 0;
        border-radius: 999px;
        background: var(--pb-accent);
        color: var(--pb-accent-ink);
        font: inherit;
        font-weight: 650;
        text-decoration: none;
        cursor: pointer;
    }

    .pb-btn:hover { background: var(--pb-accent-hover); }
    .pb-btn:disabled { opacity: .6; cursor: default; }
    .pb-btn.is-ghost { background: transparent; color: var(--pb-accent); border: 1px solid var(--pb-accent); }
    .pb-btn.is-ghost:hover { background: var(--pb-accent-soft); }

    .pb-done { text-align: left; }
    .pb-check { display: grid; place-items: center; width: 52px; height: 52px; margin-bottom: 14px; border-radius: 50%; background: var(--pb-accent-soft); color: var(--pb-accent); }
    .pb-check svg { width: 28px; height: 28px; }
    .pb-summary { margin: 18px 0; padding: 16px 18px; border: 1px solid var(--pb-line); border-radius: 12px; }
    .pb-summary dl { margin: 0; display: grid; grid-template-columns: 110px 1fr; gap: 10px 14px; }
    .pb-summary dt { color: var(--pb-muted); }
    .pb-summary dd { margin: 0; font-weight: 600; }
    .pb-actions { display: flex; flex-wrap: wrap; gap: 10px; }
    .pb-link { color: var(--pb-accent); font-weight: 600; }

    .pb-back-date { display: none; }

    .pb-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

    /* ---- step visibility (script adds `js` to <html>; without it every part is plain HTML) ---- */
    .js .pb-fallback-only { display: none; }
    html:not(.js) .pb-calendar, html:not(.js) .pb-back, html:not(.js) .pb-app .pb-done { display: none; }
    .js .pb-app[data-step="date"] .pb-times,
    .js .pb-app[data-step="date"] .pb-details,
    .js .pb-app[data-step="date"] .pb-done,
    .js .pb-app[data-step="time"] .pb-details,
    .js .pb-app[data-step="time"] .pb-done,
    .js .pb-app[data-step="details"] .pb-stage,
    .js .pb-app[data-step="details"] .pb-done,
    .js .pb-app[data-step="done"] .pb-stage,
    .js .pb-app[data-step="done"] .pb-details,
    .js .pb-app[data-step="details"] .pb-heading-main,
    .js .pb-app[data-step="done"] .pb-heading-main { display: none; }
    html:not(.js) .pb-tz { display: none; }
    .js .pb-app[data-step="details"] .pb-tz,
    .js .pb-app[data-step="done"] .pb-tz { display: none; }

    /* ---- phones: business info stacks above the scheduler ---- */
    @media (max-width: 760px) {
        .pb-shell { padding: 0 0 32px; }
        .pb-card { max-width: none; grid-template-columns: 1fr; border: 0; border-radius: 0; box-shadow: none; }
        .pb-info { padding: 22px 18px 18px; border-right: 0; border-bottom: 1px solid var(--pb-line); }
        .pb-title { font-size: 21px; margin-bottom: 10px; }
        .pb-main { padding: 22px 18px 28px; }
        .pb-stage { flex-direction: column; gap: 20px; }
        .pb-calendar, .pb-times { flex: none; width: 100%; }
        .js .pb-app[data-step="time"] .pb-calendar, .js .pb-app[data-step="time"] .pb-tz { display: none; }
        .pb-slot-list { max-height: none; }
        .js .pb-app[data-step="time"] .pb-back-date { display: inline-flex; }
        .pb-slot { padding: 14px 8px; }
        .pb-day { min-height: 44px; }
        .pb-row { grid-template-columns: 1fr; gap: 0; }
        .pb-summary dl { grid-template-columns: 90px 1fr; }
    }

    @media (prefers-reduced-motion: reduce) {
        .pb-grid, .pb-slot { transition: none; }
        .pb-skeleton { animation: none; }
    }
</style>
