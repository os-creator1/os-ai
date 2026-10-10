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
        padding: .2rem;
        gap: .15rem;
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .6rem;
        background: var(--color-surface, #fff);
    }

    .articles-filters a {
        padding: .25rem .7rem;
        font-size: .75rem;
        font-weight: 500;
        border-radius: .4rem;
        color: var(--color-text-secondary, #4A4843);
        text-decoration: none;
        white-space: nowrap;
    }

    .articles-filters a.is-active { color: var(--color-text-primary, #262522); background: var(--color-surface-secondary, #F1EEE8); box-shadow: inset 0 0 0 1px var(--color-border, #E5E1DA); }
    .articles-search { position: relative; flex: 0 1 20rem; min-width: 12rem; }
    .articles-search .ds-icon { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); color: var(--color-text-muted, #6F6D67); pointer-events: none; }
    .articles-search input { padding-left: 2.1rem; }

    .article-row {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: .75rem;
        padding: .85rem 1.1rem;
        background: var(--color-surface, #fff);
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .75rem;
    }

    .article-row-main { flex: 1 1 14rem; min-width: 0; }
    a.article-row-title { display: block; font-size: .875rem; font-weight: 600; color: var(--color-text-primary, #262522); text-decoration: none; overflow-wrap: anywhere; }
    a.article-row-title:hover { color: var(--color-primary, #B5524C); }
    .article-row-slug { display: block; font-size: .75rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
    .article-row-meta { display: flex; flex: 0 0 auto; gap: 1.75rem; font-size: .75rem; }
    .article-row-meta > div { min-width: 5.5rem; }
    .article-row-actions { display: flex; flex: 0 0 auto; align-items: center; gap: .4rem; }

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
    }
</style>
