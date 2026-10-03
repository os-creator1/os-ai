{{-- Styles for SEO > Reviews. Design tokens only; no new colours. --}}
<style>
    .rv-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }
    .rv-header-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
    .rv-header-actions .badge { display: inline-flex; align-items: center; gap: var(--space-1); padding: var(--space-2) var(--space-3); }

    .rv-note { display: flex; gap: var(--space-3); align-items: flex-start; padding: var(--space-3) var(--space-4); border: 1px solid var(--color-accent-border); border-radius: var(--radius-lg); background: var(--color-accent-soft-bg); color: var(--color-text-secondary); }
    .rv-note .ds-icon { color: var(--color-accent-primary); margin-top: 2px; }

    .rv-stat-label { display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-2); font-size: .8125rem; font-weight: 600; color: var(--color-text-muted); }
    .rv-stat-value { margin-bottom: var(--space-1); font-size: 1.75rem; line-height: 1.15; font-weight: 700; color: var(--color-text-primary); }
    .rv-stat-value-text { font-size: 1.25rem; padding-block: .3rem; }
    .rv-meter { height: 6px; border-radius: var(--radius-full); background: var(--color-surface-secondary); overflow: hidden; margin-top: var(--space-2); }
    .rv-meter span { display: block; height: 100%; border-radius: inherit; background: var(--color-accent-primary); }

    .rv-location { overflow: hidden; }
    .rv-location:target { border-color: var(--color-accent-border); box-shadow: var(--shadow-md); }
    .rv-location-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--color-border-neutral); background: var(--color-surface-secondary); }
    .rv-location-title { display: flex; align-items: center; gap: var(--space-3); min-width: 0; }
    .rv-location-icon { display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; flex-shrink: 0; border-radius: var(--radius-full); background: var(--color-accent-soft-bg); color: var(--color-accent-primary); }
    .rv-location-badges { display: flex; flex-wrap: wrap; gap: var(--space-2); }
    .rv-location-badges .badge { display: inline-flex; align-items: center; gap: var(--space-1); }

    .rv-section { padding: var(--space-4) var(--space-5); }
    .rv-section + .rv-section { border-top: 1px solid var(--color-border-neutral); }
    .rv-section-label { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--space-2); margin-bottom: var(--space-2); font-weight: 600; color: var(--color-text-primary); }
    .rv-section-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-3); }

    .rv-link-row { display: flex; flex-wrap: wrap; gap: var(--space-3); }
    .rv-link-field { flex: 1 1 18rem; min-width: 0; font-family: var(--bs-font-monospace); font-size: .8125rem; background: var(--color-surface-secondary); text-overflow: ellipsis; }
    .rv-link-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }

    .rv-empty { display: flex; align-items: center; gap: var(--space-4); padding: var(--space-4); border: 1px dashed var(--color-border-strong); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .rv-empty-quiet { border-style: solid; border-color: var(--color-border-neutral); }
    .rv-empty-icon { display: inline-flex; align-items: center; justify-content: center; width: 3rem; height: 3rem; flex-shrink: 0; border-radius: var(--radius-full); background: var(--color-accent-soft-bg); color: var(--color-accent-primary); }

    .rv-panel { margin-top: var(--space-3); }
    .rv-panel-inline { margin-top: 0; }
    .rv-panel > summary { list-style: none; cursor: pointer; }
    .rv-panel > summary::-webkit-details-marker { display: none; }
    .rv-panel[open] > summary { background: var(--color-surface-secondary); }
    .rv-panel-body { margin-top: var(--space-3); padding: var(--space-4); border: 1px solid var(--color-border-neutral); border-radius: var(--radius-lg); background: var(--color-surface-primary); box-shadow: var(--shadow-sm); max-width: 34rem; }
    .rv-field { display: flex; flex-direction: column; gap: var(--space-1); margin-bottom: var(--space-3); }

    .rv-request { display: flex; gap: var(--space-3); padding: var(--space-3) 0; border-top: 1px solid var(--color-border-neutral); }
    .rv-request:first-of-type { border-top: 0; padding-top: 0; }
    .rv-request-icon { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; flex-shrink: 0; border-radius: var(--radius-full); background: var(--color-surface-secondary); color: var(--color-text-muted); }
    .rv-request-main { flex: 1; min-width: 0; }
    .rv-request-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); }
    .rv-request-links { display: flex; flex-wrap: wrap; gap: var(--space-3); margin-top: var(--space-1); }
    .rv-request-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-top: var(--space-2); }
    .rv-more > summary { cursor: pointer; padding-block: var(--space-2); color: var(--color-accent-primary); }

    @media (max-width: 575.98px) {
        .rv-location-head, .rv-section { padding-inline: var(--space-4); }
        .rv-empty { flex-direction: column; text-align: center; }
        .rv-link-actions .btn { flex: 1 1 auto; justify-content: center; }
    }
</style>
