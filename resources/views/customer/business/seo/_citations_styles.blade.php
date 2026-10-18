{{--
    Citations dashboard — visual pass (matches the Search keywords hierarchy).

    Plain inline <style> (the Calendar precedent): this repository compiles
    resources/scss/** through a Mix/Node build, and every color below is a
    `var(--color-*)` runtime custom property from the token layer, so no new
    hex value is introduced and a per-tenant theme override still applies.
    Class prefix `cz-` is local to this page.

    Listing rows are one CSS-grid component. Desktop: five columns
    (directory, status, name/phone/address, last checked, action).
    Tablet: directory + status + details + action. Mobile: each directory is a
    stacked card (labels come from data-label).
--}}
<style>
    .cz-note { display: flex; gap: .625rem; align-items: flex-start; padding: .75rem .875rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .625rem; background: var(--color-surface-secondary, #F7F5F1); color: var(--color-text-muted, #6F6D67); font-size: .8125rem; }
    .cz-note .ds-icon { flex: none; margin-top: .1rem; }

    /* ---- metric cards: the same hierarchy as Search keywords (big number, small label) ---- */
    .cz-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .875rem; }
    .cz-stat { display: flex; flex-direction: column; justify-content: space-between; min-height: 8.5rem; padding: 1rem 1.125rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .625rem; background: var(--color-surface, #fff); }
    .cz-stat-label { margin: 0; font-size: .8125rem; font-weight: 500; color: var(--color-text-muted, #6F6D67); }
    .cz-stat-value { margin: .5rem 0 0; font-size: 2.75rem; font-weight: 700; line-height: 1; letter-spacing: -.02em; color: var(--color-text-primary, #1F1E1B); }
    .cz-stat-value small { font-size: 1.1rem; font-weight: 500; letter-spacing: 0; color: var(--color-text-muted, #6F6D67); }
    .cz-stat-value.cz-stat-value--text { font-size: 1.25rem; font-weight: 600; letter-spacing: 0; line-height: 1.3; }
    .cz-stat-sub { margin: .5rem 0 0; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .cz-meter { display: block; height: 4px; margin-top: .625rem; border-radius: 999px; background: var(--color-border-subtle, #EEEBE5); overflow: hidden; }
    .cz-meter > i { display: block; height: 100%; border-radius: inherit; background: var(--color-status-success-icon, #2E7D4F); }
    .cz-stat--warn .cz-stat-value { color: var(--color-status-warning-text, #8A5A12); }
    @media (max-width: 991.98px) { .cz-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 575.98px) {
        .cz-stat { min-height: 0; justify-content: flex-start; gap: .5rem; padding: .875rem 1rem; }
        .cz-stat-value { font-size: 2.25rem; }
    }

    /* ---- next steps + business information ---- */
    .cz-split { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(0, 1fr); gap: .875rem; align-items: start; }
    @media (max-width: 991.98px) { .cz-split { grid-template-columns: minmax(0, 1fr); } }
    .cz-card-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .25rem .75rem; margin-bottom: .25rem; }
    .cz-card-meta { font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .cz-profile { display: grid; grid-template-columns: minmax(0, 1fr); gap: .875rem; margin: .75rem 0 1rem; }
    .cz-profile dt { display: block; font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
    .cz-profile dd { margin: .125rem 0 0; font-size: .9375rem; font-weight: 600; color: var(--color-text-primary, #1F1E1B); overflow-wrap: anywhere; }
    .cz-muted { color: var(--color-text-muted, #6F6D67); font-weight: 400; }
    .cz-empty { display: flex; align-items: center; gap: .5rem; padding: .875rem 0 .25rem; font-size: .875rem; color: var(--color-text-muted, #6F6D67); }
    .cz-empty .ds-icon { color: var(--color-status-success-icon, #2E7D4F); }
    .cz-actions { list-style: none; margin: 0; padding: 0; }
    .cz-actions li { display: flex; align-items: center; gap: .75rem; padding: .625rem 0; }
    .cz-actions li + li { border-top: 1px solid var(--color-border-subtle, #EEEBE5); }
    .cz-actions .cz-actions-body { flex: 1 1 auto; min-width: 0; }
    .cz-actions li > .btn, .cz-actions li > a { flex: none; white-space: nowrap; }
    .cz-row-actions .btn { white-space: nowrap; }
    .cz-more > summary { cursor: pointer; padding: .5rem 0; font-size: .8125rem; color: var(--color-primary, #4B5FD6); }
    .cz-actions .cz-actions-title { font-weight: 600; font-size: .875rem; }
    .cz-actions .cz-actions-copy { font-size: .8125rem; color: var(--color-text-muted, #6F6D67); }

    /* ---- filters, groups ---- */
    .cz-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem .75rem; }
    .cz-filters { display: flex; flex-wrap: wrap; gap: .375rem; }
    .cz-filter { padding: .25rem .75rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: 999px; background: var(--color-surface, #fff); font-size: .8125rem; color: var(--color-text-secondary, #4A4944); }
    .cz-filter:hover { border-color: var(--color-primary-border, #C9D0F2); }
    .cz-filter.is-active { background: var(--color-primary-soft-bg, #EEF1FB); border-color: var(--color-primary, #4B5FD6); color: var(--color-primary, #4B5FD6); font-weight: 600; }
    .cz-count { margin-left: .25rem; font-size: .75rem; font-weight: 500; opacity: .75; }
    .cz-search { max-width: 16rem; }
    .cz-group-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .75rem; padding: .625rem 1rem; background: var(--color-surface-secondary, #F7F5F1); border-top: 1px solid var(--color-border-subtle, #EEEBE5); }
    .cz-group:first-of-type .cz-group-head { border-top: 0; }
    .cz-group-title { font-size: .8125rem; font-weight: 600; color: var(--color-text-primary, #1F1E1B); }
    .cz-group-sub { font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .cz-row--muted { opacity: .65; }
    .cz-mode { font-size: .75rem; color: var(--color-text-muted, #6F6D67); }

    /* ---- listings grid ---- */
    .cz-list { border: 1px solid var(--color-border, #E5E1DA); border-radius: .625rem; background: var(--color-surface, #fff); overflow: hidden; }
    .cz-row { display: grid; align-items: center; gap: .5rem 1.25rem; padding: .875rem 1rem; grid-template-columns: minmax(12rem, 1.5fr) minmax(8.5rem, 1fr) minmax(12rem, 1.7fr) minmax(6.5rem, .7fr) auto; grid-template-areas: "dir status nap checked actions"; }
    .cz-row + .cz-row { border-top: 1px solid var(--color-border-subtle, #EEEBE5); }
    .cz-row--head { padding-top: .625rem; padding-bottom: .625rem; background: var(--color-table-header, #F7F5F1); font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
    .cz-row[data-drawer] { cursor: pointer; }
    .cz-row[data-drawer]:hover { background: var(--color-row-hover, #FBFAF7); }
    .cz-c-dir { grid-area: dir; } .cz-c-status { grid-area: status; } .cz-c-nap { grid-area: nap; display: grid; gap: .25rem; min-width: 0; }
    .cz-c-checked { grid-area: checked; } .cz-c-actions { grid-area: actions; } .cz-c-detail { grid-area: nap; }
    .cz-dir { display: flex; align-items: center; gap: .625rem; min-width: 0; }
    .cz-dir-icon { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 2.125rem; height: 2.125rem; border-radius: .5rem; background: var(--color-primary-soft-bg, #EEF1FB); color: var(--color-primary, #4B5FD6); }
    .cz-dir-name { display: block; padding: 0; border: 0; background: none; font-weight: 600; font-size: .9375rem; color: var(--color-text-primary, #1F1E1B); text-align: left; }
    .cz-dir-name:hover { color: var(--color-primary, #4B5FD6); }
    .cz-dir-kind { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .375rem; margin-top: .125rem; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .cz-helper { margin: .25rem 0 0; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .cz-badge { display: inline-flex; align-items: center; gap: .3rem; }
    .cz-field { display: flex; align-items: flex-start; gap: .35rem; min-width: 0; font-size: .8125rem; overflow-wrap: anywhere; }
    .cz-field .ds-icon { flex: none; margin-top: .15rem; }
    .cz-field--ok .ds-icon { color: var(--color-status-success-icon, #2E7D4F); }
    .cz-field--diff .ds-icon { color: var(--color-status-warning-icon, #B7791F); }
    .cz-field--diff .cz-field-value { color: var(--color-status-warning-text, #8A5A12); font-weight: 500; }
    .cz-field--none { color: var(--color-text-muted, #6F6D67); }
    .cz-checked { font-size: .8125rem; }
    .cz-row-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .375rem; }
    .cz-label { display: none; }

    @media (max-width: 1199.98px) {
        .cz-row { grid-template-columns: minmax(10rem, 1.3fr) minmax(0, 2fr) auto;
                  grid-template-areas: "dir status actions" "dir nap actions" "dir checked actions"; }
        .cz-row--head { display: none; }
        .cz-label { display: inline; font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); min-width: 4.25rem; }
        .cz-field, .cz-checked { gap: .5rem; }
    }
    @media (max-width: 767.98px) {
        .cz-row { grid-template-columns: minmax(0, 1fr); grid-template-areas: "dir" "status" "nap" "checked" "actions"; gap: .5rem; padding: 1rem; }
        .cz-row-actions { justify-content: flex-start; }
        .cz-row-actions > .btn:not(.btn-icon) { flex: 1 1 auto; }
    }

    /* ---- drawer ---- */
    .cz-drawer { width: min(32rem, 100vw); }
    .cz-drawer .offcanvas-body { display: flex; flex-direction: column; gap: 1.25rem; }
    .cz-drawer h6 { margin: 0 0 .5rem; font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
    .cz-compare { width: 100%; border-collapse: collapse; font-size: .8125rem; }
    .cz-compare th { font-weight: 600; text-align: left; padding: .375rem .5rem .375rem 0; color: var(--color-text-muted, #6F6D67); white-space: nowrap; }
    .cz-compare td { padding: .375rem .5rem .375rem 0; vertical-align: top; overflow-wrap: anywhere; }
    .cz-compare tr + tr > * { border-top: 1px solid var(--color-border-subtle, #EEEBE5); }
    .cz-drawer-links { display: flex; flex-wrap: wrap; gap: .5rem; }
</style>
