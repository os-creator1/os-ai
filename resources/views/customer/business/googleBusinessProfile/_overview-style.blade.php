{{--
    Google Business Profile — styles ported from the supplied design prototypes (GbpConnect, GbpChoose, GbpOverview,
    GbpCompareStale). Sizes, radii, gaps, borders and weights are the prototype's own values; colours are the
    prototype's palette exposed as local custom properties (one block for light, one for dark) so no hex is repeated
    below. Typeface is the application's own (the shell font), not the prototype's Figtree.
--}}
<style>
    .gbp-page {
        --g-surface: var(--color-surface-primary, #FFFFFF);
        --g-soft: #FBF9F7;
        --g-tile: #F4F0EC;
        --g-border: #E9E4DF;
        --g-border-soft: #F0EBE6;
        --g-line: #D6CFC8;
        --g-text: #1F1A19;
        --g-text-2: #5E5652;
        --g-muted: #6B625E;
        --g-faint: #8A817C;
        --g-brand: #B4514A;
        --g-ink: #8F3B35;
        --g-brand-soft: #F8E9E6;
        --g-brand-wash: #FBF0EE;
        --g-ok: #17693F;
        --g-ok-soft: #E3F3EA;
        --g-warn: #7A4A05;
        --g-warn-soft: #FDF1DC;
        --g-warn-wash: #FDF6E3;
        --g-warn-line: #EBD6A8;
        --g-warn-ink: #3D2A05;
        --g-info: #1F5682;
        --g-info-soft: #E4EEF6;
        --g-skel-a: #EEE9E4;
        --g-skel-b: #F4F0EC;
        color: var(--g-text);
    }
    .dark-layout .gbp-page, html.dark .gbp-page {
        --g-surface: var(--color-surface-primary, #1F1D1B);
        --g-soft: rgba(255, 255, 255, .04);
        --g-tile: rgba(255, 255, 255, .08);
        --g-border: rgba(255, 255, 255, .12);
        --g-border-soft: rgba(255, 255, 255, .08);
        --g-line: rgba(255, 255, 255, .22);
        --g-text: #F1ECE8;
        --g-text-2: #C9C2BD;
        --g-muted: #B3ABA6;
        --g-faint: #9A918B;
        --g-brand: #D3716A;
        --g-ink: #E59A94;
        --g-brand-soft: rgba(211, 113, 106, .18);
        --g-brand-wash: rgba(211, 113, 106, .12);
        --g-ok: #6FD3A0;
        --g-ok-soft: rgba(40, 199, 111, .18);
        --g-warn: #F2C26B;
        --g-warn-soft: rgba(242, 194, 107, .16);
        --g-warn-wash: rgba(242, 194, 107, .10);
        --g-warn-line: rgba(242, 194, 107, .35);
        --g-warn-ink: #F6D79A;
        --g-info: #8FC1EA;
        --g-info-soft: rgba(143, 193, 234, .16);
        --g-skel-a: rgba(255, 255, 255, .08);
        --g-skel-b: rgba(255, 255, 255, .14);
    }

    .gbp-page a { text-decoration: none; }
    .gbp-page .ds-icon { flex: none; }
    .gbp-main { display: flex; flex-direction: column; gap: 18px; padding-bottom: 24px; }
    .gbp-wrap { max-width: 1120px; width: 100%; }
    .gbp-wrap-sm { max-width: 880px; width: 100%; }
    .gbp-wrap-md { max-width: 980px; width: 100%; }
    .gbp-break { overflow-wrap: anywhere; }
    .gbp-minw { min-width: 0; }

    /* page header */
    .gbp-head { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
    .gbp-head-text { flex: 1 1 300px; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .gbp-h1 { margin: 0; font-size: 26px; font-weight: 700; letter-spacing: -.01em; line-height: 1.25; color: var(--g-text); }
    .gbp-sub { margin: 0; font-size: 14.5px; color: var(--g-text-2); }
    .gbp-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 13px; color: var(--g-muted); }
    .gbp-pill { display: inline-flex; align-items: center; gap: 6px; height: 24px; padding: 0 9px; border-radius: 999px; background: var(--g-ok-soft); color: var(--g-ok); font-weight: 600; font-size: 13px; }
    .gbp-pill.is-warn { background: var(--g-warn-soft); color: var(--g-warn); }
    .gbp-pill-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .gbp-back { align-self: flex-start; display: inline-flex; align-items: center; gap: 6px; min-height: 36px; color: var(--g-ink); font-size: 13.5px; font-weight: 600; }
    .gbp-back:hover, .gbp-textlink:hover { color: var(--g-brand); }
    .gbp-title-block { display: flex; flex-direction: column; gap: 4px; }

    /* buttons (prototype .btn) */
    .gbp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; box-sizing: border-box; padding: 0 16px; border-radius: 10px; border: 1px solid transparent; font-size: 14px; font-weight: 600; line-height: 1.2; white-space: nowrap; cursor: pointer; text-decoration: none; font-family: inherit; }
    .gbp-btn.is-primary { background: var(--g-brand); color: #fff; }
    .gbp-btn.is-primary:hover { color: #fff; filter: brightness(.94); }
    .gbp-btn.is-outline { border-color: var(--g-line); background: var(--g-surface); color: var(--g-text); }
    .gbp-btn.is-outline:hover { color: var(--g-text); border-color: var(--g-faint); }
    .gbp-btn:disabled { opacity: .7; cursor: progress; }
    .gbp-btn:focus-visible, .gbp-textlink:focus-visible, .gbp-op-link:focus-visible { outline: 2px solid var(--g-brand); outline-offset: 2px; }
    .gbp-textlink { font-size: 13px; font-weight: 600; color: var(--g-ink); background: none; border: 0; padding: 0; cursor: pointer; font-family: inherit; }

    /* generic card (prototype .cd) */
    .gbp-cd { background: var(--g-surface); border: 1px solid var(--g-border); border-radius: 16px; box-sizing: border-box; min-width: 0; }
    .gbp-cd.is-clip { overflow: hidden; }
    .gbp-ch { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; padding: 16px 20px 0; }
    .gbp-ch b { flex: 1 1 auto; font-size: 16px; font-weight: 700; color: var(--g-text); white-space: nowrap; }
    .gbp-ch-note { font-size: 12.5px; color: var(--g-muted); }
    .gbp-ch a { font-size: 13px; font-weight: 600; color: var(--g-ink); }

    /* alerts inside the page */
    .gbp-notice { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 18px; border-radius: 14px; background: var(--g-warn-wash); border: 1px solid var(--g-warn-line); color: var(--g-warn); font-size: 14px; }
    .gbp-notice.is-info { background: var(--g-info-soft); border-color: transparent; color: var(--g-info); }
    .gbp-notice p { margin: 0; }

    /* ---------- Connect (GbpConnect) ---------- */
    .gbp-connect { box-sizing: border-box; padding: 40px 40px 32px; border-radius: 18px; background: var(--g-surface); border: 1px solid var(--g-border); display: flex; flex-direction: column; align-items: center; gap: 24px; text-align: center; }
    .gbp-connect-icon { width: 72px; height: 72px; border-radius: 20px; background: var(--g-brand-soft); color: var(--g-brand); display: flex; align-items: center; justify-content: center; }
    .gbp-connect-copy { display: flex; flex-direction: column; gap: 8px; max-width: 580px; }
    .gbp-connect-copy h2 { margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -.02em; line-height: 1.25; color: var(--g-text); }
    .gbp-connect-copy p { margin: 0; font-size: 15.5px; line-height: 1.5; color: var(--g-text-2); }
    .gbp-benefits { display: flex; flex-wrap: wrap; gap: 14px; width: 100%; text-align: left; }
    .gbp-benefit { flex: 1 1 200px; display: flex; flex-direction: column; gap: 10px; padding: 18px; border-radius: 14px; background: var(--g-soft); border: 1px solid var(--g-border-soft); min-width: 0; }
    .gbp-benefit .gbp-tile { width: 38px; height: 38px; border-radius: 10px; }
    .gbp-benefit b { font-size: 15px; font-weight: 700; color: var(--g-text); }
    .gbp-benefit span.d { font-size: 13.5px; color: var(--g-text-2); line-height: 1.45; }
    .gbp-tile { width: 32px; height: 32px; border-radius: 9px; flex: none; display: flex; align-items: center; justify-content: center; background: var(--g-brand-soft); color: var(--g-ink); }
    .gbp-tile.is-warn { background: var(--g-warn-soft); color: var(--g-warn); }
    .gbp-tile.is-info { background: var(--g-info-soft); color: var(--g-info); }
    .gbp-tile.is-neutral { background: var(--g-tile); color: var(--g-text-2); }
    .gbp-connect-cta { min-height: 50px; padding: 0 28px; font-size: 15.5px; box-shadow: 0 6px 16px rgba(180, 81, 74, .28); }
    .gbp-connect-foot { display: flex; flex-direction: column; align-items: center; gap: 6px; }
    .gbp-connect-note { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--g-text-2); margin: 0; }
    .gbp-more > summary { list-style: none; display: inline-flex; align-items: center; gap: 6px; min-height: 44px; cursor: pointer; font-size: 13px; font-weight: 500; color: var(--g-faint); }
    .gbp-more > summary::-webkit-details-marker { display: none; }
    .gbp-more > summary:hover { color: var(--g-text-2); }
    .gbp-more[open] > summary .ds-icon { transform: rotate(180deg); }
    .gbp-more > p { margin: 4px auto 0; max-width: 480px; font-size: 13px; line-height: 1.5; color: var(--g-text-2); }

    /* ---------- Picker (GbpChoose) ---------- */
    .gbp-acct { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 18px; border-radius: 14px; background: var(--g-surface); border: 1px solid var(--g-border); box-sizing: border-box; }
    .gbp-acct-ok { width: 32px; height: 32px; border-radius: 50%; background: var(--g-ok-soft); color: var(--g-ok); display: flex; align-items: center; justify-content: center; flex: none; }
    .gbp-acct-text { flex: 1 1 260px; display: flex; flex-direction: column; gap: 1px; min-width: 0; }
    .gbp-acct-text b { font-size: 14.5px; font-weight: 700; }
    .gbp-acct-text span { font-size: 13px; color: var(--g-text-2); }
    .gbp-acct .gbp-textlink { min-height: 44px; display: inline-flex; align-items: center; font-size: 13.5px; }
    .gbp-list-card { box-sizing: border-box; border-radius: 16px; background: var(--g-surface); border: 1px solid var(--g-border); overflow: hidden; }
    .gbp-list-head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 16px 16px 14px; }
    .gbp-list-head > div { flex: 1 1 200px; display: flex; flex-direction: column; gap: 2px; }
    .gbp-list-head b { font-size: 16px; font-weight: 700; }
    .gbp-list-head span { font-size: 13px; color: var(--g-muted); }
    .gbp-search { display: flex; align-items: center; gap: 8px; height: 42px; width: 280px; max-width: 100%; box-sizing: border-box; padding: 0 14px; border: 1px solid var(--g-line); border-radius: 10px; color: var(--g-faint); background: transparent; }
    .gbp-search input { border: 0; outline: 0; font-size: 14px; flex: 1; min-width: 0; background: transparent; color: var(--g-text); font-family: inherit; padding: 0; }
    .gbp-search:focus-within { border-color: var(--g-brand); }
    .gbp-rows { border-top: 1px solid var(--g-border-soft); }
    .gbp-row { position: relative; display: flex; align-items: center; gap: 14px; min-height: 60px; box-sizing: border-box; padding: 8px 16px; border-top: 1px solid var(--g-border-soft); cursor: pointer; margin: 0; }
    .gbp-row:first-child { border-top: 0; }
    .gbp-row:hover { background: var(--g-soft); }
    .gbp-row input { position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none; }
    .gbp-row .lt { width: 36px; height: 36px; border-radius: 10px; background: var(--g-tile); color: var(--g-text-2); flex: none; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; }
    .gbp-row .body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .gbp-row .name { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .gbp-row b { font-size: 14.5px; font-weight: 600; color: var(--g-text); overflow-wrap: anywhere; }
    .gbp-row small { font-size: 12.5px; color: var(--g-faint); overflow-wrap: anywhere; }
    .gbp-row .rd { width: 20px; height: 20px; border-radius: 50%; border: 1.5px solid var(--g-line); box-sizing: border-box; flex: none; }
    .gbp-row:has(input:checked) { background: var(--g-brand-wash); box-shadow: inset 3px 0 0 var(--g-brand); }
    .gbp-row:has(input:checked) .rd { border: 6px solid var(--g-brand); }
    .gbp-row:has(input:checked) .lt { background: var(--g-brand); color: #fff; }
    .gbp-row:has(input:focus-visible) .rd { outline: 2px solid var(--g-brand); outline-offset: 2px; }
    .gbp-row.is-disabled { cursor: not-allowed; opacity: .6; }
    .gbp-tag { display: inline-flex; align-items: center; height: 22px; padding: 0 8px; border-radius: 999px; background: var(--g-tile); font-size: 11.5px; font-weight: 600; color: var(--g-text); }
    .gbp-tag.is-brand { background: var(--g-brand-soft); color: var(--g-ink); }
    .gbp-pick-msg { margin: 0; padding: 14px 16px; font-size: 13.5px; color: var(--g-text-2); }
    .gbp-pick-msg.is-error { color: #c0392b; font-weight: 600; }
    .gbp-loc { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 16px; border-top: 1px solid var(--g-border-soft); }
    .gbp-loc label { font-size: 13.5px; font-weight: 600; flex: 1 1 200px; margin: 0; }
    .gbp-loc select { flex: 1 1 260px; min-height: 42px; border: 1px solid var(--g-line); border-radius: 10px; padding: 0 12px; background: var(--g-surface); color: var(--g-text); font-size: 14px; }
    .gbp-loc small { flex: 1 1 100%; font-size: 12.5px; color: var(--g-muted); }
    .gbp-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 16px; border-top: 1px solid var(--g-border-soft); background: var(--g-soft); }
    .gbp-foot > span { flex: 1 1 260px; font-size: 13px; color: var(--g-text-2); }
    .gbp-foot b { color: var(--g-text); }
    .gbp-foot .gbp-btn.is-primary { padding: 0 22px; }

    /* ---------- Overview (GbpOverview) ---------- */
    .gbp-cols { display: flex; flex-wrap: wrap; gap: 18px; align-items: flex-start; }
    .gbp-col-main { flex: 3 1 560px; min-width: 0; display: flex; flex-direction: column; gap: 18px; }
    .gbp-col-side { flex: 2 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 18px; }
    .gbp-cover { height: 120px; position: relative; background: repeating-linear-gradient(135deg, var(--g-skel-a) 0 14px, var(--g-skel-b) 14px 28px); }
    .gbp-cover-badge { position: absolute; right: 12px; top: 12px; font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 999px; background: var(--g-surface); color: var(--g-text-2); }
    .gbp-prof { padding: 0 20px 20px; display: flex; flex-direction: column; gap: 14px; }
    .gbp-prof-top { position: relative; z-index: 1; display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px; margin-top: -32px; }
    .gbp-logo { width: 72px; height: 72px; border-radius: 16px; border: 3px solid var(--g-surface); box-shadow: 0 4px 12px rgba(31, 26, 25, .12); box-sizing: border-box; background: var(--g-surface); flex: none; }
    .gbp-logo span { width: 100%; height: 100%; border-radius: 13px; background: var(--g-brand-soft); color: var(--g-ink); display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; }
    .gbp-logo.is-empty span { background: var(--g-tile); color: var(--g-faint); }
    .gbp-prof-name { flex: 1 1 260px; display: flex; flex-direction: column; gap: 3px; padding-top: 34px; min-width: 0; }
    .gbp-prof-name b { font-size: 20px; font-weight: 800; letter-spacing: -.01em; line-height: 1.25; overflow-wrap: anywhere; }
    .gbp-prof-sub { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 13.5px; color: var(--g-text-2); }
    .gbp-prof-sub .is-faint { color: var(--g-faint); }
    .gbp-prof-desc { margin: 0; font-size: 14px; line-height: 1.55; color: var(--g-text-2); }
    .gbp-kv2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 20px; }
    .gbp-kv2 > div { display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; line-height: 1.45; min-width: 0; }
    .gbp-kv2 .ds-icon { margin-top: 2px; color: var(--g-faint); }
    .gbp-kv2 .is-faint { color: var(--g-faint); }
    .gbp-kv2 span { min-width: 0; overflow-wrap: anywhere; }
    .gbp-eyebrow { font-size: 12px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--g-muted); }
    .gbp-cats { display: flex; flex-direction: column; gap: 8px; }
    .gbp-tags { display: flex; flex-wrap: wrap; gap: 6px; }
    .gbp-tg { display: inline-flex; align-items: center; height: 28px; padding: 0 10px; border-radius: 999px; background: var(--g-tile); font-size: 12.5px; font-weight: 500; color: var(--g-text); }
    .gbp-tg.is-primary { background: var(--g-brand-soft); color: var(--g-ink); font-weight: 600; }
    .gbp-stale { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; padding: 16px 18px; border-radius: 14px; background: var(--g-warn-wash); border: 1px solid var(--g-warn-line); }
    .gbp-stale .t { flex: 1 1 280px; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .gbp-stale b { font-size: 15px; color: var(--g-warn-ink); }
    .gbp-stale span { font-size: 13.5px; color: var(--g-warn); line-height: 1.45; }
    .gbp-alerts { display: flex; flex-direction: column; gap: 8px; }
    .gbp-alert { padding: 10px 14px; border-radius: 10px; font-size: 13px; background: var(--g-warn-soft); color: var(--g-warn); }
    .gbp-alert.is-info { background: var(--g-info-soft); color: var(--g-info); }
    .gbp-details { margin: 0; }
    .gbp-details > summary { list-style: none; display: inline-flex; align-items: center; gap: 6px; min-height: 36px; cursor: pointer; font-size: 13.5px; font-weight: 500; color: var(--g-faint); }
    .gbp-details > summary::-webkit-details-marker { display: none; }
    .gbp-details[open] > summary .ds-icon { transform: rotate(180deg); }
    .gbp-dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px 20px; margin: 6px 0 0; padding: 14px; border-radius: 12px; background: var(--g-soft); border: 1px solid var(--g-border-soft); }
    .gbp-dl dt { font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--g-muted); }
    .gbp-dl dd { margin: 2px 0 0; font-size: 13.5px; overflow-wrap: anywhere; }

    /* performance */
    .gbp-kpis { display: flex; flex-wrap: wrap; gap: 10px; padding: 14px 20px 4px; }
    .gbp-kp { flex: 1 1 120px; min-width: 0; display: flex; flex-direction: column; gap: 4px; padding: 14px 16px; border-radius: 12px; background: var(--g-soft); border: 1px solid var(--g-border-soft); }
    .gbp-kp .l { font-size: 12.5px; color: var(--g-muted); font-weight: 500; }
    .gbp-kp b { font-size: 26px; font-weight: 800; letter-spacing: -.02em; line-height: 1.2; color: var(--g-text); }
    .gbp-kp b.is-na { font-size: 16px; font-weight: 700; color: var(--g-faint); letter-spacing: 0; padding: 5px 0; }
    .gbp-kp .dl { font-size: 12px; font-weight: 600; color: var(--g-muted); }
    .gbp-kp .dl.is-up { color: var(--g-ok); }
    .gbp-kp .dl.is-down { color: #B3261E; }
    .gbp-perf-body { padding: 0 0 4px; }
    .gbp-perf-meta { padding: 12px 20px 0; font-size: 12.5px; color: var(--g-muted); display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: center; }
    .gbp-perf-meta .gbp-pill { height: 22px; font-size: 12px; }
    .gbp-perf-loc { margin: 14px 20px 0; font-size: 14px; font-weight: 700; }
    .gbp-chart { padding: 8px 20px 16px; }
    .gbp-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 12.5px; color: var(--g-text-2); margin-bottom: 4px; }
    .gbp-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .gbp-legend i { display: inline-block; width: 14px; height: 3px; border-radius: 2px; }
    .gbp-chart svg { width: 100%; height: auto; display: block; overflow: visible; }
    .gbp-chart .ax { fill: var(--g-muted); font-size: 12px; }
    .gbp-chart .gl { stroke: var(--g-border-soft); stroke-width: 1; }
    .gbp-chart .pv { font-size: 12px; font-weight: 700; }
    .gbp-chart-note { margin: 2px 0 0; font-size: 12px; color: var(--g-muted); line-height: 1.45; }
    .gbp-terms { padding: 0 20px 6px; }
    .gbp-terms h3 { margin: 4px 0 2px; font-size: 14px; font-weight: 700; }
    .gbp-terms p { margin: 0 0 8px; font-size: 12.5px; color: var(--g-muted); line-height: 1.45; }
    .gbp-term-list { list-style: none; margin: 0 0 8px; padding: 0; }
    .gbp-term-list li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; padding: 10px 0; border-top: 1px solid var(--g-border-soft); font-size: 13.5px; }
    .gbp-term-list li span:last-child { font-size: 12.5px; color: var(--g-muted); }
    .gbp-perf-msg { margin: 0; padding: 14px 20px 18px; font-size: 13.5px; color: var(--g-text-2); }
    .gbp-perf-msg.is-warn { color: var(--g-warn); }

    /* reviews */
    .gbp-rv-sum { display: flex; flex-wrap: wrap; gap: 24px; padding: 14px 20px 16px; align-items: center; }
    .gbp-rv-big { display: flex; flex-direction: column; gap: 2px; align-items: flex-start; }
    .gbp-rv-big b { font-size: 30px; font-weight: 800; letter-spacing: -.03em; line-height: 1; color: var(--g-faint); }
    .gbp-stars { color: var(--g-skel-a); letter-spacing: 1px; font-size: 15px; white-space: nowrap; }
    .gbp-rv-big span.s { font-size: 12.5px; color: var(--g-muted); }
    .gbp-bars { flex: 1 1 240px; display: flex; flex-direction: column; gap: 5px; }
    .gbp-bar { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--g-muted); }
    .gbp-bar > span:first-child { width: 8px; }
    .gbp-bar > span:nth-child(2) { flex: 1; height: 6px; border-radius: 999px; background: var(--g-tile); }
    .gbp-bar > span:last-child { width: 20px; text-align: right; }
    .gbp-rv-empty { display: flex; gap: 12px; padding: 14px 20px; border-top: 1px solid var(--g-border-soft); font-size: 13.5px; line-height: 1.45; color: var(--g-text-2); }
    .gbp-rv-empty b { display: block; color: var(--g-text); font-size: 14px; margin-bottom: 2px; }
    .gbp-rv-empty p { margin: 0; }

    /* improve */
    .gbp-op { display: flex; align-items: flex-start; gap: 12px; padding: 14px 20px; border-top: 1px solid var(--g-border-soft); }
    .gbp-op > div { flex: 1; min-width: 0; }
    .gbp-op b { font-size: 14px; font-weight: 600; color: var(--g-text); overflow-wrap: anywhere; }
    .gbp-op p { margin: 2px 0 0; font-size: 12.5px; line-height: 1.45; color: var(--g-text-2); overflow-wrap: anywhere; }
    .gbp-op-link { font-size: 12.5px; font-weight: 600; color: var(--g-ink); white-space: nowrap; margin-top: 2px; background: none; border: 0; padding: 0; cursor: pointer; font-family: inherit; }
    .gbp-op-link:hover { color: var(--g-brand); }
    .gbp-improve-sub { margin: 0; padding: 0 20px 12px; font-size: 12.5px; color: var(--g-muted); }
    .gbp-ok-row { display: flex; align-items: center; gap: 12px; padding: 14px 20px; border-top: 1px solid var(--g-border-soft); font-size: 13.5px; color: var(--g-text-2); }
    .gbp-cmp-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 12px; padding: 12px 20px 16px; border-top: 1px solid var(--g-border-soft); font-size: 12.5px; color: var(--g-muted); }
    .gbp-cmp-foot b { color: var(--g-text); font-weight: 600; }

    /* photos */
    .gbp-ph { padding: 14px 20px 18px; display: flex; flex-direction: column; gap: 10px; }
    .gbp-ph-top { display: grid; grid-template-columns: 2fr 1fr; gap: 8px; }
    .gbp-ph-sq { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
    .gbp-slot { position: relative; border-radius: 10px; background: repeating-linear-gradient(135deg, var(--g-skel-a) 0 12px, var(--g-skel-b) 12px 24px); display: flex; align-items: center; justify-content: center; }
    .gbp-ph-top .gbp-slot { height: 116px; }
    .gbp-ph-sq .gbp-slot { aspect-ratio: 1; }
    .gbp-slot .lb { position: absolute; left: 8px; bottom: 8px; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: var(--g-surface); color: var(--g-text-2); }
    .gbp-ph-note { font-size: 12.5px; color: var(--g-muted); line-height: 1.45; }

    /* ---------- Platform vs Google (GbpCompareStale) ---------- */
    .gbp-cmp-card { box-sizing: border-box; border-radius: 16px; background: var(--g-surface); border: 1px solid var(--g-border); overflow: hidden; }
    .gbp-cmp-top { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 16px 20px; }
    .gbp-cmp-top b { flex: 1; font-size: 16px; font-weight: 700; }
    .gbp-cmp-count { font-size: 12.5px; font-weight: 600; padding: 4px 10px; border-radius: 999px; background: var(--g-warn-soft); color: var(--g-warn); }
    .gbp-cmp-count.is-ok { background: var(--g-ok-soft); color: var(--g-ok); }
    .gbp-tr { display: grid; grid-template-columns: 200px 1fr 1fr; align-items: center; gap: 16px; min-height: 52px; padding: 8px 20px; border-top: 1px solid var(--g-border-soft); font-size: 14.5px; }
    .gbp-tr.has-status { grid-template-columns: 200px 1fr 1fr minmax(120px, auto); }
    .gbp-tr.th { min-height: 42px; padding-top: 0; padding-bottom: 0; border-top: 0; background: var(--g-soft); font-size: 11.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--g-muted); }
    .gbp-tr .f { color: var(--g-text-2); }
    .gbp-tr .v { font-weight: 600; overflow-wrap: anywhere; min-width: 0; }
    .gbp-tr .g { overflow-wrap: anywhere; min-width: 0; }
    .gbp-tr .miss { display: inline-flex; align-items: center; gap: 8px; color: var(--g-faint); font-weight: 400; }
    .gbp-tr.is-diff { background: var(--g-warn-wash); }
    .gbp-tr .why { display: block; font-size: 12px; color: var(--g-muted); margin-top: 2px; }
    .gbp-sk { display: block; height: 10px; border-radius: 999px; background: repeating-linear-gradient(90deg, var(--g-skel-a) 0 10px, var(--g-skel-b) 10px 20px); }
    .gbp-cl { display: none; }
    .gbp-loc-name { font-size: 12px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--g-muted); }

    @media (max-width: 991.98px) {
        .gbp-kv2 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
    }
    @media (max-width: 767.98px) {
        .gbp-connect { padding: 28px 20px 24px; }
        .gbp-main { gap: 14px; }
        .gbp-tr, .gbp-tr.has-status { grid-template-columns: minmax(0, 1fr); gap: 2px; padding: 12px 16px; }
        .gbp-tr.th { display: none; }
        .gbp-cl { display: block; font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--g-faint); }
        .gbp-tr .f { font-weight: 600; color: var(--g-text); }
        .gbp-cmp-top, .gbp-ch { padding-left: 16px; padding-right: 16px; }
        .gbp-prof, .gbp-ph { padding-left: 16px; padding-right: 16px; }
        .gbp-kpis, .gbp-chart, .gbp-terms, .gbp-perf-meta, .gbp-rv-sum { padding-left: 16px; padding-right: 16px; }
        .gbp-op, .gbp-rv-empty, .gbp-ok-row, .gbp-cmp-foot, .gbp-improve-sub { padding-left: 16px; padding-right: 16px; }
    }
    @media (max-width: 575.98px) {
        .gbp-h1 { font-size: 22px; }
        .gbp-head .gbp-btn, .gbp-foot .gbp-btn, .gbp-stale .gbp-btn, .gbp-notice .gbp-btn { width: 100%; }
        .gbp-head form { width: 100%; }
        .gbp-connect-copy h2 { font-size: 21px; }
        .gbp-search { width: 100%; }
        .gbp-ph-sq { grid-template-columns: repeat(4, 1fr); gap: 6px; }
    }
</style>
