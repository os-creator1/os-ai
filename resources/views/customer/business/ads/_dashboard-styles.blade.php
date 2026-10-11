{{--
    Ads dashboard styles shared by the Google Ads and Meta Ads pages: the page
    header, decision panel, period bar, KPI strip, trend card, attention and
    pacing panels. Theme tokens only, so the active theme still applies.
--}}
@once
<style>
    .ads-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .75rem 1rem; margin-bottom: .75rem; }
    .ads-head-main { min-width: 0; flex: 1 1 18rem; }
    .ads-head-meta { display: flex; flex-direction: column; align-items: flex-end; gap: .3125rem; max-width: 100%; }
    .ads-account-chip {
        display: inline-flex; align-items: center; gap: .5rem; max-width: 100%;
        padding: .3125rem .4375rem .3125rem .75rem;
        font-size: .75rem; color: var(--color-text-primary);
        background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-full);
    }
    .ads-account-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ads-freshness-line p { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .25rem; font-size: .71875rem; }
    .ads-freshness-line p::before { content: ""; width: .4375rem; height: .4375rem; border-radius: var(--radius-full); background: var(--color-status-success); }
    .ads-freshness-line[data-state="never_synced"] p::before,
    .ads-freshness-line[data-state="running"] p::before,
    .ads-freshness-line[data-state="stale"] p::before,
    .ads-freshness-line[data-state="failed_with_data"] p::before { background: var(--color-status-warning); }
    .ads-provider-nav { display: flex; gap: .375rem; overflow-x: auto; padding-bottom: .125rem; }
    @media (max-width: 575.98px) {
        .ads-head-meta { align-items: flex-start; }
        .ads-freshness-line p { justify-content: flex-start; }
    }

    /* Decision panel ----------------------------------------------------- */
    .decision-panel {
        position: relative; margin-bottom: 1rem; padding: 1.125rem 1.25rem 1.125rem 1.5rem;
        background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); overflow: hidden;
    }
    .decision-panel::before { content: ""; position: absolute; inset: 0 auto 0 0; width: .25rem; background: var(--decision-accent, var(--color-border-strong, var(--color-border))); }
    .decision-panel.tone-danger { --decision-accent: var(--color-status-danger); }
    .decision-panel.tone-warning { --decision-accent: var(--color-status-warning); }
    .decision-panel.tone-success { --decision-accent: var(--color-status-success); }
    .decision-panel.tone-neutral { --decision-accent: var(--color-text-muted); }
    .decision-kicker { margin: 0 0 .5rem; font-size: .6875rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--color-text-muted); }
    .decision-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem 1.5rem; }
    @media (min-width: 992px) { .decision-grid { grid-template-columns: minmax(0, 1.6fr) minmax(15rem, 1fr); } }
    .decision-state { font-weight: 700; letter-spacing: .04em; }
    .decision-headline { margin: .5rem 0 .5rem; font-size: 1.1875rem; line-height: 1.35; font-weight: 700; color: var(--color-text-primary); }
    .decision-reasons p { margin: 0 0 .375rem; font-size: .84375rem; color: var(--color-text-secondary, var(--color-text-primary)); }
    .decision-cta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; margin-top: .875rem; }
    .decision-side { padding: .875rem 1rem; border-radius: var(--radius-md); background: var(--color-surface-secondary); font-size: .78125rem; }
    .decision-side dl { margin: 0; }
    .decision-side dt { font-size: .625rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted); }
    .decision-side dd { margin: .125rem 0 .625rem; color: var(--color-text-primary); }
    .decision-side dd:last-child { margin-bottom: 0; }
    .decision-evidence { list-style: none; margin: 0 0 .625rem; padding: 0; }
    .decision-evidence li { display: flex; justify-content: space-between; gap: .75rem; padding: .25rem 0; border-bottom: 1px dashed var(--color-border); }
    .decision-evidence li span:last-child { font-weight: 600; text-align: right; }
    .decision-panel details { margin-top: .75rem; font-size: .78125rem; }
    .decision-panel details summary { cursor: pointer; font-weight: 600; color: var(--color-text-secondary, var(--color-text-primary)); }
    .decision-note { margin: .375rem 0 0; font-size: .75rem; color: var(--color-text-muted); }
    .decision-other { margin-top: .875rem; padding-top: .75rem; border-top: 1px solid var(--color-border-subtle, var(--color-border)); font-size: .8125rem; }
    .decision-others-title { margin: .875rem 0 0; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted); }
    .outcome-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 9.5rem), 1fr)); gap: .75rem; margin-bottom: .5rem; }
    .outcome-kpi { padding: .75rem .875rem; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
    .outcome-kpi .label { margin: 0 0 .25rem; font-size: .71875rem; color: var(--color-text-muted); }
    .outcome-kpi .value { margin: 0; font-size: 1.125rem; font-weight: 700; color: var(--color-text-primary); }
    .outcome-kpi .note { margin: .125rem 0 0; font-size: .6875rem; color: var(--color-text-muted); }

    /* Period bar ---------------------------------------------------------- */
    .ads-period { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; margin-bottom: 1rem; }
    .ads-period-range { display: inline-flex; align-items: center; gap: .375rem; font-size: .75rem; color: var(--color-text-secondary, var(--color-text-muted)); }

    /* KPI strip ------------------------------------------------------------ */
    .ads-kpis { margin-bottom: 1rem; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); overflow: hidden; }
    .ads-kpi-main { display: grid; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 768px) { .ads-kpi-main { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .ads-kpi { padding: 1rem 1.125rem; border-right: 1px solid var(--color-border-subtle, var(--color-border)); border-bottom: 1px solid var(--color-border-subtle, var(--color-border)); min-width: 0; }
    .ads-kpi-label { margin: 0 0 .375rem; display: flex; align-items: center; gap: .3125rem; font-size: .75rem; color: var(--color-text-secondary, var(--color-text-muted)); }
    .ads-kpi-value { display: block; margin: 0; font-size: 1.625rem; line-height: 1.15; font-weight: 700; color: var(--color-text-primary); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .ads-kpi-note { margin: .375rem 0 0; font-size: .71875rem; color: var(--color-text-muted); }
    .ads-change { display: inline-flex; align-items: center; gap: .1875rem; font-weight: 600; }
    .ads-change.is-up { color: var(--color-status-success-text); }
    .ads-change.is-down { color: var(--color-status-danger-text); }
    .ads-change.is-flat { color: var(--color-text-muted); }
    .ads-kpi-secondary { display: grid; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 576px) { .ads-kpi-secondary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (min-width: 992px) { .ads-kpi-secondary { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .ads-kpi-mini { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .25rem .75rem; padding: .625rem 1.125rem; font-size: .75rem; color: var(--color-text-secondary, var(--color-text-muted)); border-right: 1px solid var(--color-border-subtle, var(--color-border)); min-width: 0; }
    .ads-kpi-mini strong, .ads-kpi-mini [data-role$="-value"] { font-size: .8125rem; font-weight: 700; color: var(--color-text-primary); font-variant-numeric: tabular-nums; }
    .ads-kpi-mini .ads-kpi-note { flex-basis: 100%; margin: 0; }

    /* Trend + side panels -------------------------------------------------- */
    .ads-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; align-items: start; margin-bottom: 1rem; }
    @media (min-width: 1200px) { .ads-grid { grid-template-columns: minmax(0, 1fr) minmax(16rem, 20rem); } }
    .ads-grid > * { min-width: 0; }
    .ads-grid .cx-card { margin-bottom: 0; }
    .ads-side { display: grid; gap: 1rem; }
    @media (min-width: 768px) and (max-width: 1199.98px) { .ads-side { grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; } }
    .ads-chart-caption { margin: .25rem 0 0; font-size: .6875rem; font-weight: 600; color: var(--color-text-muted); }
    .ads-chart { min-height: 160px; width: 100%; }
    .ads-trend-details summary { display: inline-flex; align-items: center; gap: .25rem; cursor: pointer; font-size: .75rem; font-weight: 600; color: var(--color-primary); }
    .ads-trend-details .table-responsive { max-height: 22rem; overflow: auto; }

    .ads-attention { list-style: none; margin: 0; padding: 0; }
    .ads-attention li { padding: .625rem 0; border-top: 1px solid var(--color-border-subtle, var(--color-border)); font-size: .78125rem; }
    .ads-attention li:first-child { border-top: 0; padding-top: 0; }
    .ads-attention strong { display: block; font-size: .8125rem; color: var(--color-text-primary); }
    .ads-attention .text-caption { display: block; }

    .ads-pacing-figure { margin: 0; font-size: 1.375rem; font-weight: 700; color: var(--color-text-primary); font-variant-numeric: tabular-nums; }
    .ads-meter { position: relative; height: .5rem; margin: .625rem 0 .375rem; border-radius: var(--radius-full); background: var(--color-surface-secondary); overflow: hidden; }
    .ads-meter > span { position: absolute; inset: 0 auto 0 0; border-radius: var(--radius-full); background: var(--color-primary); }
    .ads-meter > i { position: absolute; top: -.125rem; bottom: -.125rem; width: 2px; background: var(--color-text-primary); opacity: .55; }
    .ads-meter-legend { display: flex; justify-content: space-between; gap: .5rem; font-size: .6875rem; color: var(--color-text-muted); }
    .ads-pacing-box { margin: .75rem 0; padding: .625rem .75rem; font-size: .75rem; border: 1px solid var(--color-border); border-radius: var(--radius-md); color: var(--color-text-secondary, var(--color-text-primary)); }
</style>
@endonce
