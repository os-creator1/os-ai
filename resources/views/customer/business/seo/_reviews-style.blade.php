{{--
    Styles for SEO > Reviews. Same visual language as the Citations and
    Keywords passes (stat tiles, pill filters, calm cards): design tokens only,
    no new colours. Class prefix `rv-` is local to this page.
--}}
<style>
    .rv-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }
    .rv-header-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }

    /* ---- stat tiles: the number is the loudest thing on the page ---- */
    .rv-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .875rem; }
    .rv-stat { padding: 1rem 1.125rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .625rem; background: var(--color-surface, #fff); min-width: 0; }
    .rv-stat-label { display: flex; align-items: center; gap: .4rem; margin: 0; font-size: .75rem; font-weight: 500; color: var(--color-text-muted, #6F6D67); }
    .rv-stat-value { margin: .375rem 0 .125rem; font-size: 2.25rem; line-height: 1.1; font-weight: 700; color: var(--color-text-primary, #1F1E1B); }
    .rv-stat-value small { font-size: 1rem; font-weight: 500; color: var(--color-text-muted, #6F6D67); }
    .rv-stat-value-text { font-size: 1.75rem; line-height: 1.2; padding-block: .3rem .15rem; }
    .rv-stat-sub { margin: 0; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
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
    .rv-location-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-2); margin-bottom: var(--space-3); }
    .rv-location-title { display: flex; align-items: center; gap: var(--space-2); min-width: 0; }
    .rv-location-icon { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; flex-shrink: 0; border-radius: var(--radius-full); background: var(--color-accent-soft-bg); color: var(--color-accent-primary); }
    .rv-location-badges { display: flex; flex-wrap: wrap; gap: var(--space-1); }
    .rv-location-badges .badge { display: inline-flex; align-items: center; gap: var(--space-1); }
    .rv-section-label { font-weight: 600; color: var(--color-text-primary); overflow-wrap: anywhere; }
    .rv-link-row { display: grid; gap: var(--space-2); }
    .rv-link-field { min-width: 0; font-family: var(--bs-font-monospace); font-size: .8125rem; background: var(--color-surface-secondary); text-overflow: ellipsis; }
    .rv-link-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
    .rv-link-actions .btn { flex: 1 1 auto; justify-content: center; }
    .rv-panel { margin-top: var(--space-2); }
    .rv-panel > summary { list-style: none; cursor: pointer; }
    .rv-panel > summary::-webkit-details-marker { display: none; }
    .rv-panel-body { margin-top: var(--space-2); padding: var(--space-3); border: 1px solid var(--color-border-neutral); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .rv-note { display: flex; gap: var(--space-2); align-items: flex-start; padding: .625rem .875rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .5rem; background: var(--color-surface-secondary, #F7F5F1); color: var(--color-text-muted, #6F6D67); font-size: .8125rem; }
    .rv-note .ds-icon { flex: none; margin-top: .1rem; }

    .rv-empty { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); border: 1px dashed var(--color-border-strong); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .rv-empty-quiet { margin: var(--space-4); border-style: solid; border-color: var(--color-border-neutral); }
    .rv-empty-icon { display: inline-flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; flex-shrink: 0; border-radius: var(--radius-full); background: var(--color-accent-soft-bg); color: var(--color-accent-primary); }

    /* ---- request ledger ---- */
    .rv-ledger { overflow: hidden; }
    .rv-ledger-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2) var(--space-3); padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border-neutral); }
    .rv-pills { display: flex; flex-wrap: wrap; gap: .375rem; }
    .rv-pill { padding: .25rem .75rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: 999px; background: var(--color-surface, #fff); font-size: .8125rem; color: var(--color-text-secondary, #4A4944); }
    .rv-pill span { margin-left: .25rem; color: var(--color-text-muted, #6F6D67); }
    .rv-pill:hover { border-color: var(--color-primary-border, #C9D0F2); }
    .rv-pill.is-active { background: var(--color-primary-soft-bg, #EEF1FB); border-color: var(--color-primary, #4B5FD6); color: var(--color-primary, #4B5FD6); font-weight: 600; }
    .rv-pill.is-active span { color: inherit; }

    .rv-request { display: flex; gap: var(--space-3); padding: var(--space-3) var(--space-4); border-top: 1px solid var(--color-border-neutral); }
    .rv-rows > .rv-request:first-child { border-top: 0; }
    .rv-request[hidden] { display: none; }
    .rv-request-icon { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; flex-shrink: 0; border-radius: var(--radius-full); background: var(--color-surface-secondary); color: var(--color-text-muted); }
    .rv-request-main { flex: 1; min-width: 0; }
    .rv-request-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); }
    .rv-request-who { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .5rem; min-width: 0; overflow-wrap: anywhere; }
    .rv-request-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2) var(--space-3); margin-top: var(--space-2); }
    .rv-request-foot:empty { display: none; }
    .rv-request-actions { display: flex; flex-wrap: wrap; gap: var(--space-1) var(--space-2); }
    .rv-request-links { display: flex; flex-wrap: wrap; gap: var(--space-1) var(--space-3); }
    .rv-request-links:empty { display: none; }
    .rv-more > summary { cursor: pointer; padding: var(--space-3) var(--space-4); color: var(--color-accent-primary); border-top: 1px solid var(--color-border-neutral); }
    .rv-filter-empty { padding: var(--space-4); margin: 0; text-align: center; }
    .rv-trunc { margin: 0; padding: var(--space-2) var(--space-4) var(--space-3); border-top: 1px solid var(--color-border-neutral); }

    @media (max-width: 575.98px) {
        .rv-stat { padding: .875rem; }
        .rv-stat-value { font-size: 1.875rem; }
        .rv-stat-value-text { font-size: 1.375rem; }
        .rv-header-actions, .rv-header-actions .btn { width: 100%; justify-content: center; }
        .rv-request { padding-inline: var(--space-3); }
        .rv-ledger-head { padding-inline: var(--space-3); }
        .rv-empty { flex-direction: column; text-align: center; }
    }
</style>
