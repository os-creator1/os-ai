{{--
    Styles for SEO > Reviews. Same visual language as the Citations and
    Keywords passes (compact fact cards, a segmented filter, calm warm cards):
    design tokens only, no new colours. Class prefix `rv-` is local to this page.
--}}
<style>
    .rv-header { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
    .rv-header-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
    .rv-sub { font-size: .875rem; color: var(--color-link, #B5524C); }

    /* ---- fact cards: compact, the number is bold but never huge ---- */
    .rv-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
    .rv-stat { padding: .875rem 1rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem; background: var(--color-surface, #fff); min-width: 0; }
    .rv-stat-warm { background: color-mix(in srgb, var(--color-status-pending, #B58B46) 7%, var(--color-surface, #fff)); border-color: color-mix(in srgb, var(--color-status-pending, #B58B46) 35%, var(--color-border, #E5E1DA)); }
    .rv-stat-label { margin: 0; font-size: .75rem; font-weight: 500; color: var(--color-text-muted, #6F6D67); }
    .rv-stat-value { margin: .25rem 0 .25rem; font-size: 1.75rem; line-height: 1.15; font-weight: 700; color: var(--color-text-primary, #262522); }
    .rv-stat-value small { font-size: .8125rem; font-weight: 500; color: var(--color-text-muted, #6F6D67); }
    .rv-stat-value-text { font-size: 1.375rem; line-height: 1.3; padding-block: .25rem .125rem; }
    .rv-stat-value-warn { color: color-mix(in srgb, var(--color-status-pending, #B58B46) 80%, #000); }
    .rv-stat-value-ok { color: color-mix(in srgb, var(--color-status-success, #28C76F) 70%, #000); }
    .rv-stat-sub { margin: 0; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .rv-bar { display: flex; gap: 2px; height: .3125rem; margin: .125rem 0 .5rem; border-radius: 999px; overflow: hidden; }
    .rv-bar > span { display: block; min-width: 4px; }
    .rv-bar-ok { background: color-mix(in srgb, var(--color-status-success, #28C76F) 70%, #000); }
    .rv-bar-wait { background: var(--color-status-pending, #B58B46); opacity: .8; }
    .rv-bar-off { background: var(--color-chart-neutral, #A8A29A); opacity: .6; }
    @media (max-width: 991.98px) { .rv-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    /* ---- record panel: opens from the header button via the :target hash ---- */
    .rv-record { display: none; }
    .rv-record:target { display: block; }
    .rv-record-head { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-1); }
    .rv-record-forms { display: grid; gap: var(--space-3); }
    .rv-record-fields { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--space-3); }
    .rv-record-fields .rv-field { margin-bottom: 0; min-width: 12rem; flex: 1 1 12rem; }
    .rv-field { display: flex; flex-direction: column; gap: var(--space-1); margin-bottom: var(--space-3); }

    /* ---- location review-link card ---- */
    .rv-location:target { border-color: var(--color-accent-border); box-shadow: var(--shadow-md); }
    .rv-location-head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-2); margin-bottom: var(--space-3); }
    .rv-location-title { display: flex; align-items: center; gap: var(--space-2); min-width: 0; }
    .rv-location-icon { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; flex-shrink: 0; border-radius: .5rem; background: var(--color-accent-soft-bg); color: var(--color-accent-primary); }
    .rv-location-badges { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-1); flex-shrink: 0; }
    .rv-location-badges .badge { display: inline-flex; align-items: center; gap: .25rem; }
    .rv-section-label { font-weight: 600; color: var(--color-text-primary); overflow-wrap: anywhere; }
    .rv-link-row { display: grid; gap: var(--space-2); }
    .rv-link-box { display: flex; align-items: center; gap: .5rem; padding: .5rem .625rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .5rem; background: var(--color-surface-secondary, #FBFAF7); }
    .rv-link-field { flex: 1; min-width: 0; padding: 0; border: 0; outline: 0; background: transparent; font-family: var(--bs-font-monospace); font-size: .75rem; color: var(--color-text-primary); text-overflow: ellipsis; }
    .rv-link-copy { flex: none; display: inline-flex; padding: .125rem; border: 0; background: transparent; color: var(--color-accent-primary); cursor: pointer; }
    .rv-link-actions { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-2); }
    .rv-link-actions .btn { justify-content: center; }
    .rv-panel { margin-top: var(--space-3); }
    .rv-panel > summary { list-style: none; cursor: pointer; }
    .rv-panel > summary::-webkit-details-marker { display: none; }
    .rv-change { display: inline-flex; align-items: center; gap: .375rem; font-size: .8125rem; font-weight: 500; color: var(--color-link, #B5524C); }
    .rv-change:hover { color: var(--color-link-hover, #A83E38); }
    .rv-panel-body { margin-top: var(--space-2); padding: var(--space-3); border: 1px solid var(--color-border-neutral); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .rv-note { display: flex; gap: var(--space-2); align-items: flex-start; padding: .75rem 1rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem; background: var(--color-surface-secondary, #F7F5F1); color: var(--color-text-secondary, #676664); font-size: .8125rem; line-height: 1.5; }
    .rv-note .ds-icon { flex: none; margin-top: .15rem; }
    .rv-note strong { color: var(--color-text-primary, #262522); }

    .rv-empty { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); border: 1px dashed var(--color-border-strong); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .rv-empty-quiet { margin: var(--space-4); border-style: solid; border-color: var(--color-border-neutral); }
    .rv-empty-icon { display: inline-flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; flex-shrink: 0; border-radius: var(--radius-full); background: var(--color-accent-soft-bg); color: var(--color-accent-primary); }

    /* ---- request ledger ---- */
    .rv-ledger { overflow: hidden; border-radius: .75rem; }
    .rv-ledger-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2) var(--space-3); padding: 1rem 1.25rem; }
    .rv-pills { display: inline-flex; gap: .125rem; padding: .1875rem; border-radius: .625rem; background: var(--color-border-subtle, #F2F0ED); }
    .rv-pill { padding: .3125rem .875rem; border: 0; border-radius: .5rem; background: transparent; font-size: .8125rem; color: var(--color-text-secondary, #676664); }
    .rv-pill:hover { color: var(--color-text-primary, #262522); }
    .rv-pill.is-active { background: var(--color-surface, #fff); color: var(--color-text-primary, #262522); font-weight: 600; box-shadow: 0 1px 2px var(--color-shadow-tint, rgba(38, 37, 34, .08)); }

    .rv-request { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; column-gap: .875rem; row-gap: .75rem; align-items: start; padding: .875rem 1.25rem 1rem; border-top: 1px solid var(--color-border-neutral, #E5E1DA); }
    .rv-request.is-awaiting { background: color-mix(in srgb, var(--color-status-pending, #B58B46) 7%, var(--color-surface, #fff)); }
    .rv-request[hidden] { display: none; }
    .rv-avatar { display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: var(--radius-full, 999px); background: var(--color-primary-soft-bg, #F4E5E4); color: var(--color-link-hover, #A83E38); font-size: .75rem; font-weight: 600; }
    .rv-request.is-awaiting .rv-avatar:has(.ds-icon) { background: var(--color-border-subtle, #F2F0ED); color: var(--color-text-secondary, #676664); }
    .rv-request-main { min-width: 0; }
    .rv-request-who { font-size: .875rem; color: var(--color-text-primary, #262522); overflow-wrap: anywhere; }
    .rv-request-meta { font-size: .75rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
    .rv-request-links { display: flex; flex-wrap: wrap; gap: .125rem .875rem; margin-top: .25rem; font-size: .75rem; }
    .rv-request-links a { color: var(--color-link-hover, #A83E38); font-weight: 500; }
    .rv-request-links:empty { display: none; }
    .rv-request-state { display: flex; flex-direction: column; align-items: flex-end; gap: .25rem; text-align: right; }
    .rv-request-state .badge { display: inline-flex; align-items: center; gap: .25rem; white-space: nowrap; }
    .rv-request-note { font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .rv-request-actions { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: .5rem; }
    .rv-request-actions form { margin: 0; }
    .rv-more > summary { cursor: pointer; padding: var(--space-3) 1.25rem; color: var(--color-accent-primary); border-top: 1px solid var(--color-border-neutral); }
    .rv-filter-empty { padding: var(--space-4); margin: 0; text-align: center; }
    .rv-trunc { margin: 0; padding: var(--space-2) 1.25rem var(--space-3); border-top: 1px solid var(--color-border-neutral); }

    @media (max-width: 575.98px) {
        .rv-stat { padding: .75rem .875rem; }
        .rv-stat-value { font-size: 1.5rem; }
        .rv-stat-value-text { font-size: 1.125rem; }
        .rv-header-actions, .rv-header-actions .btn { width: 100%; justify-content: center; }
        .rv-ledger-head { padding-inline: var(--space-3); }
        .rv-request { grid-template-columns: auto minmax(0, 1fr); padding-inline: var(--space-3); }
        .rv-request-state { grid-column: 2; flex-direction: row; flex-wrap: wrap; align-items: center; justify-content: flex-start; text-align: left; gap: .5rem; }
        .rv-request-actions .btn { flex: 1 1 auto; justify-content: center; }
        .rv-empty { flex-direction: column; text-align: center; }
    }
</style>
