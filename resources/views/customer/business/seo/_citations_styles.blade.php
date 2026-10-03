{{--
    Citations dashboard — visual pass.

    Plain inline <style> (the Calendar precedent): this repository compiles
    resources/scss/** through a Mix/Node build, and every color below is a
    `var(--color-*)` runtime custom property from the token layer, so no new
    hex value is introduced and a per-tenant theme override still applies.
    Class prefix `cz-` is local to this page.

    Listing rows are one CSS-grid component. Desktop: seven columns.
    Tablet: directory + status + a three-field details block + actions.
    Mobile: each directory is a stacked card (labels come from data-label).
--}}
<style>
    .cz-note { display: flex; gap: .625rem; align-items: flex-start; padding: .625rem .875rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .5rem; background: var(--color-surface-secondary, #F7F5F1); color: var(--color-text-muted, #6F6D67); font-size: .8125rem; }
    .cz-note .ds-icon { flex: none; margin-top: .1rem; }

    /* ---- summary cards ---- */
    .cz-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .875rem; }
    .cz-stat { padding: .875rem 1rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .625rem; background: var(--color-surface, #fff); }
    .cz-stat-label { display: flex; align-items: center; gap: .4rem; font-size: .75rem; font-weight: 500; color: var(--color-text-muted, #6F6D67); }
    .cz-stat-value { margin: .25rem 0 0; font-size: 1.625rem; font-weight: 600; line-height: 1.15; color: var(--color-text-primary, #1F1E1B); }
    .cz-stat-value small { font-size: .875rem; font-weight: 500; color: var(--color-text-muted, #6F6D67); }
    .cz-stat-sub { margin: .125rem 0 0; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .cz-stat--ok .ds-icon { color: var(--color-status-success-icon, #2E7D4F); }
    .cz-stat--warn .ds-icon { color: var(--color-status-warning-icon, #B7791F); }
    @media (max-width: 991.98px) { .cz-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    /* ---- business profile ---- */
    .cz-profile { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin: 0; }
    .cz-profile dt { display: flex; align-items: center; gap: .35rem; font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
    .cz-profile dd { margin: .125rem 0 0; font-size: .9375rem; font-weight: 500; color: var(--color-text-primary, #1F1E1B); overflow-wrap: anywhere; }
    .cz-muted { color: var(--color-text-muted, #6F6D67); font-weight: 400; }
    @media (max-width: 991.98px) { .cz-profile { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 575.98px) { .cz-profile { grid-template-columns: minmax(0, 1fr); } }

    /* ---- needs attention ---- */
    .cz-actions { list-style: none; margin: 0; padding: 0; }
    .cz-actions li { display: flex; align-items: center; gap: .75rem; padding: .625rem 0; }
    .cz-actions li + li { border-top: 1px solid var(--color-border-subtle, #EEEBE5); }
    .cz-actions .cz-actions-body { flex: 1 1 auto; min-width: 0; }
    .cz-actions .cz-actions-title { font-weight: 600; font-size: .875rem; }
    .cz-actions .cz-actions-copy { font-size: .8125rem; color: var(--color-text-muted, #6F6D67); }

    /* ---- listings grid ---- */
    .cz-list { border: 1px solid var(--color-border, #E5E1DA); border-radius: .625rem; background: var(--color-surface, #fff); overflow: hidden; }
    .cz-row { display: grid; align-items: center; gap: .5rem 1rem; padding: .875rem 1rem; grid-template-columns: minmax(11rem, 1.5fr) minmax(8rem, .9fr) minmax(8rem, 1.1fr) minmax(7rem, .9fr) minmax(9rem, 1.3fr) minmax(6.5rem, .8fr) auto; grid-template-areas: "dir status name phone addr checked actions"; }
    .cz-row + .cz-row { border-top: 1px solid var(--color-border-subtle, #EEEBE5); }
    .cz-row--head { padding-top: .625rem; padding-bottom: .625rem; background: var(--color-table-header, #F7F5F1); font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
    .cz-row[data-drawer] { cursor: pointer; }
    .cz-row[data-drawer]:hover { background: var(--color-row-hover, #FBFAF7); }
    .cz-c-dir { grid-area: dir; } .cz-c-status { grid-area: status; } .cz-c-name { grid-area: name; } .cz-c-phone { grid-area: phone; }
    .cz-c-addr { grid-area: addr; } .cz-c-checked { grid-area: checked; } .cz-c-actions { grid-area: actions; }
    .cz-c-detail { grid-area: detail; }
    .cz-row--wide { grid-template-areas: "dir status detail detail detail detail actions"; }
    .cz-dir { display: flex; align-items: center; gap: .625rem; min-width: 0; }
    .cz-dir-icon { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 2.125rem; height: 2.125rem; border-radius: .5rem; background: var(--color-primary-soft-bg, #EEF1FB); color: var(--color-primary, #4B5FD6); }
    .cz-dir-name { display: block; padding: 0; border: 0; background: none; font-weight: 600; font-size: .9375rem; color: var(--color-text-primary, #1F1E1B); text-align: left; }
    .cz-dir-name:hover { color: var(--color-primary, #4B5FD6); }
    .cz-dir-kind { display: block; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .cz-helper { margin: .25rem 0 0; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .cz-badge { display: inline-flex; align-items: center; gap: .3rem; }
    .cz-field { display: flex; align-items: flex-start; gap: .35rem; min-width: 0; font-size: .8125rem; overflow-wrap: anywhere; }
    .cz-field .ds-icon { flex: none; margin-top: .15rem; }
    .cz-field--ok .ds-icon { color: var(--color-status-success-icon, #2E7D4F); }
    .cz-field--diff .ds-icon { color: var(--color-status-warning-icon, #B7791F); }
    .cz-field--diff .cz-field-value { color: var(--color-status-warning-text, #8A5A12); font-weight: 500; }
    .cz-field--none { color: var(--color-text-muted, #6F6D67); }
    .cz-checked { font-size: .8125rem; }
    .cz-row-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .375rem; }
    .cz-label { display: none; }

    @media (max-width: 1199.98px) {
        .cz-row { grid-template-columns: minmax(10rem, 1.4fr) minmax(8rem, 1fr) minmax(0, 2fr) auto;
                  grid-template-areas: "dir status name actions" "dir status phone actions" "dir status addr actions" "dir status checked actions"; }
        .cz-row--head { display: none; }
        .cz-label { display: inline; font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); min-width: 4.25rem; }
        .cz-field, .cz-checked { gap: .5rem; }
        .cz-row--wide { grid-template-areas: "dir status detail actions"; }
        .cz-row-actions { flex-direction: column; align-items: stretch; }
    }
    @media (max-width: 767.98px) {
        .cz-row { grid-template-columns: minmax(0, 1fr); grid-template-areas: "dir" "status" "name" "phone" "addr" "checked" "actions"; gap: .5rem; padding: 1rem; }
        .cz-row--wide { grid-template-areas: "dir" "status" "detail" "actions"; }
        .cz-row-actions { flex-direction: row; justify-content: flex-start; }
        .cz-row-actions > * { flex: 1 1 auto; }
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
