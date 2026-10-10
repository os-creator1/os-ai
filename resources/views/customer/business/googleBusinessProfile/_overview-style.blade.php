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
</style>
