{{-- Styles for the Google Business Profile overview. Design tokens only; no new colours. --}}
<style>
    .gbp-header { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
    .gbp-connection { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
    .gbp-connection .badge { display: inline-flex; align-items: center; gap: var(--space-1); }
    .gbp-dot { width: .4rem; height: .4rem; border-radius: var(--radius-full); background: currentColor; }

    .gbp-card-body { padding: var(--space-5); }
    .gbp-eyebrow { display: block; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted); }
    .gbp-break { overflow-wrap: anywhere; }
    .min-w-0 { min-width: 0; }

    .gbp-profile-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-4); }
    .gbp-profile-title { display: flex; align-items: center; gap: var(--space-3); min-width: 0; }
    .gbp-profile-icon { display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; flex-shrink: 0; border-radius: var(--radius-lg); background: var(--color-accent-soft-bg); color: var(--color-accent-primary); }
    .gbp-chips { display: flex; flex-wrap: wrap; gap: var(--space-2); }
    .gbp-chips .badge { display: inline-flex; align-items: center; gap: var(--space-1); }

    .gbp-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--space-4); margin: 0; padding: var(--space-4); border: 1px solid var(--color-border-neutral); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .gbp-summary dt { font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted); margin-bottom: var(--space-1); }
    .gbp-summary dd { margin: 0; font-weight: 500; color: var(--color-text-primary); }
    .gbp-summary-flat { margin-top: var(--space-3); }

    .gbp-more { margin-top: var(--space-3); }
    .gbp-more > summary { list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: var(--space-2); color: var(--color-accent-primary); font-size: .875rem; font-weight: 500; }
    .gbp-more > summary::-webkit-details-marker { display: none; }
    .gbp-more > summary .ds-icon { transition: transform .15s ease; }
    .gbp-more[open] > summary .ds-icon { transform: rotate(180deg); }

    .gbp-diff-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-4); }
    .gbp-card-body.gbp-diff-head { margin-bottom: 0; }
    .gbp-match { display: flex; align-items: center; gap: var(--space-3); }
    .gbp-match-icon { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; flex-shrink: 0; border-radius: var(--radius-full); background: var(--color-status-success-soft-bg, var(--color-accent-soft-bg)); color: var(--color-status-success, var(--color-accent-primary)); }

    .gbp-diff { margin-top: var(--space-3); padding: var(--space-3) var(--space-4); border: 1px solid var(--color-status-warning-border); border-radius: var(--radius-lg); background: var(--color-status-warning-soft-bg); }
    .gbp-diff-field { margin: 0 0 var(--space-2); font-weight: 600; color: var(--color-text-primary); }
    .gbp-diff-pair { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: var(--space-3); }
    .gbp-value { display: flex; flex-direction: column; gap: var(--space-1); padding: var(--space-2) var(--space-3); border: 1px solid var(--color-border-neutral); border-radius: var(--radius-md); background: var(--color-surface-primary); min-width: 0; }
    .gbp-unset { color: var(--color-text-muted); }
    .gbp-swap { display: inline-flex; color: var(--color-text-muted); }

    @media (max-width: 991.98px) {
        .gbp-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 575.98px) {
        .gbp-card-body { padding: var(--space-4); }
        .gbp-summary { grid-template-columns: minmax(0, 1fr); }
        .gbp-diff-pair { grid-template-columns: minmax(0, 1fr); }
        .gbp-swap { justify-self: center; transform: rotate(90deg); }
        .gbp-diff-head .btn, .gbp-header .btn { width: 100%; justify-content: center; }
    }

    /* ---- Connect / reconnect hero (design: connect) ---- */
    .gbp-connect { max-width: 880px; padding: var(--space-6) var(--space-5) var(--space-5); text-align: center; display: flex; flex-direction: column; align-items: center; gap: var(--space-4); }
    .gbp-hero-icon, .gbp-connect-icon { display: inline-flex; align-items: center; justify-content: center; width: 4.5rem; height: 4.5rem; border-radius: var(--radius-xl, var(--radius-lg)); background: var(--color-accent-soft-bg); color: var(--color-accent-primary); }
    .gbp-connect h2 { margin: 0; }
    .gbp-connect-lead { max-width: 32.5rem; margin: 0 auto; color: var(--color-text-secondary, var(--color-text-muted)); }
    .gbp-benefits { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-3); width: 100%; text-align: left; }
    .gbp-benefit { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-4); border: 1px solid var(--color-border-neutral); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .gbp-benefit .gbp-tile { width: 2.4rem; height: 2.4rem; }
    .gbp-tile { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; width: 2rem; height: 2rem; border-radius: var(--radius-md); background: var(--color-accent-soft-bg); color: var(--color-accent-primary); }
    .gbp-tile.is-warning { background: var(--color-status-warning-soft-bg); color: var(--color-status-warning, var(--color-text-primary)); }
    .gbp-tile.is-neutral { background: var(--color-surface-secondary); color: var(--color-text-muted); }
    .gbp-connect-note { display: inline-flex; align-items: center; gap: var(--space-2); }
    .gbp-connect-cta { min-height: 3rem; padding-inline: var(--space-6); }

    /* ---- Section cards (design: connected overview) ---- */
    .gbp-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-4); max-width: 1120px; }
    .gbp-section-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2) var(--space-3); margin-bottom: var(--space-3); }
    .gbp-section-head h2 { margin: 0; }
    .gbp-link { font-size: .8125rem; font-weight: 600; color: var(--color-accent-primary); }
    .gbp-kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: var(--space-3) var(--space-5); margin: var(--space-4) 0 0; }
    .gbp-kv > div { display: flex; flex-direction: column; gap: var(--space-1); min-width: 0; }
    .gbp-kv dt { font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted); }
    .gbp-kv dd { margin: 0; color: var(--color-text-primary); overflow-wrap: anywhere; }
    .gbp-tags { display: flex; flex-wrap: wrap; gap: var(--space-2); }
    .gbp-tag { display: inline-flex; align-items: center; padding: .15rem .6rem; border-radius: var(--radius-full); background: var(--color-surface-secondary); border: 1px solid var(--color-border-neutral); font-size: .78rem; color: var(--color-text-primary); }
    .gbp-tag.is-primary { background: var(--color-accent-soft-bg); border-color: transparent; color: var(--color-accent-primary); font-weight: 600; }

    .gbp-unavail { display: flex; gap: var(--space-3); align-items: flex-start; padding: var(--space-4); border: 1px dashed var(--color-border-neutral); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .gbp-unavail p { margin: 0; }
    .gbp-unavail strong { display: block; color: var(--color-text-primary); }

    .gbp-trend { display: grid; gap: var(--space-2); margin-top: var(--space-4); }
    .gbp-trend-row { display: grid; grid-template-columns: 7.5rem minmax(0, 1fr); align-items: center; gap: var(--space-3); font-size: .8125rem; }
    .gbp-trend-bars { display: flex; flex-direction: column; gap: 3px; }
    .gbp-trend-bar { display: flex; align-items: center; gap: var(--space-2); }
    .gbp-trend-bar > span:first-child { display: block; height: .5rem; min-width: 2px; border-radius: var(--radius-full); background: var(--color-accent-primary); }
    .gbp-trend-bar.is-prev > span:first-child { background: var(--color-border-neutral); }
    .gbp-trend-legend { display: flex; flex-wrap: wrap; gap: var(--space-3); margin-top: var(--space-3); }

    .gbp-actions { display: flex; flex-direction: column; }
    .gbp-action { display: flex; align-items: flex-start; gap: var(--space-3); padding: var(--space-3) 0; border-top: 1px solid var(--color-border-neutral); }
    .gbp-action:first-child { border-top: 0; padding-top: 0; }
    .gbp-action p { margin: 0; }
    .gbp-action b { display: block; color: var(--color-text-primary); }
    .gbp-action > div { flex: 1; min-width: 0; }

    .gbp-photo-grid { display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-2); }
    .gbp-photo-slot { display: flex; align-items: center; justify-content: center; min-height: 5.5rem; border: 1px dashed var(--color-border-neutral); border-radius: var(--radius-md); background: var(--color-surface-secondary); color: var(--color-text-muted); font-size: .75rem; font-weight: 600; }

    /* ---- Listing picker (design: pick listing) ---- */
    .gbp-narrow { max-width: 880px; }
    .gbp-account { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); padding: var(--space-3) var(--space-4); }
    .gbp-account > div { flex: 1 1 16rem; min-width: 0; }
    .gbp-pick-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); padding: var(--space-4); }
    .gbp-pick-search { width: 17.5rem; max-width: 100%; }
    .gbp-pick-list { border-top: 1px solid var(--color-border-neutral); }
    .gbp-pick-row { display: flex; align-items: center; gap: var(--space-3); min-height: 3.75rem; padding: var(--space-3) var(--space-4); border-top: 1px solid var(--color-border-neutral); cursor: pointer; margin: 0; }
    .gbp-pick-row:first-child { border-top: 0; }
    .gbp-pick-row:hover { background: var(--color-surface-secondary); }
    .gbp-pick-row:has(input:checked) { background: var(--color-accent-soft-bg); box-shadow: inset 3px 0 0 var(--color-accent-primary); }
    .gbp-pick-row.is-disabled { cursor: not-allowed; opacity: .65; }
    .gbp-pick-row .gbp-letter { display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; flex-shrink: 0; border-radius: var(--radius-md); background: var(--color-surface-secondary); color: var(--color-text-muted); font-weight: 700; }
    .gbp-pick-row:has(input:checked) .gbp-letter { background: var(--color-accent-primary); color: #fff; }
    .gbp-pick-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .gbp-pick-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); padding: var(--space-3) var(--space-4); border-top: 1px solid var(--color-border-neutral); background: var(--color-surface-secondary); }

    /* ---- Platform vs Google ---- */
    .gbp-cmp-summary { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-bottom: var(--space-3); }
    .gbp-cmp-row { display: grid; grid-template-columns: minmax(7rem, 1fr) minmax(0, 2fr) minmax(0, 2fr) minmax(8rem, 1fr); gap: var(--space-3); align-items: start; padding: var(--space-3) 0; border-top: 1px solid var(--color-border-neutral); }
    .gbp-cmp-row.is-head { border-top: 0; padding-top: 0; }
    .gbp-cmp-row.is-head > div { font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted); }
    .gbp-cmp-row.is-mismatch { background: var(--color-status-warning-soft-bg); margin-inline: calc(var(--space-3) * -1); padding-inline: var(--space-3); border-radius: var(--radius-md); }
    .gbp-cmp-row > div { min-width: 0; overflow-wrap: anywhere; }
    .gbp-cmp-label { display: none; }
    .gbp-stale { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }

    @media (max-width: 991.98px) {
        .gbp-benefits { grid-template-columns: minmax(0, 1fr); }
    }
    @media (max-width: 767.98px) {
        .gbp-cmp-row { grid-template-columns: minmax(0, 1fr); gap: var(--space-1); }
        .gbp-cmp-row.is-head { display: none; }
        .gbp-cmp-label { display: block; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted); }
        .gbp-trend-row { grid-template-columns: minmax(0, 1fr); }
        .gbp-connect { padding: var(--space-5) var(--space-4); }
    }
    @media (max-width: 575.98px) {
        .gbp-stale .btn, .gbp-pick-foot .btn { width: 100%; justify-content: center; }
    }
</style>
