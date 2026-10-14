{{--
    Pre-generation Review page — layout and components. Everything is scoped under `.rv` and uses the application's own
    design tokens (with the MotionGrove fallbacks), so it follows the active theme.
--}}
<style>
    .rv { --rv-gap: 1rem; color: var(--color-text-primary, #262522); }
    .rv-layout { display: grid; grid-template-columns: minmax(0, 1fr) 17.5rem; gap: 1.25rem; align-items: start; }
    .rv-main { min-width: 0; display: flex; flex-direction: column; gap: var(--rv-gap); }
    .rv-aside { display: flex; flex-direction: column; gap: var(--rv-gap); position: sticky; top: 5.5rem; max-height: calc(100vh - 6.5rem); overflow-y: auto; padding: 2px; }

    .rv-steps { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; margin: 0 0 1rem; padding: 0; list-style: none; font-size: .8125rem; color: var(--color-text-muted, #6E6B63); }
    .rv-steps li { display: inline-flex; align-items: center; gap: .4rem; }
    .rv-step-dot { display: inline-flex; align-items: center; justify-content: center; width: 1.25rem; height: 1.25rem; border-radius: 50%; font-size: .7rem; font-weight: 600; background: var(--color-surface-secondary, #F3F1EC); color: var(--color-text-muted, #6E6B63); border: 1px solid var(--color-border, #E5E1DA); }
    .rv-steps .is-done .rv-step-dot { background: var(--color-status-success-soft-bg, #E6F4EA); color: var(--color-status-success-text, #1E7B3A); border-color: var(--color-status-success-border, #B7DFC3); }
    .rv-steps .is-current { color: var(--color-text-primary, #262522); font-weight: 600; }
    .rv-steps .is-current .rv-step-dot { background: var(--color-primary, #B5524C); color: #fff; border-color: var(--color-primary, #B5524C); }

    .rv-card { background: var(--color-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem; scroll-margin-top: 5.5rem; }
    .rv-card-head { display: flex; align-items: flex-start; gap: .65rem; padding: 1rem 1.25rem .25rem; }
    .rv-card-body { padding: .5rem 1.25rem 1.1rem; }
    .rv-num { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 50%; font-size: .75rem; font-weight: 600; color: var(--color-primary, #B5524C); background: var(--color-primary-soft-bg, #F4E6E4); }
    .rv-title { margin: 0; font-size: 1rem; font-weight: 600; line-height: 1.5rem; }
    .rv-sub { margin: .1rem 0 0; font-size: .8125rem; color: var(--color-text-muted, #6E6B63); }

    .rv-row { display: grid; grid-template-columns: 9.5rem minmax(0, 1fr) auto; gap: .5rem 1rem; align-items: start; padding: .7rem 0; border-top: 1px solid var(--color-border-subtle, #EFEBE4); font-size: .875rem; }
    .rv-row:first-child { border-top: 0; }
    .rv-row-label { color: var(--color-text-muted, #6E6B63); font-size: .8125rem; padding-top: .1rem; }
    .rv-row-value { min-width: 0; overflow-wrap: anywhere; }
    .rv-edit { font-size: .8125rem; font-weight: 500; white-space: nowrap; color: var(--color-primary, #B5524C); text-decoration: none; padding: .1rem .35rem; border-radius: .3rem; }
    .rv-edit:hover { text-decoration: underline; }
    .rv-edit:focus-visible, .rv-nav a:focus-visible, .rv-photo-add:focus-visible { outline: 2px solid var(--color-primary, #B5524C); outline-offset: 2px; }
    .rv-muted { color: var(--color-text-muted, #6E6B63); }

    .rv-chips { display: flex; flex-wrap: wrap; gap: .35rem; }
    .rv-chip { display: inline-block; padding: .15rem .6rem; font-size: .75rem; border-radius: 999px; background: var(--color-surface-secondary, #F3F1EC); border: 1px solid var(--color-border-subtle, #EAE6DF); }

    .rv-glance { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .35rem 1.5rem; font-size: .875rem; margin-bottom: .9rem; }
    .rv-glance dt { font-size: .75rem; font-weight: 400; color: var(--color-text-muted, #6E6B63); }
    .rv-glance dd { margin: 0 0 .35rem; }
    .rv-metrics { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: .5rem; }
    .rv-metric { border: 1px solid var(--color-border-subtle, #EAE6DF); border-radius: .5rem; padding: .55rem .7rem; background: var(--color-surface-secondary, #FBFAF7); }
    .rv-metric strong { display: block; font-size: 1.25rem; line-height: 1.3; }
    .rv-metric span { font-size: .75rem; color: var(--color-text-muted, #6E6B63); }

    .rv-templates { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .9rem; }
    .rv-template { display: flex; flex-direction: column; border: 1px solid var(--color-border, #E5E1DA); border-radius: .65rem; overflow: hidden; background: var(--color-surface, #fff); }
    .rv-template.is-selected { border-color: var(--color-primary, #B5524C); box-shadow: 0 0 0 2px var(--color-primary, #B5524C); }
    .rv-template-frame { position: relative; overflow: hidden; aspect-ratio: 16 / 10; background: #f1f1f1; }
    .rv-template-frame iframe { position: absolute; top: 0; left: 0; width: 1280px; height: 800px; border: 0; transform-origin: 0 0; pointer-events: none; }
    .rv-template-flag { position: absolute; top: .5rem; right: .5rem; z-index: 1; padding: .15rem .55rem; font-size: .7rem; font-weight: 600; border-radius: 999px; color: #fff; background: var(--color-primary, #B5524C); }
    .rv-template-body { padding: .7rem .8rem .8rem; display: flex; flex-direction: column; gap: .5rem; flex: 1 1 auto; }
    .rv-template-name { font-weight: 600; font-size: .9rem; }
    .rv-template-desc { font-size: .78rem; color: var(--color-text-muted, #6E6B63); line-height: 1.4; }
    .rv-template-actions { margin-top: auto; display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
    .rv-template-actions .btn { justify-content: center; }

    .rv-brand { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin-top: 1.1rem; padding-top: 1rem; border-top: 1px solid var(--color-border-subtle, #EFEBE4); }
    .rv-brand-links { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-top: .9rem; }
    .rv-field-label { display: block; margin-bottom: .3rem; font-size: .75rem; font-weight: 500; color: var(--color-text-muted, #6E6B63); }
    .rv-color { display: flex; align-items: center; gap: .5rem; }
    .rv-swatch { flex: 0 0 auto; width: 2.1rem; height: 2.1rem; padding: 0; border: 1px solid var(--color-border, #E5E1DA); border-radius: .45rem; background: none; cursor: pointer; }
    .rv-upload { display: flex; align-items: center; gap: .6rem; min-width: 0; }
    .rv-thumb { flex: 0 0 auto; width: 3rem; height: 2.25rem; object-fit: cover; border-radius: .4rem; border: 1px solid var(--color-border-subtle, #EAE6DF); background: var(--color-surface-secondary, #F3F1EC); }
    .rv-thumb--logo { object-fit: contain; }
    .rv-upload-name { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .75rem; color: var(--color-text-muted, #6E6B63); }
    .rv-file { position: relative; margin: 0; flex: 0 0 auto; }
    .rv-file:focus-within { outline: 2px solid var(--color-primary, #B5524C); outline-offset: 2px; border-radius: .4rem; }
    .rv-alt { margin-top: .9rem; font-size: .8125rem; }
    .rv-alt summary { cursor: pointer; color: var(--color-text-muted, #6E6B63); }
    .rv-alt-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .6rem; margin-top: .5rem; }

    .rv-tiles { display: flex; flex-wrap: wrap; gap: .75rem; }
    .rv-tile { width: 6rem; font-size: .75rem; line-height: 1.3; }
    .rv-tile img, .rv-tile-ph { display: block; width: 6rem; height: 4.5rem; object-fit: cover; border-radius: .45rem; border: 1px solid var(--color-border-subtle, #EAE6DF); margin-bottom: .3rem; }
    .rv-tile-ph { display: flex; align-items: center; justify-content: center; text-align: center; padding: .3rem; font-size: .7rem; color: var(--color-status-warning-text, #8A5A00); background: var(--color-status-warning-soft-bg, #FFF6E0); border-style: dashed; border-color: var(--color-status-warning-border, #EBCB7C); }
    .rv-tile-name { font-weight: 500; overflow-wrap: anywhere; }

    .rv-table { width: 100%; font-size: .8125rem; border-collapse: collapse; }
    .rv-table td { padding: .35rem .5rem .35rem 0; vertical-align: top; border-top: 1px solid var(--color-border-subtle, #EFEBE4); }
    .rv-table tr:first-child td { border-top: 0; }
    .rv-table td:nth-child(2) { white-space: nowrap; font-weight: 500; }
    .rv-notice { margin-top: .5rem; padding: .45rem .65rem; font-size: .78rem; border-radius: .45rem; background: var(--color-status-warning-soft-bg, #FFF6E0); color: var(--color-status-warning-text, #8A5A00); border: 1px solid var(--color-status-warning-border, #EBCB7C); }
    .rv-more summary { cursor: pointer; font-size: .8125rem; color: var(--color-primary, #B5524C); }
    .rv-story { color: var(--color-text-primary, #262522); }
    .rv-faq { margin: 0; padding-left: 1.2rem; }
    .rv-faq li { margin-bottom: .15rem; }

    .rv-alert { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: .75rem 1rem; border-radius: .6rem; font-size: .875rem; }
    .rv-alert--danger { background: var(--color-status-danger-soft-bg, #FDECEA); color: var(--color-status-danger-text, #A12622); border: 1px solid var(--color-status-danger-border, #F2BDB8); }
    .rv-alert--info { background: var(--color-status-info-soft-bg, #E8F1FB); color: var(--color-status-info-text, #1F5FA8); border: 1px solid var(--color-status-info-border, #B9D3F0); }

    .rv-side-card { padding: 1rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem; background: var(--color-surface, #fff); }
    .rv-side-card h2 { margin: 0 0 .25rem; font-size: .95rem; font-weight: 600; }
    .rv-side-card--warm { background: var(--color-status-warning-soft-bg, #FFFBEF); border-color: var(--color-status-warning-border, #F0DFA8); }
    .rv-checks { list-style: none; margin: .6rem 0 .8rem; padding: 0; font-size: .8125rem; display: flex; flex-direction: column; gap: .35rem; }
    .rv-checks li { display: flex; gap: .45rem; align-items: flex-start; }
    .rv-ok { color: var(--color-status-success-text, #1E7B3A); }
    .rv-warn { color: var(--color-status-warning-text, #8A5A00); }
    .rv-photo { display: flex; align-items: center; gap: .55rem; padding: .4rem 0; font-size: .8125rem; border-top: 1px solid var(--color-border-subtle, #EFEBE4); }
    .rv-photo:first-of-type { border-top: 0; }
    .rv-photo-thumb { flex: 0 0 auto; width: 1.9rem; height: 1.9rem; border-radius: .35rem; object-fit: cover; background: var(--color-surface-secondary, #F3F1EC); border: 1px solid var(--color-border-subtle, #EAE6DF); }
    .rv-photo-title { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rv-photo-add { flex: 0 0 auto; font-size: .75rem; padding: .15rem .55rem; border: 1px solid var(--color-primary, #B5524C); border-radius: .4rem; color: var(--color-primary, #B5524C); text-decoration: none; background: var(--color-surface, #fff); }
    .rv-photo-add:hover { background: var(--color-primary-soft-bg, #F4E6E4); }
    .rv-photo-done { flex: 0 0 auto; font-size: .75rem; }

    .rv-nav { padding: 1rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem; background: var(--color-surface, #fff); }
    .rv-nav h2 { margin: 0 0 .4rem; font-size: .7rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6E6B63); }
    .rv-nav a { display: block; padding: .3rem .55rem; font-size: .8125rem; border-radius: .4rem; color: var(--color-text-secondary, #4A4741); text-decoration: none; }
    .rv-nav a:hover { background: var(--color-surface-secondary, #F3F1EC); }
    .rv-nav a.is-active { color: var(--color-primary, #B5524C); background: var(--color-primary-soft-bg, #F4E6E4); font-weight: 600; }

    .rv-footer { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: space-between; align-items: center; }

    @media (max-width: 1199.98px) {
        .rv-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .rv-brand { grid-template-columns: minmax(0, 1fr); }
    }

    @media (max-width: 991.98px) {
        /* One column: the aside's cards become siblings of the main column so "Ready to generate" can lead the page. */
        .rv-layout { display: flex; flex-direction: column; gap: var(--rv-gap); }
        .rv-aside { display: contents; }
        .rv-aside > .rv-side-ready { order: 0; }
        .rv-main { order: 1; }
        .rv-aside > .rv-side-photos { order: 2; }
        .rv-aside > .rv-nav { display: none; }
    }

    @media (max-width: 575.98px) {
        .rv-row { grid-template-columns: minmax(0, 1fr) auto; }
        .rv-row-label { grid-column: 1 / -1; padding-top: 0; }
        .rv-row-value { grid-column: 1; }
        .rv-edit { grid-column: 2; grid-row: 2; }
        .rv-glance, .rv-brand-links, .rv-alt-grid { grid-template-columns: minmax(0, 1fr); }
        .rv-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .rv-templates { grid-template-columns: minmax(0, 1fr); }
        .rv-footer .btn, .rv-footer form { width: 100%; }
        .rv-footer form .btn { width: 100%; justify-content: center; }
    }

    @media (prefers-reduced-motion: reduce) { .rv * { scroll-behavior: auto !important; } }
</style>
