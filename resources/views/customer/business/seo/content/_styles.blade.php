<style>
    /* ---------------------------------------------------------------
       Module header + tabs (stable: never part of the swapped region)
    --------------------------------------------------------------- */
    .content-module-header {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    .content-module-heading { min-width: 0; max-width: 46rem; }
    .content-module-heading > h4 { font-size: 1.5rem; line-height: 1.25; font-weight: 700; color: var(--color-text-primary, #262522); }
    .content-module-action:empty { display: none; }

    .content-tabs {
        display: flex;
        flex-wrap: nowrap;
        gap: 1.25rem;
        margin-bottom: 1.25rem;
        overflow-x: auto;
        border-bottom: 1px solid var(--color-border, #E5E1DA);
        scrollbar-width: none;
    }

    .content-tabs::-webkit-scrollbar { display: none; }

    .content-tab {
        padding: .5rem .125rem;
        margin-bottom: -1px;
        font-size: .8125rem;
        font-weight: 500;
        white-space: nowrap;
        color: var(--color-text-muted, #6F6D67);
        border-bottom: 2px solid transparent;
        text-decoration: none;
    }

    .content-tab:hover { color: var(--color-text-primary, #262522); text-decoration: none; }
    .content-tab.is-active { color: var(--color-primary, #B5524C); border-bottom-color: var(--color-primary, #B5524C); }

    /* ---------------------------------------------------------------
       Shared bits
    --------------------------------------------------------------- */
    .content-icon-tile {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        width: 2rem;
        height: 2rem;
        border-radius: .5rem;
        color: var(--color-primary, #B5524C);
        background: var(--color-primary-soft-bg, #F4E6E4);
    }

    .content-icon-tile--neutral { color: var(--color-text-muted, #6F6D67); background: var(--color-surface-secondary, #F1EEE8); }

    .content-chip {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .05rem .5rem;
        border-radius: 999px;
        font-size: .6875rem;
        font-weight: 600;
        color: var(--color-text-secondary, #4A4843);
        background: var(--color-surface-secondary, #F1EEE8);
    }

    .content-chip.is-success { color: var(--color-status-success, #2F7A4B); background: var(--color-status-success-soft-bg, #E6F2EA); }
    .content-chip.is-warning { color: var(--color-status-warning-text, #8A5F10); background: var(--color-status-warning-soft-bg, #FDF6E3); }

    .content-chip.is-success::before {
        content: '';
        width: .4rem;
        height: .4rem;
        border-radius: 50%;
        background: currentColor;
    }

    .content-label {
        display: block;
        font-size: .625rem;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: var(--color-text-muted, #6F6D67);
    }

    /* ---------------------------------------------------------------
       Autopilot
    --------------------------------------------------------------- */
    .autopilot-hero { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; }
    .autopilot-hero-main { display: flex; align-items: flex-start; gap: .85rem; min-width: 0; flex: 1 1 22rem; }

    .autopilot-steps {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
        margin-top: 1.1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--color-border, #E5E1DA);
    }

    .autopilot-step { display: flex; align-items: flex-start; gap: .6rem; font-size: .75rem; }
    .autopilot-step-num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        width: 1.35rem;
        height: 1.35rem;
        border-radius: 50%;
        font-size: .6875rem;
        color: var(--color-text-muted, #6F6D67);
        background: var(--color-surface-secondary, #F1EEE8);
    }

    .autopilot-step strong { display: block; font-size: .75rem; }

    .autopilot-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1.25rem; margin-bottom: 1.25rem; }

    .autopilot-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: .2rem;
        min-height: 8rem;
        padding: 1.25rem;
        text-align: center;
        border: 1px dashed var(--color-border-strong, #D6D1C7);
        border-radius: .6rem;
    }

    .autopilot-month { display: flex; gap: 1.1rem; margin: .5rem 0 .75rem; }
    .autopilot-month > div + div { padding-left: 1.1rem; border-left: 1px solid var(--color-border, #E5E1DA); }
    .autopilot-month .num { font-size: 1.5rem; font-weight: 600; line-height: 1.1; }

    .autopilot-meter { display: flex; gap: .3rem; margin-bottom: .75rem; }
    .autopilot-meter > i { flex: 1 1 0; height: .25rem; border-radius: 999px; background: var(--color-border, #E5E1DA); }
    .autopilot-meter > i.is-filled { background: var(--color-primary, #B5524C); }

    .autopilot-quick { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }

    .autopilot-quick a {
        display: flex;
        align-items: center;
        gap: .7rem;
        padding: .8rem 1rem;
        font-size: .8125rem;
        font-weight: 600;
        color: var(--color-text-primary, #262522);
        text-decoration: none;
        background: var(--color-surface, #fff);
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .75rem;
    }

    .autopilot-quick a:hover { border-color: var(--color-primary, #B5524C); }
    .autopilot-quick a .chev { margin-left: auto; color: var(--color-text-muted, #6F6D67); }

    /* ---------------------------------------------------------------
       Articles
    --------------------------------------------------------------- */
    .articles-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: 1rem; }

    .articles-filters {
        display: inline-flex;
        flex-wrap: wrap;
        padding: .1875rem;
        gap: .125rem;
        border: 1px solid var(--color-border-subtle, var(--color-border, #E5E1DA));
        border-radius: var(--radius-md, .625rem);
        background: var(--color-surface-secondary, #F1EEE8);
    }

    .articles-filters a {
        padding: .3125rem .75rem;
        font-size: .75rem;
        font-weight: 500;
        border-radius: calc(var(--radius-md, .625rem) - .1875rem);
        color: var(--color-text-secondary, #4A4843);
        text-decoration: none;
        white-space: nowrap;
    }

    .articles-filters a:hover { color: var(--color-text-primary, #262522); }
    .articles-filters a.is-active { font-weight: 600; color: var(--color-text-primary, #262522); background: var(--color-surface, #fff); box-shadow: var(--shadow-sm, 0 1px 2px rgba(0, 0, 0, .06)); }
    .articles-search { position: relative; flex: 0 1 20rem; min-width: 12rem; }
    .articles-search .ds-icon { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); color: var(--color-text-muted, #6F6D67); pointer-events: none; }
    .articles-search input { padding-left: 2.1rem; }

    .article-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .75rem 1rem;
        margin-bottom: .75rem;
        padding: .85rem 1.1rem;
        background: var(--color-surface, #fff);
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: var(--radius-lg, .875rem);
    }

    .article-row-main { flex: 1 1 14rem; min-width: 0; }
    a.article-row-title { display: block; font-size: .875rem; font-weight: 600; color: var(--color-text-primary, #262522); text-decoration: none; overflow-wrap: anywhere; }
    a.article-row-title:hover { color: var(--color-primary, #B5524C); }
    .article-row-slug { display: block; font-size: .75rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
    .article-row-meta { display: flex; flex-wrap: wrap; flex: 0 1 auto; gap: .75rem 1.75rem; font-size: .75rem; }
    .article-row-meta > div { min-width: 5.5rem; }
    .article-ai { margin-left: .375rem; vertical-align: middle; }
    .article-row-actions { display: flex; flex: 0 0 auto; align-items: center; gap: .4rem; margin-left: auto; }


    /* ---------------------------------------------------------------
       Content Plan
    --------------------------------------------------------------- */
    .content-icon-tile--warm { color: var(--color-status-warning-text, #8A5F10); background: var(--color-status-warning-soft-bg, #FDF6E3); }

    .plan-review {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .75rem 1rem;
        margin-bottom: 1rem;
        padding: .9rem 1.1rem;
        background: var(--color-status-warning-soft-bg, #FDF6E3);
        border: 1px solid var(--color-status-warning-border, #F0DDAE);
        border-radius: var(--radius-lg, .875rem);
    }

    .plan-review-main { flex: 1 1 16rem; min-width: 0; }
    .plan-review-label { color: var(--color-status-warning-text, #8A5F10); }
    .plan-review-title { font-size: .9375rem; font-weight: 600; color: var(--color-text-primary, #262522); overflow-wrap: anywhere; }
    .plan-review-text { font-size: .75rem; color: var(--color-text-secondary, #4A4843); }
    .plan-review-action { background: var(--color-surface, #fff); }

    .plan-pages {
        background: var(--color-surface, #fff);
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: var(--radius-lg, .875rem);
    }

    .plan-pages-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .75rem 1.5rem; padding: 1rem 1.25rem; }
    .plan-pages-heading { min-width: 0; }
    .plan-progress { display: flex; flex-direction: column; align-items: flex-end; gap: .35rem; }
    .plan-progress-label { font-size: .75rem; font-weight: 600; color: var(--color-text-primary, #262522); }
    .plan-progress-bar { display: flex; gap: .2rem; width: 10rem; max-width: 100%; }
    .plan-progress-bar > i { flex: 1 1 0; height: .25rem; border-radius: 999px; background: var(--color-border, #E5E1DA); }
    .plan-progress-bar > i.is-filled { background: var(--color-primary, #B5524C); }

    .plan-row {
        display: grid;
        grid-template-columns: minmax(10rem, 14rem) minmax(0, 1fr) auto;
        align-items: center;
        gap: .75rem 1.25rem;
        padding: .85rem 1.25rem;
        border-top: 1px solid var(--color-border, #E5E1DA);
    }

    .plan-row-page { display: flex; align-items: center; gap: .65rem; min-width: 0; }
    .plan-row-name { font-size: .875rem; font-weight: 600; color: var(--color-text-primary, #262522); overflow-wrap: anywhere; }
    .plan-row-topics { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; min-width: 0; }
    .plan-row-cta { justify-self: end; }

    .plan-topic,
    .plan-article {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        max-width: 100%;
        padding: .2rem .65rem;
        font-size: .75rem;
        text-decoration: none;
        border-radius: 999px;
        border: 1px solid var(--color-primary-soft-border, #E8C5C1);
        color: var(--color-primary, #B5524C);
        background: var(--color-surface, #fff);
    }

    .plan-topic:hover { background: var(--color-primary-soft-bg, #F4E6E4); text-decoration: none; }
    .plan-article { border-color: var(--color-border, #E5E1DA); color: var(--color-text-primary, #262522); }
    .plan-article:hover { border-color: var(--color-primary, #B5524C); text-decoration: none; }

    /* ---------------------------------------------------------------
       Opportunities
    --------------------------------------------------------------- */
    .opp-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .6rem 1rem; margin-bottom: 1rem; }

    .opp-filters {
        display: inline-flex;
        flex-wrap: wrap;
        gap: .25rem;
        padding: .1875rem;
        max-width: 100%;
        border-radius: var(--radius-md, .625rem);
        background: var(--color-surface-secondary, #F1EEE8);
    }

    .opp-filter {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .3rem .7rem;
        font-size: .75rem;
        font-weight: 500;
        white-space: nowrap;
        text-decoration: none;
        border-radius: calc(var(--radius-md, .625rem) - .1875rem);
        color: var(--color-text-secondary, #4A4843);
    }

    .opp-filter span { color: var(--color-text-muted, #6F6D67); }
    .opp-filter:hover { color: var(--color-text-primary, #262522); text-decoration: none; }
    .opp-filter.is-active { font-weight: 600; color: var(--color-text-primary, #262522); background: var(--color-surface, #fff); box-shadow: var(--shadow-sm, 0 1px 2px rgba(0, 0, 0, .06)); }

    .opp-trust { display: inline-flex; align-items: center; gap: .4rem; margin: 0; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }

    .opp-group {
        margin-bottom: 1rem;
        overflow: hidden;
        background: var(--color-surface, #fff);
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: var(--radius-lg, .875rem);
    }

    .opp-group-head { display: flex; align-items: center; gap: .75rem; padding: .8rem 1.1rem; background: var(--color-surface-secondary, #F8F6F2); }
    .opp-group-title { margin: 0; font-size: .875rem; font-weight: 600; color: var(--color-text-primary, #262522); }
    .opp-group-sub { margin: 0; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }

    .opp-group-icon { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 2rem; height: 2rem; border-radius: .5rem; }
    .opp-group-icon.is-green { color: var(--color-status-success, #2F7A4B); background: var(--color-status-success-soft-bg, #E6F2EA); }
    .opp-group-icon.is-blue { color: var(--color-status-info-text, #2C5F8A); background: var(--color-status-info-soft-bg, #E7F0F8); }
    .opp-group-icon.is-amber { color: var(--color-status-warning-text, #8A5F10); background: var(--color-status-warning-soft-bg, #FDF6E3); }
    .opp-group-icon.is-neutral { color: var(--color-text-muted, #6F6D67); background: var(--color-surface-secondary, #F1EEE8); }

    .opp-row { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem 1rem; padding: .8rem 1.1rem; border-top: 1px solid var(--color-border, #E5E1DA); }
    .opp-row-main { flex: 1 1 18rem; min-width: 0; }
    .opp-row-title { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; font-size: .875rem; font-weight: 700; color: var(--color-text-primary, #262522); overflow-wrap: anywhere; }
    .opp-row-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .3rem .4rem; margin-top: .3rem; font-size: .6875rem; }
    .opp-meta-label { color: var(--color-text-muted, #6F6D67); }
    .opp-badge { padding: .05rem .5rem; font-weight: 600; border-radius: 999px; color: var(--color-primary, #B5524C); background: var(--color-primary-soft-bg, #F4E6E4); }
    .opp-link-chip { padding: .05rem .5rem; border-radius: 999px; color: var(--color-text-secondary, #4A4843); background: var(--color-surface-secondary, #F1EEE8); }
    .opp-row-actions { flex: 0 0 auto; margin-left: auto; }

    /* ---------------------------------------------------------------
       Narrow screens
    --------------------------------------------------------------- */
    @media (max-width: 991.98px) {
        .autopilot-grid { grid-template-columns: minmax(0, 1fr); }
    }

    @media (max-width: 767.98px) {
        .autopilot-steps,
        .autopilot-quick { grid-template-columns: minmax(0, 1fr); }
        .autopilot-hero .btn { width: 100%; }
        .article-row { flex-wrap: wrap; }
        .article-row-meta { flex-basis: 100%; gap: 1rem; order: 3; }
        .article-row-meta > div { min-width: 0; flex: 1 1 0; }
        .article-row-actions { margin-left: auto; }
        .articles-search { flex-basis: 100%; }
        .plan-row { grid-template-columns: minmax(0, 1fr); }
        .plan-row-cta { justify-self: start; }
        .plan-progress { align-items: flex-start; }
        .opp-filters { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; }
        .opp-row-actions { flex-basis: 100%; margin-left: 0; }
    }
</style>
