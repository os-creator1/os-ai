{{--
    Visual layer for the Growth Center. Plain inline <style> (this repository's
    Mix/Node build is not runnable by this lane), using ONLY `var(--color-*)`
    runtime tokens already defined in resources/scss/base/tokens/_colors.scss,
    so an active tenant theme still applies and no new hex value is introduced.
    The fallbacks after each var() are the Factory preset's own values.

    Included via @section('page-style') on every Growth view.
--}}
<style>
    .gc { --gc-gap: 1rem; }

    /* ── Page header ───────────────────────────────────────────── */
    .gc-header { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem 1.5rem; margin-bottom: 1rem; }
    .gc-title { font-size: 1.5rem; font-weight: 600; line-height: 1.2; margin: 0; color: var(--color-text-primary, #262522); }
    .gc-subtitle { margin: .25rem 0 0; color: var(--color-text-muted, #6F6D67); font-size: .9375rem; max-width: 46rem; }
    .gc-header-meta { display: flex; align-items: center; gap: .75rem; color: var(--color-text-muted, #6F6D67); font-size: .8125rem; }

    .gc-tabs { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; border-bottom: 1px solid var(--color-border, #E5E1DA); margin-bottom: 1.25rem; overflow-x: auto; }
    .gc-tab { display: inline-flex; align-items: center; gap: .4rem; padding: .6rem .1rem; margin-bottom: -1px; font-size: .875rem; font-weight: 500; color: var(--color-text-muted, #6F6D67); border-bottom: 2px solid transparent; text-decoration: none; white-space: nowrap; }
    .gc-tab:hover { color: var(--color-text-primary, #262522); text-decoration: none; }
    .gc-tab.is-active { color: var(--color-primary, #B5524C); border-bottom-color: var(--color-primary, #B5524C); }
    .gc-tab-count { background: var(--color-primary-soft-bg, #F4E6E4); color: var(--color-primary, #B5524C); border-radius: 999px; padding: 0 .45rem; font-size: .72rem; font-weight: 600; line-height: 1.35; }

    /* ── Hero: score + tiles ───────────────────────────────────── */
    .gc-hero { display: grid; grid-template-columns: minmax(0, 22rem) minmax(0, 1fr); gap: var(--gc-gap); margin-bottom: var(--gc-gap); }
    .gc-panel { background: var(--color-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem; padding: 1.25rem; }
    .gc-panel-title { margin: 0 0 .75rem; font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }

    .gc-score { display: flex; gap: 1.1rem; align-items: center; }
    .gc-ring { position: relative; width: 7.5rem; height: 7.5rem; flex: none; }
    .gc-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .gc-ring-track { fill: none; stroke: var(--color-border-subtle, #F2F0ED); stroke-width: 9; }
    .gc-ring-value { fill: none; stroke: var(--color-primary, #B5524C); stroke-width: 9; stroke-linecap: round; transition: stroke-dashoffset .6s ease; }
    .gc-ring-value.is-good { stroke: var(--color-status-success, #28C76F); }
    .gc-ring-value.is-fair { stroke: var(--color-status-warning, #FF9F43); }
    .gc-ring-value.is-low { stroke: var(--color-status-danger, #EA5455); }
    .gc-ring-number { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1; }
    .gc-ring-number strong { font-size: 2.1rem; font-weight: 700; color: var(--color-text-primary, #262522); }
    .gc-ring-number span { font-size: .7rem; color: var(--color-text-muted, #6F6D67); margin-top: .25rem; }
    .gc-score-copy { min-width: 0; }
    .gc-score-based { font-size: .8125rem; color: var(--color-text-muted, #6F6D67); margin: 0 0 .35rem; }
    .gc-score-move { font-size: .875rem; font-weight: 500; margin: 0; display: inline-flex; align-items: center; gap: .3rem; }
    .gc-up { color: var(--color-status-success, #28C76F); }
    .gc-down { color: var(--color-status-danger, #EA5455); }
    .gc-flat { color: var(--color-text-muted, #6F6D67); }
    .gc-spark { margin-top: .75rem; width: 100%; height: 2.25rem; display: block; }
    .gc-spark polyline { fill: none; stroke: var(--color-primary, #B5524C); stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }
    .gc-spark circle { fill: var(--color-primary, #B5524C); }
    .gc-link { color: var(--color-link, var(--color-primary, #B5524C)); font-size: .8125rem; text-decoration: none; }
    .gc-link:hover { text-decoration: underline; }

    .gc-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); gap: var(--gc-gap); }
    .gc-tile { display: flex; flex-direction: column; justify-content: space-between; gap: .5rem; padding: 1rem 1.1rem; }
    .gc-tile-label { font-size: .78rem; color: var(--color-text-muted, #6F6D67); margin: 0; }
    .gc-tile-value { font-size: 1.65rem; font-weight: 650; line-height: 1.1; margin: 0; color: var(--color-text-primary, #262522); }
    .gc-tile-note { font-size: .75rem; color: var(--color-text-muted, #6F6D67); margin: 0; }
    .gc-tile.is-alert .gc-tile-value { color: var(--color-status-danger, #EA5455); }

    /* ── Sections ──────────────────────────────────────────────── */
    .gc-section { margin-bottom: 1.5rem; }
    .gc-section-head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; margin-bottom: .75rem; }
    .gc-section-title { margin: 0; font-size: 1.0625rem; font-weight: 600; color: var(--color-text-primary, #262522); }
    .gc-section-sub { margin: .1rem 0 0; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); }

    /* ── Opportunity card ──────────────────────────────────────── */
    .gc-list { display: flex; flex-direction: column; gap: .75rem; }
    .gc-opp { position: relative; background: var(--color-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem; padding: 1rem 1.15rem 1rem 1.35rem; display: grid; gap: .6rem; }
    .gc-opp::before { content: ""; position: absolute; left: 0; top: .9rem; bottom: .9rem; width: 3px; border-radius: 3px; background: var(--color-border-strong, #A29F9A); }
    .gc-opp.impact-high::before { background: var(--color-status-danger, #EA5455); }
    .gc-opp.impact-medium::before { background: var(--color-status-warning, #FF9F43); }
    .gc-opp.is-muted { opacity: .72; }
    .gc-opp-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem .6rem; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .gc-pill { display: inline-flex; align-items: center; gap: .3rem; border-radius: 999px; padding: .1rem .55rem; font-size: .72rem; font-weight: 600; letter-spacing: .02em; background: var(--color-secondary-soft-bg, #EDE9E8); color: var(--color-text-secondary, #676664); }
    .gc-pill.impact-high { background: var(--color-status-danger-soft-bg, #FCE4E4); color: var(--color-status-danger, #EA5455); }
    .gc-pill.impact-medium { background: var(--color-status-warning-soft-bg, #FFF0DE); color: #B86A15; }
    .gc-pill.is-ok { background: var(--color-status-success-soft-bg, #DFF7E9); color: #1B8A4E; }
    .gc-opp-headline { margin: 0; font-size: 1rem; font-weight: 600; line-height: 1.35; color: var(--color-text-primary, #262522); }
    .gc-opp-headline a { color: inherit; text-decoration: none; }
    .gc-opp-headline a:hover { text-decoration: underline; }
    .gc-opp-why { margin: 0; font-size: .875rem; color: var(--color-text-secondary, #676664); }
    .gc-facts { display: flex; flex-wrap: wrap; gap: .4rem .9rem; margin: 0; padding: 0; list-style: none; font-size: .8125rem; color: var(--color-text-secondary, #676664); }
    .gc-facts li { display: inline-flex; align-items: center; gap: .3rem; }
    .gc-facts strong { color: var(--color-text-primary, #262522); font-weight: 600; }
    .gc-opp-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .15rem; }
    .gc-menu { position: relative; }
    .gc-menu > summary { list-style: none; cursor: pointer; }
    .gc-menu > summary::-webkit-details-marker { display: none; }
    .gc-menu-body { position: absolute; z-index: 20; top: calc(100% + .35rem); left: 0; min-width: 14rem; background: var(--color-popover-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); border-radius: .6rem; padding: .6rem; box-shadow: 0 8px 24px rgba(38, 37, 34, .12); display: grid; gap: .35rem; }
    .gc-menu-body button, .gc-menu-body .gc-menu-item { text-align: left; background: none; border: 0; padding: .4rem .5rem; border-radius: .4rem; font-size: .8125rem; color: var(--color-text-primary, #262522); width: 100%; }
    .gc-menu-body button:hover { background: var(--color-row-hover, #F4E6E4); }
    .gc-menu-body label { font-size: .75rem; color: var(--color-text-muted, #6F6D67); margin: 0; }
    .gc-menu-body input[type="date"], .gc-menu-body select { font-size: .8125rem; }

    /* ── Filters ───────────────────────────────────────────────── */
    .gc-chips { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .9rem; }
    .gc-chip { display: inline-flex; align-items: center; gap: .4rem; padding: .3rem .8rem; border-radius: 999px; border: 1px solid var(--color-border, #E5E1DA); background: var(--color-surface, #fff); color: var(--color-text-secondary, #676664); font-size: .8125rem; text-decoration: none; }
    .gc-chip:hover { border-color: var(--color-border-strong, #A29F9A); text-decoration: none; }
    .gc-chip.is-active { background: var(--color-primary-soft-bg, #F4E6E4); border-color: var(--color-primary-border, #E3C3BF); color: var(--color-primary, #B5524C); font-weight: 600; }
    .gc-chip small { font-weight: 600; opacity: .8; }
    .gc-filters { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: 1rem; }
    .gc-filters .form-control, .gc-filters .form-select { width: auto; min-width: 9rem; max-width: 100%; font-size: .8125rem; }

    /* ── Two-column insight rows ───────────────────────────────── */
    .gc-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--gc-gap); margin-bottom: 1.5rem; }
    .gc-bullets { margin: 0; padding: 0; list-style: none; display: grid; gap: .55rem; font-size: .875rem; color: var(--color-text-primary, #262522); }
    .gc-bullets li { display: grid; grid-template-columns: 1.25rem 1fr; gap: .5rem; align-items: start; }
    .gc-bullets .ds-icon { margin-top: .12rem; }
    .gc-empty-line { margin: 0; font-size: .875rem; color: var(--color-text-muted, #6F6D67); }

    /* ── Category grid ─────────────────────────────────────────── */
    .gc-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); gap: var(--gc-gap); }
    .gc-cat { padding: 1rem 1.1rem; display: grid; gap: .5rem; }
    .gc-cat-head { display: flex; justify-content: space-between; align-items: baseline; gap: .5rem; }
    .gc-cat-name { font-size: .875rem; font-weight: 600; margin: 0; color: var(--color-text-primary, #262522); }
    .gc-cat-score { font-size: 1.25rem; font-weight: 700; margin: 0; }
    .gc-cat-na { font-size: .8125rem; color: var(--color-text-muted, #6F6D67); font-weight: 500; }
    .gc-bar { height: .4rem; border-radius: 999px; background: var(--color-border-subtle, #F2F0ED); overflow: hidden; }
    .gc-bar > span { display: block; height: 100%; border-radius: 999px; background: var(--color-primary, #B5524C); }
    .gc-bar.is-good > span { background: var(--color-status-success, #28C76F); }
    .gc-bar.is-fair > span { background: var(--color-status-warning, #FF9F43); }
    .gc-bar.is-low > span { background: var(--color-status-danger, #EA5455); }
    .gc-cat-foot { font-size: .75rem; color: var(--color-text-muted, #6F6D67); margin: 0; }

    /* ── Detail page ───────────────────────────────────────────── */
    .gc-detail { display: grid; grid-template-columns: minmax(0, 1fr) 19rem; gap: var(--gc-gap); align-items: start; }
    .gc-detail-main > .gc-panel + .gc-panel { margin-top: var(--gc-gap); }
    .gc-detail h2 { font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); margin: 0 0 .6rem; }
    .gc-records { margin: 0; padding: 0; list-style: none; display: grid; gap: .35rem; }
    .gc-records li { display: flex; justify-content: space-between; gap: 1rem; padding: .5rem .6rem; border-radius: .5rem; background: var(--color-surface-secondary, #FBFAF7); font-size: .875rem; }
    .gc-records a { color: var(--color-text-primary, #262522); text-decoration: none; font-weight: 500; }
    .gc-records a:hover { text-decoration: underline; }
    .gc-timeline { margin: 0; padding: 0; list-style: none; display: grid; gap: .6rem; font-size: .8125rem; color: var(--color-text-secondary, #676664); }
    .gc-timeline small { color: var(--color-text-muted, #6F6D67); }
    .gc-note { font-size: .8125rem; color: var(--color-text-muted, #6F6D67); margin: .6rem 0 0; }

    /* ── Score page table ──────────────────────────────────────── */
    .gc-why { display: grid; gap: .6rem; }
    .gc-why-cat { border: 1px solid var(--color-border, #E5E1DA); border-radius: .65rem; padding: .85rem 1rem; background: var(--color-surface, #fff); }
    .gc-why-rules { margin: .6rem 0 0; padding: 0; list-style: none; display: grid; gap: .3rem; font-size: .8125rem; }
    .gc-why-rules li { display: grid; grid-template-columns: 1fr auto; gap: .75rem; color: var(--color-text-secondary, #676664); }
    .gc-formula { font-size: .8125rem; color: var(--color-text-secondary, #676664); line-height: 1.55; }
    .gc-formula code { background: var(--color-surface-secondary, #FBFAF7); border-radius: .3rem; padding: .05rem .35rem; }

    /* ── Banners ───────────────────────────────────────────────── */
    .gc-banner { display: flex; gap: .75rem; align-items: flex-start; padding: .85rem 1rem; border-radius: .65rem; background: var(--color-status-warning-soft-bg, #FFF0DE); border: 1px solid var(--color-status-warning-border, #FFD9AE); font-size: .875rem; margin-bottom: 1rem; }
    .gc-banner.is-info { background: var(--color-primary-soft-bg, #F4E6E4); border-color: var(--color-primary-border, #E3C3BF); }
    .gc-welcome { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .75rem; margin-top: 1rem; }
    .gc-welcome-card { padding: .8rem .9rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .65rem; background: var(--color-surface-secondary, #FBFAF7); font-size: .8125rem; }
    .gc-welcome-card strong { display: block; font-size: .875rem; margin-bottom: .15rem; color: var(--color-text-primary, #262522); }

    @media (max-width: 991.98px) {
        .gc-hero, .gc-detail { grid-template-columns: minmax(0, 1fr); }
        .gc-cols { grid-template-columns: minmax(0, 1fr); }
    }

    @media (max-width: 575.98px) {
        .gc-score { flex-direction: column; align-items: flex-start; }
        .gc-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .gc-tile-value { font-size: 1.4rem; }
        .gc-opp { padding: .9rem .9rem .9rem 1.1rem; }
        .gc-opp-actions .btn { flex: 1 1 auto; justify-content: center; }
        .gc-menu-body { position: static; box-shadow: none; margin-top: .35rem; min-width: 0; }
        .gc-menu { width: 100%; }
        .gc-menu > summary { width: 100%; justify-content: center; }
    }
</style>
