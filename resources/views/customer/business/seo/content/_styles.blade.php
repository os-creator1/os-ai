<style>
    /* ---------------------------------------------------------------
       SEO -> Content: values taken from the approved designs
       (ContentAutopilot / Articles / ContentPlan / ContentOpportunities .dc.html).
       Everything is scoped to .content-module so no other screen is touched.
    --------------------------------------------------------------- */
    .content-module {
        --cm-brand: #B4514A;
        --cm-brand-d: #8F3B35;
        --cm-ink: #1F1A19;
        --cm-ink2: #2E2826;
        --cm-mute: #5E5652;
        --cm-line: #E9E4DF;
        --cm-line2: #D6CFC8;
        --cm-soft: #F8E9E6;
        --cm-sand: #EDE8E3;
        font-family: 'Figtree', var(--font-family-app, system-ui), system-ui, -apple-system, 'Segoe UI', sans-serif;
        color: var(--cm-ink);
    }

    .content-module a:hover { text-decoration: none; }
    .content-module #seo-content-region { display: flex; flex-direction: column; gap: 20px; }
    .content-module #seo-content-region > * { margin-bottom: 0 !important; }
    .content-module .ds-icon { flex: none; }

    /* Header + tabs (stable: never part of the swapped region) */
    .content-module-header { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
    .content-module-heading { display: flex; flex-direction: column; gap: 6px; min-width: 0; max-width: 46rem; }
    .content-module-heading > h4 { margin: 0 !important; font-size: 28px; line-height: 1.2; font-weight: 700; letter-spacing: -.015em; color: var(--cm-ink); }
    .content-module-heading > p { margin: 0; font-size: 15px; line-height: 1.4; color: var(--cm-mute); }
    .content-module-action:empty { display: none; }

    .content-tabs { display: flex; flex-wrap: nowrap; gap: 4px; margin-bottom: 20px; overflow-x: auto; border-bottom: 1px solid #E0DAD4; scrollbar-width: none; }
    .content-tabs::-webkit-scrollbar { display: none; }
    .content-tab { display: inline-flex; align-items: center; min-height: 44px; padding: 0 14px; margin-bottom: -1px; font-size: 14.5px; font-weight: 500; white-space: nowrap; color: var(--cm-mute); border-bottom: 2px solid transparent; }
    .content-tab:hover { color: var(--cm-brand-d); }
    .content-tab.is-active { color: var(--cm-brand-d); font-weight: 600; border-bottom-color: var(--cm-brand); }
    .content-tab:focus-visible { outline: 2px solid var(--cm-brand); outline-offset: -2px; border-radius: 6px; }

    /* Buttons (design: 44px, 10px radius, 14px / 600) */
    .content-module .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 600; line-height: 1.2; white-space: nowrap; box-shadow: none; }
    .content-module .btn:focus-visible { outline: 2px solid var(--cm-brand); outline-offset: 2px; box-shadow: none; }
    .content-module .btn.btn-primary { border: 0; background: var(--cm-brand); color: #fff; }
    .content-module .btn.btn-primary:hover, .content-module .btn.btn-primary:active { background: var(--cm-brand-d); color: #fff; }
    .content-module .btn.btn-outline-primary { border: 1px solid #E3C3BF; background: #fff; color: var(--cm-brand-d); }
    .content-module .btn.btn-outline-primary:hover { background: var(--cm-soft); color: var(--cm-brand-d); border-color: #E3C3BF; }
    .content-module .btn.btn-outline-secondary { border: 1px solid var(--cm-line2); background: #fff; color: var(--cm-ink2); }
    .content-module .btn.btn-outline-secondary:hover { background: #FBF9F7; color: var(--cm-ink2); border-color: var(--cm-line2); }

    .content-module .card { margin-bottom: 0; border: 1px solid var(--cm-line); border-radius: 14px; background: #fff; box-shadow: none; }
    .content-module .card-body { padding: 22px 24px; }

    /* Shared bits */
    .content-icon-tile { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 40px; height: 40px; border-radius: 10px; color: var(--cm-brand-d); background: var(--cm-soft); }
    .content-icon-tile .ds-icon { width: 18px; height: 18px; }
    .content-icon-tile--neutral { color: #4A4340; background: var(--cm-sand); }
    .content-icon-tile--warm { color: #7A4A05; background: #fff; border-radius: 12px; }

    .cm-pill, .content-module .cx-pill { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 10px; border: 0; border-radius: 999px; font-size: 12.5px; font-weight: 600; line-height: 1; white-space: nowrap; color: #4A4340; background: var(--cm-sand); }
    .cm-pill::before, .content-module .cx-pill.has-dot::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #8A817C; flex: none; }
    .cm-pill.is-on, .content-module .cx-pill.is-success { color: #17693F; background: #E3F3EA; }
    .cm-pill.is-on::before, .content-module .cx-pill.is-success.has-dot::before { background: #1F8A55; }
    .cm-pill.is-paused, .content-module .cx-pill.is-warning { color: #7A4A05; background: #FDF1DC; }
    .cm-pill.is-paused::before, .content-module .cx-pill.is-warning.has-dot::before { background: #D9A441; }
    .content-module .cx-pill.is-info { color: #1F5682; background: #E4EEF6; }
    .content-module .cx-pill.is-info.has-dot::before { background: #3B7BAE; }
    .content-module .cx-pill.is-accent { color: var(--cm-brand-d); background: var(--cm-soft); }
    .content-module .cx-pill.is-accent.has-dot::before { background: var(--cm-brand); }

    .content-label { display: block; font-size: 11.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--cm-mute); }

    /* ---------------------------------------------------------------
       Autopilot
    --------------------------------------------------------------- */
    .autopilot-card > .card-body { padding: 28px; display: flex; flex-direction: column; gap: 24px; }
    .autopilot-hero { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 20px; }
    .autopilot-hero-main { display: flex; align-items: center; gap: 16px; min-width: 0; flex: 1 1 380px; }
    .autopilot-hero-main .content-icon-tile { width: 56px; height: 56px; border-radius: 16px; }
    .autopilot-hero-main .content-icon-tile .ds-icon { width: 26px; height: 26px; }
    .autopilot-hero-text { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .autopilot-hero-title { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .autopilot-hero-title h2 { margin: 0; font-size: 22px; font-weight: 700; line-height: 1.25; color: var(--cm-ink); }
    .autopilot-hero-text p { margin: 0; font-size: 15px; line-height: 1.45; color: var(--cm-mute); }
    .autopilot-hero .btn { min-height: 46px; padding: 0 20px; font-size: 15px; }

    .autopilot-steps { display: flex; flex-wrap: wrap; gap: 20px; padding-top: 22px; border-top: 1px solid #EEE9E4; }
    .autopilot-step { flex: 1 1 180px; display: flex; align-items: flex-start; gap: 12px; min-width: 0; font-size: 13.5px; line-height: 1.45; color: var(--cm-mute); }
    .autopilot-step-num { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 28px; height: 28px; border-radius: 50%; font-size: 13px; font-weight: 700; color: var(--cm-brand-d); background: var(--cm-soft); }
    .autopilot-step strong { display: block; margin-bottom: 2px; font-size: 14.5px; font-weight: 600; color: var(--cm-ink); }

    .autopilot-grid { display: flex; flex-wrap: wrap; align-items: stretch; gap: 20px; }
    .autopilot-grid > * { min-width: 0; }
    .autopilot-grid > :first-child { flex: 2 1 420px; }
    .autopilot-grid > :last-child { flex: 1 1 280px; }
    .autopilot-grid .card-body { display: flex; flex-direction: column; gap: 14px; height: 100%; }
    .autopilot-grid h5, .content-module .autopilot-card h5, .content-module h5.cm-h { margin: 0; font-size: 17px; font-weight: 700; color: var(--cm-ink); }
    .autopilot-grid p { margin: 0; }

    .autopilot-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 28px 16px; text-align: center; border: 1.5px dashed #CFC6BE; border-radius: 12px; }
    .autopilot-empty .ds-icon { width: 24px; height: 24px; color: #8A817C; }
    .autopilot-empty strong { font-size: 15px; font-weight: 600; color: var(--cm-ink); }
    .autopilot-empty span { max-width: 360px; font-size: 13.5px; color: var(--cm-mute); }

    .autopilot-month { display: flex; gap: 24px; }
    .autopilot-month > div { display: flex; flex-direction: column; gap: 4px; }
    .autopilot-month > div + div { padding-left: 24px; border-left: 1px solid #EEE9E4; }
    .autopilot-month .num { font-size: 32px; font-weight: 700; letter-spacing: -.02em; line-height: 1; }
    .autopilot-month .content-label { font-size: 13px; font-weight: 400; letter-spacing: 0; text-transform: none; }
    .autopilot-meter { display: flex; gap: 6px; }
    .autopilot-meter > i { flex: 1 1 0; height: 8px; border-radius: 999px; background: var(--cm-sand); }
    .autopilot-meter > i.is-filled { background: var(--cm-brand); }
    .autopilot-note { font-size: 13.5px; line-height: 1.45; color: var(--cm-mute); }

    .autopilot-quick { display: flex; flex-wrap: wrap; gap: 16px; }
    .autopilot-quick a { flex: 1 1 240px; min-width: 0; display: flex; align-items: center; gap: 14px; padding: 16px 18px; font-size: 15px; font-weight: 600; color: var(--cm-ink); background: #fff; border: 1px solid var(--cm-line); border-radius: 14px; }
    .autopilot-quick a:hover { border-color: #E3C3BF; color: var(--cm-ink); }
    .autopilot-quick a:focus-visible { outline: 2px solid var(--cm-brand); outline-offset: 2px; }
    .autopilot-quick a .chev { margin-left: auto; width: 16px; height: 16px; color: #8A817C; }

    /* ---------------------------------------------------------------
       Articles
    --------------------------------------------------------------- */
    .articles-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
    .articles-filters { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 4px; max-width: 100%; padding: 3px; border-radius: 10px; background: #EFEBE6; }
    .articles-filters a { display: inline-flex; align-items: center; height: 38px; padding: 0 14px; border-radius: 8px; font-size: 13.5px; font-weight: 500; white-space: nowrap; color: var(--cm-mute); }
    .articles-filters a:hover { color: var(--cm-ink); }
    .articles-filters a.is-active { font-weight: 600; color: var(--cm-ink); background: #fff; box-shadow: 0 1px 2px rgba(31, 26, 25, .12); }
    .articles-filters a:focus-visible { outline: 2px solid var(--cm-brand); outline-offset: 1px; }
    .articles-search { display: flex; align-items: center; gap: 8px; flex: 0 1 300px; min-width: 200px; height: 44px; padding: 0 14px; border: 1px solid var(--cm-line2); border-radius: 10px; background: #fff; color: #6B625E; position: static; }
    .articles-search:focus-within { border-color: var(--cm-brand); box-shadow: 0 0 0 3px var(--cm-soft); }
    .articles-search .ds-icon { position: static; transform: none; width: 16px; height: 16px; color: #6B625E; pointer-events: none; }
    .articles-search input.form-control { flex: 1; min-width: 0; height: auto; padding: 0; border: 0; outline: 0; box-shadow: none; background: transparent; font-size: 14px; color: var(--cm-ink); }

    .articles-list { display: flex; flex-direction: column; gap: 12px; }
    .article-row { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; padding: 18px 20px; background: #fff; border: 1px solid var(--cm-line); border-radius: 14px; }
    .article-row:hover { border-color: #E3C3BF; }
    .article-row > .content-icon-tile { width: 44px; height: 44px; border-radius: 12px; }
    .article-row > .content-icon-tile .ds-icon { width: 20px; height: 20px; }
    .article-row-main { display: flex; flex-direction: column; gap: 6px; flex: 3 1 340px; min-width: 0; }
    a.article-row-title { font-size: 16px; font-weight: 700; line-height: 1.3; color: var(--cm-ink); overflow-wrap: anywhere; }
    a.article-row-title:hover { color: var(--cm-brand-d); }
    .article-row-slug { font-size: 13px; color: var(--cm-mute); overflow-wrap: anywhere; }
    .article-row-meta { display: contents; font-size: 14px; }
    .article-row-meta > div { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; }
    .article-row-meta > div:nth-child(1) { flex: 0 0 120px; }
    .article-row-meta > div:nth-child(2) { flex: 1 1 130px; }
    .article-row-meta > div:nth-child(3) { flex: 0 0 100px; }
    .article-row-meta .text-muted { color: #6B625E !important; }
    .article-ai { margin-left: 6px; height: 20px; padding: 0 8px; font-size: 11.5px; vertical-align: middle; }
    .article-row-actions { display: flex; flex: 0 0 auto; align-items: center; gap: 8px; }
    .article-row-actions .article-open-live { width: 44px; padding: 0; }

    /* ---------------------------------------------------------------
       Content Plan
    --------------------------------------------------------------- */
    .plan-review { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; padding: 18px 20px; background: #FDF6E3; border: 1px solid #EBD6A8; border-radius: 16px; }
    .plan-review .content-icon-tile { width: 40px; height: 40px; }
    .plan-review-main { display: flex; flex-direction: column; gap: 6px; flex: 1 1 360px; min-width: 0; }
    .plan-review-label { font-size: 12px; font-weight: 700; letter-spacing: .07em; color: #7A4A05; }
    .plan-review-title { font-size: 16px; font-weight: 700; color: var(--cm-ink); overflow-wrap: anywhere; }
    .plan-review-text { font-size: 13.5px; color: #6B4706; }
    .content-module .btn.plan-review-action { border: 1px solid #EBD6A8; background: #fff; color: #6B4706; }
    .content-module .btn.plan-review-action:hover { background: #FFFBF0; color: #6B4706; border-color: #EBD6A8; }

    .plan-pages { overflow: hidden; background: #fff; border: 1px solid var(--cm-line); border-radius: 16px; }
    .plan-pages-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; padding: 20px 22px; }
    .plan-pages-heading { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .plan-pages-heading h5 { margin: 0; font-size: 18px; font-weight: 700; color: var(--cm-ink); }
    .plan-pages-heading p { margin: 0; font-size: 13.5px; color: var(--cm-mute); }
    .plan-progress { display: flex; align-items: center; gap: 12px; flex: 0 1 280px; }
    .plan-progress-label { font-size: 14px; font-weight: 600; white-space: nowrap; color: var(--cm-ink); }
    .plan-progress-bar { display: flex; flex: 1; gap: 3px; }
    .plan-progress-bar > i { flex: 1 1 0; height: 8px; border-radius: 3px; background: var(--cm-sand); }
    .plan-progress-bar > i.is-filled { background: var(--cm-brand); }

    .plan-row { display: grid; grid-template-columns: minmax(200px, .9fr) minmax(0, 2fr) auto; align-items: center; gap: 18px; padding: 16px 22px; border-top: 1px solid #EEE9E4; }
    .plan-row:hover { background: #FBF9F7; }
    .plan-row-page { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .plan-row-page .content-icon-tile { width: 36px; height: 36px; color: var(--cm-mute); background: #F4F0EC; }
    .plan-row-page .content-icon-tile .ds-icon { width: 16px; height: 16px; }
    .plan-row-name { font-size: 15px; font-weight: 600; color: var(--cm-ink); overflow-wrap: anywhere; }
    .plan-row-topics { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; min-width: 0; }
    .plan-none { font-size: 13.5px; color: #6B625E; }
    .plan-row-cta { justify-self: end; }

    .plan-topic, .plan-article { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; min-height: 34px; padding: 0 12px; font-size: 13.5px; font-weight: 500; text-align: left; border-radius: 999px; border: 1px solid #E3C3BF; color: var(--cm-brand-d); background: #fff; }
    .plan-topic .ds-icon { width: 13px; height: 13px; }
    .plan-topic:hover { background: var(--cm-soft); color: var(--cm-brand-d); }
    .plan-topic:focus-visible, .plan-article:focus-visible { outline: 2px solid var(--cm-brand); outline-offset: 2px; }
    .plan-article { border-color: var(--cm-line2); color: var(--cm-ink); }
    .plan-article:hover { border-color: var(--cm-brand); color: var(--cm-ink); }
    .plan-article .cx-pill { height: 22px; font-size: 11.5px; padding: 0 8px; }

    /* ---------------------------------------------------------------
       Opportunities
    --------------------------------------------------------------- */
    .opp-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
    .opp-filters { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 4px; max-width: 100%; padding: 3px; border-radius: 10px; background: #EFEBE6; }
    .opp-filter { display: inline-flex; align-items: center; gap: 4px; height: 36px; padding: 0 12px; font-size: 13.5px; font-weight: 500; white-space: nowrap; border-radius: 8px; color: var(--cm-mute); }
    .opp-filter span { color: inherit; }
    .opp-filter:hover { color: var(--cm-ink); }
    .opp-filter.is-active { font-weight: 600; color: var(--cm-ink); background: #fff; box-shadow: 0 1px 2px rgba(31, 26, 25, .12); }
    .opp-filter:focus-visible { outline: 2px solid var(--cm-brand); outline-offset: 1px; }
    .opp-trust { display: inline-flex; align-items: center; gap: 8px; margin: 0; font-size: 13.5px; color: var(--cm-mute); }
    .opp-trust .ds-icon { width: 16px; height: 16px; color: #17693F; }

    .opp-list { display: flex; flex-direction: column; gap: 20px; }
    .opp-group { overflow: hidden; background: #fff; border: 1px solid var(--cm-line); border-radius: 16px; }
    .opp-group-head { display: flex; align-items: center; gap: 14px; padding: 16px 22px; background: #FBF9F7; border-bottom: 1px solid #EEE9E4; }
    .opp-group-head > div { display: flex; flex-direction: column; gap: 2px; flex: 1; }
    .opp-group-title { margin: 0; font-size: 16px; font-weight: 700; color: var(--cm-ink); }
    .opp-group-sub { margin: 0; font-size: 13.5px; color: var(--cm-mute); }
    .opp-group-icon { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 38px; height: 38px; border-radius: 11px; }
    .opp-group-icon .ds-icon { width: 18px; height: 18px; }
    .opp-group-icon.is-green { color: #17693F; background: #E3F3EA; }
    .opp-group-icon.is-blue { color: #1F5682; background: #E4EEF6; }
    .opp-group-icon.is-amber { color: #7A4A05; background: #FDF1DC; }
    .opp-group-icon.is-neutral { color: #4A4340; background: var(--cm-sand); }

    .opp-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 18px; padding: 16px 22px; border-top: 1px solid #EEE9E4; }
    .opp-group-head + .opp-row { border-top: 0; }
    .opp-row-main { display: flex; flex-direction: column; gap: 6px; flex: 1 1 320px; min-width: 0; }
    .opp-row-title { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 16px; font-weight: 700; line-height: 1.3; color: var(--cm-ink); overflow-wrap: anywhere; }
    .opp-row-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 13px; color: var(--cm-mute); }
    .opp-meta-label { color: var(--cm-mute); }
    .opp-badge, .opp-link-chip { display: inline-flex; align-items: center; height: 24px; padding: 0 9px; border-radius: 999px; font-size: 12.5px; font-weight: 600; color: var(--cm-ink2); background: #F4F0EC; }
    .opp-badge { color: var(--cm-brand-d); background: var(--cm-soft); }
    .opp-row-actions { flex: 0 0 auto; }


    /* Anchors: beat the theme's generic link colour so the design's greys and reds win. */
    .content-module a.content-tab { color: var(--cm-mute); }
    .content-module a.content-tab:hover { color: var(--cm-brand-d); }
    .content-module a.content-tab.is-active { color: var(--cm-brand-d); }
    .content-module .articles-filters a, .content-module a.opp-filter { color: var(--cm-mute); }
    .content-module .articles-filters a:hover, .content-module a.opp-filter:hover { color: var(--cm-ink); }
    .content-module .articles-filters a.is-active, .content-module a.opp-filter.is-active { color: var(--cm-ink); }
    .content-module a.article-row-title { color: var(--cm-ink); }
    .content-module a.article-row-title:hover { color: var(--cm-brand-d); }
    .content-module .autopilot-quick a { color: var(--cm-ink); }
    .content-module a.plan-topic { color: var(--cm-brand-d); }
    .content-module a.plan-article { color: var(--cm-ink); }
    .content-module a.plan-review-title, .content-module .plan-review-title a { color: var(--cm-ink); }

    /* ---------------------------------------------------------------
       Narrow screens
    --------------------------------------------------------------- */
    @media (max-width: 767.98px) {
        .content-module-heading > h4 { font-size: 24px; }
        .content-module-action .btn, .autopilot-hero .btn { width: 100%; }
        .article-row-meta { display: flex; flex: 1 1 100%; gap: 16px; order: 3; }
        .article-row-meta > div:nth-child(n) { flex: 1 1 0; min-width: 0; }
        .article-row-actions { margin-left: auto; }
        .articles-search { flex-basis: 100%; }
        .plan-row { grid-template-columns: minmax(0, 1fr); }
        .plan-row-cta { justify-self: start; }
        .plan-progress { flex-basis: 100%; }
        .opp-filters { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; }
        .opp-row-actions { flex-basis: 100%; }
        .opp-row-actions > div { flex-wrap: wrap; }
        .autopilot-card > .card-body { padding: 20px; }
    }
</style>
