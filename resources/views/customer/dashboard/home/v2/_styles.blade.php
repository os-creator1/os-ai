{{--
    Home V2 (final) — scoped styles. Everything is under `.hv2` so nothing can reach another page; every colour is a runtime design
    token (`var(--color-*)`) with the brand fallback, so a per-tenant theme still applies.
--}}
<style>
    .hv2 { --hv2-border: var(--color-border-subtle, #EEEAE3); --hv2-text: var(--color-text-primary, #1F1D1A); --hv2-muted: var(--color-text-muted, #6F6D67); --hv2-brand: var(--color-primary, #B5472D); --hv2-soft: var(--color-primary-soft-bg, #F7E9E4); --hv2-card: var(--color-surface, #fff); }
    .hv2 .hv2-layout { display: grid; grid-template-columns: minmax(0, 1fr) 20rem; gap: 0 1.75rem; align-items: start; }
    .hv2 .hv2-layout--setup { grid-template-columns: minmax(0, 1fr) 21rem; }
    .hv2 .hv2-main, .hv2 .hv2-side { min-width: 0; }
    .hv2 .hv2-greeting { margin-bottom: 1rem; }
    .hv2 .hv2-greeting__title { font-size: 1.625rem; font-weight: 700; line-height: 1.2; margin: 0 0 .125rem; color: var(--hv2-text); }
    .hv2 .hv2-greeting__sub { margin: 0; color: var(--hv2-muted); font-size: .9375rem; }
    .hv2 .hv2-section { margin-bottom: 1.25rem; }
    .hv2 .hv2-label { display: flex; align-items: center; gap: .5rem; margin: 0 0 .5rem; font-size: .75rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--hv2-muted); }
    .hv2 .hv2-num { display: inline-flex; width: 1.125rem; height: 1.125rem; align-items: center; justify-content: center; border-radius: 50%; background: var(--hv2-soft); color: var(--hv2-brand); font-size: .6875rem; }
    .hv2 .hv2-card { background: var(--hv2-card); border: 1px solid var(--hv2-border); border-radius: .875rem; padding: 1rem 1.125rem; }
    .hv2 .hv2-muted { color: var(--hv2-muted); font-size: .8125rem; }
    .hv2 .hv2-btn { white-space: nowrap; }
    .hv2 .hv2-spark { color: var(--hv2-brand); }

    /* Needs you */
    .hv2 .hv2-needs { display: flex; align-items: center; gap: 1rem; border-left: 3px solid var(--hv2-brand); padding: .875rem 1rem; }
    .hv2 .hv2-tile { flex: 0 0 3.25rem; height: 3.25rem; display: inline-flex; align-items: center; justify-content: center; border-radius: .75rem; background: var(--hv2-soft); color: var(--hv2-brand); font-size: 1.5rem; font-weight: 700; }
    .hv2 .hv2-needs__text { flex: 1 1 auto; min-width: 0; }
    .hv2 .hv2-needs__title { margin: 0 0 .125rem; font-size: 1.125rem; font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
    .hv2 .hv2-needs__actions { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: flex-end; }
    .hv2 .hv2-quiet { display: flex; align-items: center; gap: .625rem; padding: .625rem .875rem; }
    .hv2 .hv2-check { display: inline-flex; width: 1.5rem; height: 1.5rem; align-items: center; justify-content: center; border-radius: 50%; background: var(--color-status-success-soft-bg, #E6F3EA); color: var(--color-status-success, #1E7B3C); font-size: .8125rem; font-weight: 700; flex: 0 0 auto; }
    .hv2 .hv2-check--warn { background: var(--color-status-warning-soft-bg, #FDF1D8); color: var(--color-status-warning, #8A5A00); }
    .hv2 .hv2-details { margin: .375rem .25rem 0; font-size: .8125rem; }
    .hv2 .hv2-details summary { cursor: pointer; color: var(--hv2-muted); }
    .hv2 .hv2-list { margin: .5rem 0 0; padding-left: 1.125rem; }
    .hv2 .hv2-list li { margin-bottom: .25rem; }

    /* Ask (the existing card, compact) */
    .hv2 .home-ask { margin-bottom: 1.25rem !important; }
    .hv2 .home-ask-card .card-body { padding: .5rem .75rem; }
    .hv2 .home-ask-form { align-items: center; gap: .625rem; }
    .hv2 .home-ask-input { padding: .375rem 0; }
    .hv2 .hv2-ask-chips { display: flex; gap: .375rem; flex: 0 1 auto; }
    .hv2 .hv2-ask-chips .home-chip { white-space: nowrap; max-width: 17rem; overflow: hidden; text-overflow: ellipsis; }
    .hv2 .home-ask-submit { width: 2.25rem; height: 2.25rem; padding: 0; border-radius: .5rem; }
    .hv2 .home-ask:not(.is-conversation-open) .home-ask-submit-label { display: none; }
    .hv2 .home-ask:not(.is-conversation-open) .home-ask-submit-icon { display: inline-block; width: .5rem; height: .5rem; border-top: 2px solid #fff; border-right: 2px solid #fff; transform: rotate(45deg); }

    /* In the narrow side column (new-business Home) the input needs the room: its prompt wraps instead of scrolling. */
    .hv2 .hv2-side .home-ask-form { flex-wrap: wrap; }
    .hv2 .hv2-side .home-ask-input { flex: 1 1 10rem; min-width: 0; min-height: 2.75rem; overflow: hidden; }

    /* Growth */
    .hv2 .hv2-growth__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; margin-bottom: .5rem; }
    .hv2 .hv2-growth__head .hv2-label { margin: 0; }
    .hv2 .hv2-range .home-segmented { background: var(--color-surface-secondary, #F4F1EC); }
    .hv2 .hv2-kpis { display: grid; grid-template-columns: repeat(var(--hv2-kpis, 3), minmax(0, 1fr)); gap: .75rem; }
    .hv2 .hv2-kpi { position: relative; display: grid; grid-template-columns: 1fr auto; grid-template-areas: "label label" "value badge" "note note"; align-items: end; gap: .125rem .5rem; padding: .75rem .875rem; background: var(--hv2-card); border: 1px solid var(--hv2-border); border-radius: .75rem; color: var(--hv2-text); text-decoration: none; transition: border-color .15s, box-shadow .15s; }
    .hv2 a.hv2-kpi:hover, .hv2 a.hv2-kpi:focus-visible { border-color: var(--hv2-brand); box-shadow: 0 1px 6px rgba(0, 0, 0, .06); outline: none; }
    .hv2 a.hv2-kpi:focus-visible { box-shadow: 0 0 0 2px var(--hv2-soft), 0 0 0 4px var(--hv2-brand); }
    .hv2 .hv2-kpi__label { grid-area: label; display: flex; justify-content: space-between; font-size: .8125rem; color: var(--hv2-muted); }
    .hv2 .hv2-kpi__arrow { opacity: .55; }
    .hv2 a.hv2-kpi:hover .hv2-kpi__arrow { opacity: 1; color: var(--hv2-brand); }
    .hv2 .hv2-kpi__value { grid-area: value; font-size: 1.875rem; font-weight: 700; line-height: 1.1; }
    .hv2 .hv2-kpi__note { grid-area: note; font-size: .75rem; color: var(--hv2-muted); }
    .hv2 .hv2-badge { grid-area: badge; justify-self: end; padding: .125rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; background: var(--color-surface-secondary, #EEEAE3); color: var(--hv2-muted); white-space: nowrap; }
    .hv2 .hv2-badge--up { background: var(--color-status-success-soft-bg, #E6F3EA); color: var(--color-status-success, #1E7B3C); }
    .hv2 .hv2-badge--down { background: var(--color-status-warning-soft-bg, #FDF1D8); color: var(--color-status-warning, #8A5A00); }
    .hv2 .hv2-revenue { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-top: .75rem; padding: .5rem .875rem; border: 1px dashed var(--color-border, #D9D3C8); border-radius: .75rem; background: var(--color-surface-secondary, #FAF8F5); font-size: .875rem; }
    .hv2 .hv2-revenue a, .hv2 .hv2-side-card a, .hv2 .hv2-chart__foot a { color: var(--hv2-brand); font-weight: 600; text-decoration: none; }
    .hv2 .hv2-chart { margin-top: .75rem; padding: .75rem .875rem; background: var(--hv2-card); border: 1px solid var(--hv2-border); border-radius: .75rem; }
    .hv2 .hv2-chart__plot { display: flex; align-items: flex-end; gap: 3px; height: 4.5rem; margin: .5rem 0 .25rem; border-bottom: 1px solid var(--hv2-border); }
    .hv2 .hv2-chart__bar { flex: 1 1 0; min-width: 2px; border-radius: 3px 3px 0 0; background: var(--hv2-brand); }
    .hv2 .hv2-chart__bar.is-zero { background: var(--color-border, #E5E1DA); height: 2px !important; }
    .hv2 .hv2-chart__axis { display: flex; justify-content: space-between; font-size: .6875rem; color: var(--hv2-muted); }
    .hv2 .hv2-chart__empty { padding: .5rem 0; font-size: .875rem; color: var(--hv2-muted); }
    .hv2 .hv2-chart__foot { margin-top: .5rem; font-size: .8125rem; }

    /* Right column */
    .hv2 .hv2-side-card { padding: .75rem .875rem; }
    .hv2 .hv2-side-card__head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: .5rem; font-size: .875rem; }
    .hv2 .hv2-done { list-style: none; margin: 0; padding: 0; }
    .hv2 .hv2-done li { display: flex; gap: .5rem; padding: .5rem 0; border-top: 1px solid var(--hv2-border); font-size: .875rem; }
    .hv2 .hv2-done__mark { color: var(--color-status-success, #1E7B3C); }
    .hv2 .hv2-done time { display: block; }
    .hv2 .hv2-suggest { list-style: none; margin: 0; background: var(--color-surface-secondary, #FAF8F5); }
    .hv2 .hv2-suggest li { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .5rem 0; font-size: .875rem; }
    .hv2 .hv2-suggest li + li { border-top: 1px solid var(--hv2-border); }
    .hv2 .hv2-more { margin: 1rem 0; }
    .hv2 .hv2-more summary { cursor: pointer; color: var(--hv2-muted); font-size: .875rem; }

    /* New business */
    .hv2 .hv2-setup { padding: 0; overflow: hidden; }
    .hv2 .hv2-setup__head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .875rem 1.125rem; }
    .hv2 .hv2-setup__title { margin: 0; font-size: 1rem; font-weight: 700; }
    .hv2 .hv2-progress { display: flex; align-items: center; gap: .625rem; font-size: .8125rem; color: var(--hv2-muted); }
    .hv2 .hv2-progress__bar { width: 5.5rem; height: .3125rem; border-radius: 999px; background: var(--hv2-border); overflow: hidden; }
    .hv2 .hv2-progress__bar span { display: block; height: 100%; background: var(--color-status-success, #1E7B3C); }
    .hv2 .hv2-steps { list-style: none; margin: 0; padding: 0; }
    .hv2 .hv2-step { display: flex; align-items: center; gap: .75rem; padding: .75rem 1.125rem; border-top: 1px solid var(--hv2-border); }
    .hv2 .hv2-step.is-next { background: #FDF7F5; box-shadow: inset 3px 0 0 var(--hv2-brand); }
    .hv2 .hv2-step__mark { flex: 0 0 1.5rem; height: 1.5rem; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; border: 1px solid var(--color-border, #D9D3C8); font-size: .75rem; color: var(--hv2-muted); }
    .hv2 .hv2-step.is-next .hv2-step__mark { background: var(--hv2-brand); border-color: var(--hv2-brand); color: #fff; }
    .hv2 .hv2-step.is-done .hv2-step__mark { background: var(--color-status-success-soft-bg, #E6F3EA); border-color: var(--color-status-success, #1E7B3C); color: var(--color-status-success, #1E7B3C); }
    .hv2 .hv2-step.is-done .hv2-step__text strong { text-decoration: line-through; color: var(--hv2-muted); font-weight: 500; }
    .hv2 .hv2-step__text { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
    .hv2 .hv2-step__text small { color: var(--hv2-muted); }
    .hv2 .hv2-results-note { margin-top: 1rem; background: var(--color-surface-secondary, #FAF8F5); font-size: .875rem; }

    .hv2 .hv2-sev { display: inline-block; margin: .125rem 0 .25rem; padding: .0625rem .5rem; border-radius: 999px; font-size: .6875rem; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; background: var(--color-status-warning-soft-bg, #FDF1D8); color: var(--color-status-warning, #8A5A00); }

    @media (max-width: 1199.98px) {
        .hv2 .hv2-layout, .hv2 .hv2-layout--setup { grid-template-columns: minmax(0, 1fr); }
    }
    @media (max-width: 767.98px) {
        .hv2 .hv2-greeting__title { font-size: 1.375rem; }
        .hv2 .hv2-needs { flex-wrap: wrap; }
        .hv2 .hv2-needs__actions { width: 100%; justify-content: stretch; }
        .hv2 .hv2-needs__actions .btn { flex: 1 1 100%; min-height: 2.75rem; }
        .hv2 .hv2-kpis { grid-template-columns: minmax(0, 1fr); }
        .hv2 .hv2-kpi { min-height: 3.5rem; }
        /* The suggestions stay (one or two): they wrap onto their own row under the input instead of vanishing. */
        .hv2 .home-ask-form { flex-wrap: wrap; }
        .hv2 .home-ask-input { flex: 1 1 8rem; min-width: 0; min-height: 2.75rem; }
        .hv2 .hv2-ask-chips { order: 5; flex: 1 1 100%; flex-wrap: wrap; }
        .hv2 .hv2-ask-chips .home-chip { white-space: normal; max-width: 100%; text-align: left; min-height: 2.25rem; }
        .hv2 .hv2-step { flex-wrap: wrap; }
        .hv2 .hv2-step .btn { min-height: 2.5rem; }
    }
    @media (prefers-reduced-motion: reduce) { .hv2 .hv2-kpi { transition: none; } }
</style>
