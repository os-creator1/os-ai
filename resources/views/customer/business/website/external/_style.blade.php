{{--
    External Website Audit — styles ported from the supplied design references (WebOverview, WebAudit, WebPlan,
    WebPages, WebSettings). Sizes, radii, gaps, borders and weights are the references' own values. Colours are the
    references' palette exposed as local custom properties (one block for light, one for dark), so no hex is repeated
    below. Everything is scoped under `.xw`; nothing here touches the global design system, the hosted website
    builder or other modules. The application shell (sidebar, header) is the MotionGrove shell, not the reference's.
--}}
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    .xw {
        --x-surface: var(--color-surface-primary, #FFFFFF);
        --x-soft: #FBF9F7;
        --x-wash: #FCFAF8;
        --x-border: #E9E4DF;
        --x-border-soft: #F0EBE6;
        --x-line: #D6CFC8;
        --x-line-soft: #E0DAD4;
        --x-text: #1F1A19;
        --x-text-2: #5E5652;
        --x-text-3: #2E2826;
        --x-muted: #6B625E;
        --x-faint: #8A817C;
        --x-brand: #B4514A;
        --x-ink: #8F3B35;
        --x-brand-soft: #F8E9E6;
        --x-brand-wash: #FBF0EE;
        --x-brand-line: #E2CFCB;
        --x-ok: #17693F;
        --x-ok-soft: #E3F3EA;
        --x-ok-wash: #F1F8F3;
        --x-ok-line: #CFE8D9;
        --x-warn: #7A4A05;
        --x-warn-soft: #FDF1DC;
        --x-warn-line: #D9A441;
        --x-bad: #A3352A;
        --x-bad-soft: #FBE7E4;
        --x-info: #1F5682;
        --x-info-soft: #E4EEF6;
        --x-info-line: #C9DCEB;
        --x-blue: #2F6FA3;
        --x-neutral-soft: #F1EDE9;
        --x-track: #EEE9E4;
        --x-ring: #CFC6BE;
        --x-chev: #B9AFA8;
        color: var(--x-text);
        font-family: 'Figtree', system-ui, sans-serif;
    }
    .dark-layout .xw, html.dark .xw {
        --x-surface: var(--color-surface-primary, #1F1D1B);
        --x-soft: rgba(255, 255, 255, .04);
        --x-wash: rgba(255, 255, 255, .03);
        --x-border: rgba(255, 255, 255, .12);
        --x-border-soft: rgba(255, 255, 255, .08);
        --x-line: rgba(255, 255, 255, .22);
        --x-line-soft: rgba(255, 255, 255, .18);
        --x-text: #F1ECE8;
        --x-text-2: #C9C2BD;
        --x-text-3: #E4DEDA;
        --x-muted: #B3ABA6;
        --x-faint: #9A918B;
        --x-brand: #D3716A;
        --x-ink: #E59A94;
        --x-brand-soft: rgba(211, 113, 106, .18);
        --x-brand-wash: rgba(211, 113, 106, .12);
        --x-brand-line: rgba(211, 113, 106, .35);
        --x-ok: #6FD3A0;
        --x-ok-soft: rgba(40, 199, 111, .18);
        --x-ok-wash: rgba(40, 199, 111, .08);
        --x-ok-line: rgba(40, 199, 111, .3);
        --x-warn: #F2C26B;
        --x-warn-soft: rgba(242, 194, 107, .16);
        --x-warn-line: rgba(242, 194, 107, .5);
        --x-bad: #F09A91;
        --x-bad-soft: rgba(240, 154, 145, .16);
        --x-info: #8FC1EA;
        --x-info-soft: rgba(143, 193, 234, .16);
        --x-info-line: rgba(143, 193, 234, .35);
        --x-blue: #6FA9D8;
        --x-neutral-soft: rgba(255, 255, 255, .08);
        --x-track: rgba(255, 255, 255, .1);
        --x-ring: rgba(255, 255, 255, .3);
        --x-chev: #8A817C;
    }

    .xw, .xw * { box-sizing: border-box; }
    .xw a { text-decoration: none; }
    :where(.xw) a:where([class*="xw-"]) { color: inherit; }
    :where(.xw) a:where([class*="xw-"]):hover { color: var(--x-ink); }
    .xw button, .xw input, .xw select { font-family: inherit; }
    .xw svg.xi { width: 16px; height: 16px; flex: none; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .xw-main { display: flex; flex-direction: column; gap: 18px; padding-bottom: 24px; }
    .xw-live { display: flex; flex-direction: column; gap: 18px; }
    .xw-narrow { max-width: 900px; width: 100%; }
    .xw-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    .xw-mut { font-size: 12.5px; color: var(--x-muted); }
    .xw-faint { color: var(--x-faint); }
    .xw-code { font-family: ui-monospace, Menlo, monospace; font-size: 13px; overflow-wrap: anywhere; }
    .xw-lk { font-size: 13.5px; font-weight: 600; color: var(--x-ink); display: inline-flex; align-items: center; gap: 4px; }
    .xw-lk-sm { font-size: 12.5px; }
    .xw-break { overflow-wrap: anywhere; }
    .xw-minw { min-width: 0; }
    .xw-eyebrow { font-size: 12px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--x-muted); }

    /* page header */
    .xw-head { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
    .xw-head-text { flex: 1 1 320px; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .xw-title { margin: 0; font-size: 28px; font-weight: 800; letter-spacing: -.02em; color: var(--x-text); line-height: 1.2; }
    .xw-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 13.5px; color: var(--x-muted); }
    .xw-head-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .xw-head-actions form { margin: 0; }

    /* buttons */
    .xw-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 16px; border-radius: 10px; border: 1px solid transparent; font-size: 14px; font-weight: 600; white-space: nowrap; cursor: pointer; background: var(--x-surface); color: var(--x-text-3); }
    .xw-btn-primary { background: var(--x-brand); color: #FFFFFF; border-color: var(--x-brand); }
    .xw .xw-btn-primary:hover { color: #FFFFFF; filter: brightness(.94); }
    .xw-btn-line { border-color: var(--x-line); background: var(--x-surface); color: var(--x-text-3); }
    .xw .xw-btn-line:hover { color: var(--x-text-3); border-color: var(--x-brand); }
    .xw-btn-sm { min-height: 38px; }
    .xw-btn[disabled] { opacity: .6; cursor: default; }

    /* tabs */
    .xw-tabs { display: flex; flex-wrap: wrap; column-gap: 26px; border-bottom: 1px solid var(--x-border); }
    .xw-tab { display: inline-flex; align-items: center; min-height: 44px; font-size: 14.5px; font-weight: 500; color: var(--x-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
    .xw .xw-tab:hover { color: var(--x-text); }
    .xw .xw-tab.is-active { font-weight: 600; color: var(--x-ink); border-bottom-color: var(--x-brand); }

    /* tags */
    .xw-tg { display: inline-flex; align-items: center; gap: 5px; height: 22px; padding: 0 8px; border-radius: 999px; font-size: 11.5px; font-weight: 700; white-space: nowrap; background: var(--x-neutral-soft); color: var(--x-text-2); }
    .xw-tg-lg { height: 24px; font-weight: 600; }
    .xw-t-low { background: var(--x-neutral-soft); color: var(--x-text-2); }
    .xw-t-seo, .xw-t-info { background: var(--x-info-soft); color: var(--x-info); }
    .xw-t-ok, .xw-t-grow { background: var(--x-ok-soft); color: var(--x-ok); }
    .xw-t-am { background: var(--x-warn-soft); color: var(--x-warn); }
    .xw-t-bad { background: var(--x-bad-soft); color: var(--x-bad); }
    .xw-t-new { background: var(--x-brand-soft); color: var(--x-ink); }

    /* cards */
    .xw-card { background: var(--x-surface); border: 1px solid var(--x-border); border-radius: 16px; }
    .xw-card-pad { padding: 18px 20px; }
    .xw-card-pad-lg { padding: 22px; }
    .xw-card-flat { overflow: hidden; }
    .xw-card:has(> details[open]) { border-color: var(--x-brand-line); }
    .xw-stack { display: flex; flex-direction: column; gap: 6px; }
    .xw-card h2.xw-h, .xw-h { margin: 0; font-size: 15px; font-weight: 700; color: var(--x-text); }
    .xw-h-lg { font-size: 16.5px; }
    .xw-ch { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; padding: 16px 20px; }
    .xw-ch > .xw-h { flex: 1; font-size: 16px; }
    .xw-p { margin: 0; font-size: 14px; line-height: 1.5; color: var(--x-text-2); }
    .xw-p-body { margin: 0; font-size: 14.5px; line-height: 1.55; color: var(--x-text-3); }
    .xw-sect { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 4px; }
    .xw-sect b { font-size: 16px; color: var(--x-text); }

    /* verdict banner */
    .xw-verdict { display: flex; flex-wrap: wrap; align-items: center; gap: 18px; padding: 22px 24px; background: linear-gradient(120deg, var(--x-surface) 60%, var(--x-ok-wash)); }
    .xw-verdict.is-warn { background: linear-gradient(120deg, var(--x-surface) 60%, var(--x-warn-soft)); }
    .xw-verdict-ic { width: 56px; height: 56px; border-radius: 16px; background: var(--x-ok-soft); color: var(--x-ok); display: flex; align-items: center; justify-content: center; flex: none; }
    .is-warn .xw-verdict-ic { background: var(--x-warn-soft); color: var(--x-warn); }
    .xw-verdict-ic svg.xi { width: 26px; height: 26px; stroke-width: 2.4; }
    .xw-verdict-text { flex: 1 1 320px; display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .xw-verdict-text b { font-size: 20px; font-weight: 800; letter-spacing: -.01em; color: var(--x-text); }
    .xw-verdict-text span { font-size: 14px; color: var(--x-text-2); }

    /* status banners (running / failed / partial / none) */
    .xw-banner { display: flex; flex-direction: column; gap: 8px; padding: 16px 20px; border-radius: 14px; border: 1px solid var(--x-border); background: var(--x-surface); font-size: 14px; color: var(--x-text-2); }
    .xw-banner > * { margin: 0; }
    .xw-banner.is-info { background: var(--x-info-soft); border-color: var(--x-info-line); color: var(--x-text-3); }
    .xw-banner.is-warn { background: var(--x-warn-soft); border-color: var(--x-warn-line); color: var(--x-text-3); }
    .xw-banner.is-bad { background: var(--x-bad-soft); border-color: var(--x-bad); color: var(--x-text-3); }
    .xw-banner.is-ok { background: var(--x-ok-wash); border-color: var(--x-ok-line); }
    .xw-banner strong { color: var(--x-text); }
    .xw-progress { display: flex; height: 8px; border-radius: 999px; overflow: hidden; background: var(--x-track); gap: 2px; }
    .xw-progress > span { display: block; height: 100%; background: var(--x-blue); transition: width .3s; }
    .xw-progress > span.is-done { background: var(--x-ok); }
    .xw-progress > span.is-empty { background: var(--x-track); }

    /* KPI tiles */
    .xw-kpis { display: flex; flex-wrap: wrap; gap: 12px; }
    .xw-kpi { flex: 1 1 150px; display: flex; flex-direction: column; gap: 3px; padding: 16px 18px; border-radius: 14px; background: var(--x-surface); border: 1px solid var(--x-border); min-width: 0; }
    .xw-kpi > span { font-size: 12.5px; color: var(--x-muted); font-weight: 500; }
    .xw-kpi > b { font-size: 26px; font-weight: 800; letter-spacing: -.02em; color: var(--x-text); }
    .xw-kpi > b.is-ok { color: var(--x-ok); }
    .xw-kpi > b.is-bad { color: var(--x-bad); }
    .xw-kpi > b.is-text { font-size: 18px; padding: 6px 0; letter-spacing: 0; line-height: 1.25; }
    .xw-kpi > small { font-size: 12px; color: var(--x-faint); }
    .xw-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
    .xw-kpi-grid .xw-kpi > b { font-size: 17px; letter-spacing: 0; }

    /* two-column body */
    .xw-cols { display: flex; flex-wrap: wrap; gap: 18px; align-items: flex-start; }
    .xw-col-main { flex: 3 1 560px; min-width: 0; }
    .xw-cols.is-single > .xw-col-main, .xw-cols.is-single > .xw-col-side { flex: 1 1 100%; }
    .xw-cols > .xw-col-main:empty { display: none; }
    .xw-col-side { flex: 2 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 18px; }

    /* rows */
    .xw-rw { display: flex; align-items: center; gap: 14px; min-height: 60px; padding: 10px 20px; border-top: 1px solid var(--x-border-soft); }
    .xw-rw:hover { background: var(--x-wash); }
    .xw-rw-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
    .xw-rw-title { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .xw-rw-title b { font-size: 15px; font-weight: 600; color: var(--x-text); }
    .xw-rw-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
    .xw-num { width: 28px; height: 28px; border-radius: 50%; background: var(--x-brand-soft); color: var(--x-ink); flex: none; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; }
    .xw-nm { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-top: 1px solid var(--x-border-soft); font-size: 13.5px; }
    .xw-nm:first-of-type { border-top: 0; }
    .xw-nm-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .xw-nm-body small { font-size: 12px; color: var(--x-muted); }
    .xw-nm-end { font-size: 12.5px; color: var(--x-muted); text-align: right; font-weight: 500; }
    .xw-hist { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; font-size: 13.5px; }
    .xw-hist + .xw-hist { padding-top: 8px; border-top: 1px solid var(--x-border-soft); }
    .xw-hist-main { flex: 1; min-width: 0; }
    .xw-idea { padding: 12px 0; border-top: 1px solid var(--x-border-soft); font-size: 14px; color: var(--x-text-2); }
    .xw-idea:first-of-type { border-top: 0; }
    .xw-idea strong { color: var(--x-text); }

    /* filters */
    .xw-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0; }
    .xw-search { flex: 1 1 260px; display: flex; align-items: center; gap: 8px; height: 40px; padding: 0 12px; border: 1px solid var(--x-line-soft); border-radius: 10px; background: var(--x-surface); color: var(--x-faint); }
    .xw-search input { border: 0; outline: 0; font: inherit; font-size: 14px; flex: 1; min-width: 0; background: transparent; color: var(--x-text); }
    .xw-search:focus-within { border-color: var(--x-brand); box-shadow: 0 0 0 3px var(--x-brand-soft); }
    .xw-sel { position: relative; display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 12px; border: 1px solid var(--x-line-soft); border-radius: 10px; background: var(--x-surface); font-size: 13.5px; font-weight: 500; color: var(--x-text-3); }
    .xw-sel > span { color: var(--x-faint); }
    .xw-sel select { appearance: none; -webkit-appearance: none; border: 0; outline: 0; background: transparent; font: inherit; font-weight: 500; color: inherit; padding: 0 18px 0 0; height: 100%; cursor: pointer; max-width: 160px; }
    .xw-sel select option { color: #1F1A19; background: #FFFFFF; }
    .xw-sel > svg.xi { position: absolute; right: 10px; width: 14px; height: 14px; color: var(--x-faint); pointer-events: none; }
    .xw-sel:focus-within { border-color: var(--x-brand); box-shadow: 0 0 0 3px var(--x-brand-soft); }
    .xw-sel.is-disabled { opacity: .55; }
    .xw-sel-status { height: 36px; font-weight: 600; padding-right: 30px; }
    .xw-sel-status.is-in_progress, .xw-sel-status.is-waiting { background: var(--x-info-soft); border-color: var(--x-info-line); color: var(--x-info); }
    .xw-sel-status.is-done, .xw-sel-status.is-verified { background: var(--x-ok-soft); border-color: var(--x-ok-line); color: var(--x-ok); }
    .xw-sel-status select { max-width: none; padding-right: 0; }
    .xw-count { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .xw-count b { font-size: 15px; color: var(--x-text); }

    /* collapsible issue / plan cards */
    .xw-det > summary { list-style: none; cursor: pointer; display: flex; align-items: center; flex-wrap: wrap; gap: 12px; padding: 16px 20px; }
    .xw-det > summary::-webkit-details-marker { display: none; }
    .xw-det[open] > summary { padding: 18px 20px; }
    .xw-det > summary .xw-chev { color: var(--x-faint); transition: transform .15s; }
    .xw-det[open] > summary .xw-chev { transform: rotate(180deg); }
    .xw-det-title { flex: 1 1 260px; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .xw-det-title b { font-size: 15px; color: var(--x-text); }
    .xw-det[open] > summary .xw-det-title b { font-size: 16px; }
    .xw-det-sub { font-size: 12.5px; color: var(--x-muted); }
    .xw-det-hide-open { display: inline; }
    .xw-det[open] .xw-det-hide-open { display: none; }
    .xw-det-body { padding: 0 20px 20px; display: flex; flex-direction: column; gap: 16px; }
    .xw-faint-note { color: var(--x-faint); }

    /* evidence tables (Audit) */
    .xw-evid { border: 1px solid var(--x-border); border-radius: 12px; overflow: hidden; }
    .xw-evid-head, .xw-evid-row { display: grid; grid-template-columns: 180px 150px minmax(0, 1fr) 90px; gap: 12px; align-items: center; padding: 0 16px; }
    .xw-evid-head { min-height: 38px; background: var(--x-soft); font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--x-muted); }
    .xw-evid-row { min-height: 46px; padding-top: 8px; padding-bottom: 8px; border-top: 1px solid var(--x-border-soft); font-size: 13.5px; color: var(--x-text); }
    .xw-evid-row > a.xw-lk { justify-content: flex-end; }
    .xw-evid-extra { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; color: var(--x-muted); padding-bottom: 4px; }
    .xw-cluster { display: flex; flex-direction: column; gap: 6px; }
    .xw-cluster-label { font-size: 13.5px; font-weight: 600; color: var(--x-text); }
    .xw-twocol { display: flex; flex-wrap: wrap; gap: 16px; }
    .xw-howto { flex: 2 1 320px; padding: 14px 16px; border-radius: 12px; background: var(--x-soft); border: 1px solid var(--x-border-soft); min-width: 0; }
    .xw-howto b { font-size: 13.5px; color: var(--x-text); }
    .xw-howto .xw-eyebrow { display: block; }
    .xw-howto ol { margin: 8px 0 0; padding-left: 18px; font-size: 13.5px; line-height: 1.6; color: var(--x-text-3); }
    .xw-notes { flex: 1 1 220px; display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: var(--x-text-2); min-width: 0; }
    .xw-notes > span { display: flex; gap: 8px; }
    .xw-notes svg.xi { width: 14px; height: 14px; margin-top: 2px; color: var(--x-faint); }
    .xw-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .xw-actions form { margin: 0; }

    /* plan */
    .xw-plan-top { display: flex; flex-wrap: wrap; align-items: center; gap: 18px; padding: 20px 22px; }
    .xw-plan-top-main { flex: 1 1 320px; display: flex; flex-direction: column; gap: 8px; min-width: 0; }
    .xw-plan-top-main > div:first-child { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; }
    .xw-plan-top-main b { font-size: 22px; font-weight: 800; color: var(--x-text); }
    .xw-state { width: 22px; height: 22px; border-radius: 50%; border: 2px solid var(--x-ring); flex: none; background: transparent; display: inline-flex; align-items: center; justify-content: center; }
    .xw-state.is-in_progress, .xw-state.is-waiting { border-color: var(--x-blue); background: conic-gradient(var(--x-blue) 0 50%, var(--x-surface) 0); }
    .xw-state.is-done, .xw-state.is-verified { border-color: var(--x-ok); background: var(--x-ok); color: #FFFFFF; }
    .xw-state svg.xi { width: 12px; height: 12px; stroke-width: 3; }
    .xw-change { flex: 1 1 300px; display: flex; flex-direction: column; gap: 8px; min-width: 0; }
    .xw-change-rows { display: flex; flex-direction: column; gap: 6px; margin-top: 4px; }
    .xw-change-row { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; font-size: 13.5px; }
    .xw-change-row .xw-code { min-width: 140px; }
    .xw-change-row .xw-mut { flex: 1; }
    .xw-steps { flex: 1 1 300px; padding: 14px 16px; border-radius: 12px; background: var(--x-soft); border: 1px solid var(--x-border-soft); min-width: 0; }
    .xw-steps ol { margin: 8px 0 0; padding-left: 18px; font-size: 13.5px; line-height: 1.6; }
    .xw-field { display: flex; flex-direction: column; gap: 6px; }
    .xw-field label { font-size: 13.5px; font-weight: 600; color: var(--x-text-3); }
    .xw-field label span { font-weight: 400; color: var(--x-faint); }
    .xw-in { height: 46px; padding: 0 14px; border: 1px solid var(--x-line); border-radius: 10px; background: var(--x-surface); font-size: 15px; color: var(--x-text); width: 100%; }
    .xw-in-sm { height: 42px; }
    .xw-in::placeholder { color: var(--x-faint); }
    .xw-in:focus { outline: 0; border-color: var(--x-brand); box-shadow: 0 0 0 3px var(--x-brand-soft); }
    .xw-in:disabled { background: var(--x-soft); color: var(--x-muted); }
    .xw-warnline { font-size: 13px; color: var(--x-warn); margin: 0; }

    /* pages table */
    .xw-tbl-head, .xw-tbl-row { display: grid; grid-template-columns: minmax(0, 2.4fr) 120px 90px 150px 110px 32px; gap: 14px; align-items: center; padding: 0 20px; }
    .xw-tbl-head { min-height: 40px; background: var(--x-soft); border-top: 1px solid var(--x-border-soft); font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--x-muted); }
    .xw .xw-tbl-head a { color: inherit; }
    .xw-tbl-row { min-height: 60px; padding-top: 10px; padding-bottom: 10px; border-top: 1px solid var(--x-border-soft); font-size: 13px; }
    .xw-tbl-row:hover { background: var(--x-wash); }
    .xw-tbl-page { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .xw-tbl-page .xw-code { font-weight: 600; color: var(--x-text); }
    .xw-tbl-page .xw-ellip { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .xw-tbl-fine { display: flex; flex-wrap: wrap; gap: 6px 12px; font-size: 12px; color: var(--x-muted); }
    .xw-tbl-fine a { color: var(--x-ink); font-weight: 600; }
    .xw-ix { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--x-ok); }
    .xw-ix.is-bad { color: var(--x-bad); }
    .xw-ix.is-muted { color: var(--x-muted); }
    .xw-ix svg.xi { width: 14px; height: 14px; }
    .xw-tbl-more { display: flex; align-items: center; justify-content: center; min-height: 46px; border-top: 1px solid var(--x-border-soft); font-size: 13.5px; font-weight: 600; color: var(--x-ink); }
    .xw-skip > summary { list-style: none; display: flex; align-items: center; flex-wrap: wrap; gap: 10px; padding: 16px 20px; cursor: pointer; }
    .xw-skip > summary::-webkit-details-marker { display: none; }
    .xw-skip > summary .xw-chev { color: var(--x-faint); transition: transform .15s; transform: rotate(180deg); }
    .xw-skip:not([open]) > summary .xw-chev { transform: none; }
    .xw-skip-group { padding: 4px 20px 12px; border-top: 1px solid var(--x-border-soft); }
    .xw-skip-group > b { display: block; padding: 10px 0 4px; font-size: 13px; color: var(--x-text); }
    .xw-skip-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px 16px; min-height: 40px; padding: 8px 0; border-top: 1px solid var(--x-border-soft); }
    .xw-skip-row .xw-code { min-width: 220px; }
    .xw-skip-row .xw-mut { flex: 1 1 240px; }

    /* settings */
    .xw-reach { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 12px; background: var(--x-ok-wash); border: 1px solid var(--x-ok-line); }
    .xw-reach.is-warn { background: var(--x-warn-soft); border-color: var(--x-warn-line); }
    .xw-reach.is-bad { background: var(--x-bad-soft); border-color: var(--x-bad); }
    .xw-reach.is-neutral { background: var(--x-soft); border-color: var(--x-border); }
    .xw-reach-ic { width: 28px; height: 28px; border-radius: 50%; background: var(--x-ok-soft); color: var(--x-ok); display: flex; align-items: center; justify-content: center; flex: none; }
    .is-warn > .xw-reach-ic { background: var(--x-warn-soft); color: var(--x-warn); }
    .is-bad > .xw-reach-ic { background: var(--x-bad-soft); color: var(--x-bad); }
    .is-neutral > .xw-reach-ic { background: var(--x-neutral-soft); color: var(--x-muted); }
    .xw-reach-ic svg.xi { width: 14px; height: 14px; stroke-width: 2.6; }
    .xw-reach-text { flex: 1 1 260px; display: flex; flex-direction: column; min-width: 0; }
    .xw-reach-text b { font-size: 14px; color: var(--x-ok); }
    .is-warn .xw-reach-text b { color: var(--x-warn); }
    .is-bad .xw-reach-text b { color: var(--x-bad); }
    .is-neutral .xw-reach-text b { color: var(--x-text-3); }
    .xw-reach form { margin: 0; }
    .xw-reach button.xw-lk { background: none; border: 0; padding: 0; cursor: pointer; }
    .xw-addr { display: flex; flex-wrap: wrap; gap: 10px; }
    .xw-addr .xw-in { flex: 1 1 320px; width: auto; }
    .xw-facts { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 8px 16px; margin: 0; font-size: 13.5px; }
    .xw-facts dt { font-weight: 600; color: var(--x-text-3); }
    .xw-facts dd { margin: 0; color: var(--x-text-2); overflow-wrap: anywhere; }
    .xw-privacy { display: flex; gap: 10px; align-items: flex-start; padding: 14px 18px; border-radius: 14px; background: var(--x-surface); border: 1px solid var(--x-border); font-size: 13px; line-height: 1.5; color: var(--x-text-2); }
    .xw-privacy svg.xi { color: var(--x-ok); margin-top: 2px; }
    .xw-host { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; padding: 22px; }
    .xw-host-text { flex: 1 1 320px; display: flex; flex-direction: column; gap: 3px; }
    .xw-host-text b { font-size: 16px; color: var(--x-text); }
    .xw-host form { margin: 0; }
    .xw-stop { align-self: flex-start; font-size: 13.5px; font-weight: 600; color: var(--x-bad); min-height: 40px; display: inline-flex; align-items: center; background: none; border: 0; padding: 0; cursor: pointer; }
    .xw .xw-stop:hover { color: var(--x-bad); text-decoration: underline; }
    .xw-stop-form { margin: 0; }
    .xw-hist-list { display: flex; flex-direction: column; }
    .xw-hist-list .xw-nm { align-items: flex-start; flex-wrap: wrap; }
    .xw-hist-list .xw-nm-body { flex: 1 1 260px; }

    /* page detail (page.blade.php uses the same system) */
    .xw-def { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 8px 16px; margin: 0; font-size: 13.5px; }
    .xw-def dt { font-weight: 600; color: var(--x-text-3); }
    .xw-def dd { margin: 0; color: var(--x-text-2); overflow-wrap: anywhere; }

    @media (max-width: 991.98px) {
        .xw-evid-head, .xw-evid-row { grid-template-columns: minmax(0, 1fr) 90px; }
        .xw-evid-head > :nth-child(2), .xw-evid-head > :nth-child(3) { display: none; }
        .xw-evid-row > :nth-child(2) { grid-column: 1; font-size: 12.5px; color: var(--x-muted); }
        .xw-evid-row > :nth-child(3) { grid-column: 1 / -1; }
        .xw-evid-row > a.xw-lk { grid-column: 2; grid-row: 1; }
        .xw-tbl-head { display: none; }
        .xw-tbl-row { grid-template-columns: minmax(0, 1fr) auto; row-gap: 8px; padding: 12px 16px; }
        .xw-tbl-row > .xw-tbl-page { grid-column: 1 / -1; }
        .xw-tbl-row > svg.xi { display: none; }
        .xw-tbl-row > [data-cell="canonical"] { grid-column: 1 / -1; }
        .xw-facts, .xw-def { grid-template-columns: 1fr; gap: 2px; }
        .xw-facts dd, .xw-def dd { margin-bottom: 8px; }
    }
    @media (max-width: 575.98px) {
        .xw-title { font-size: 24px; }
        .xw-tabs { column-gap: 18px; overflow-x: auto; flex-wrap: nowrap; }
        .xw-verdict { padding: 18px; }
        .xw-rw { align-items: flex-start; flex-wrap: wrap; }
        .xw-rw-actions { flex-direction: row; flex-wrap: wrap; align-items: center; gap: 4px 14px; padding-left: 42px; width: 100%; }
        .xw-skip-row .xw-code { min-width: 0; }
        .xw-head-actions { width: 100%; }
        .xw-head-actions .xw-btn { width: 100%; }
        .xw-head-actions form { width: 100%; }
    }
</style>
