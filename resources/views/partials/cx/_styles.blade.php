{{--
    Customer UI kit — the shared visual primitives of the redesigned customer
    pages (Calendar booking types, Packages & Products, Contacts, Content,
    Forms, Google Ads and Meta Ads). One partial, included once per page via
    the page-style section, so every screen shares one set of page headers,
    tabs, segmented filters, list cards, rows, pills and two-column layouts.

    Plain inline style, not SCSS: the Mix build is not part of these pages'
    deploy path. Every colour, radius and shadow is a runtime custom property
    from resources/scss/base/tokens, so the active MotionGrove theme (and an
    agency white-label override) still applies — no hex value is introduced
    except as the fallback of a token that is always defined.
--}}
@once
<style>
    /* Page header -------------------------------------------------------- */
    .cx-page-head {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: .75rem 1rem;
        margin-bottom: 1rem;
    }
    .cx-page-head > :first-child { min-width: 0; flex: 1 1 18rem; }
    .cx-page-title {
        margin: 0 0 .25rem;
        font-size: 1.5rem;
        line-height: 1.25;
        font-weight: 700;
        color: var(--color-text-primary);
        overflow-wrap: anywhere;
    }
    .cx-page-sub {
        margin: 0;
        max-width: 40rem;
        font-size: .8125rem;
        line-height: 1.45;
        color: var(--color-text-muted);
    }
    .cx-page-sub strong { color: var(--color-text-primary); font-weight: 600; }
    .cx-page-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .5rem;
    }
    .cx-back {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        margin-bottom: .75rem;
        font-size: .75rem;
        font-weight: 500;
        color: var(--color-text-muted);
        text-decoration: none;
    }
    .cx-back:hover { color: var(--color-primary); text-decoration: none; }

    /* Underline tabs (navigate between pages; scroll sideways when narrow) --- */
    .cx-tabs {
        display: flex;
        gap: 1.25rem;
        margin-bottom: 1rem;
        border-bottom: 1px solid var(--color-border);
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
        -webkit-overflow-scrolling: touch;
    }
    .cx-tabs a {
        display: inline-flex;
        align-items: center;
        gap: .375rem;
        flex: 0 0 auto;
        padding: .5rem 0;
        margin-bottom: -1px;
        font-size: .8125rem;
        font-weight: 500;
        color: var(--color-text-muted);
        border-bottom: 2px solid transparent;
        text-decoration: none;
        white-space: nowrap;
    }
    .cx-tabs a:hover { color: var(--color-text-primary); text-decoration: none; }
    .cx-tabs a.is-active,
    .cx-tabs a[aria-current="page"] {
        color: var(--color-primary);
        border-bottom-color: var(--color-primary);
    }
    .cx-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.25rem;
        padding: 0 .375rem;
        font-size: .6875rem;
        font-weight: 600;
        line-height: 1.25rem;
        border-radius: var(--radius-full);
        color: var(--color-text-muted);
        background: var(--color-surface-secondary);
    }
    .is-active > .cx-count,
    [aria-current="page"] > .cx-count,
    [aria-pressed="true"] > .cx-count {
        color: var(--color-primary);
        background: var(--color-primary-soft-bg);
    }

    /* Segmented filter --------------------------------------------------- */
    .cx-seg {
        display: inline-flex;
        flex-wrap: wrap;
        gap: .125rem;
        padding: .1875rem;
        border-radius: var(--radius-md);
        background: var(--color-surface-secondary);
        border: 1px solid var(--color-border-subtle, var(--color-border));
    }
    .cx-seg > a,
    .cx-seg > button {
        display: inline-flex;
        align-items: center;
        gap: .375rem;
        padding: .3125rem .75rem;
        font-size: .75rem;
        font-weight: 500;
        line-height: 1.2;
        color: var(--color-text-secondary, var(--color-text-muted));
        background: transparent;
        border: 0;
        border-radius: calc(var(--radius-md) - .1875rem);
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
    }
    .cx-seg > a:hover,
    .cx-seg > button:hover { color: var(--color-text-primary); text-decoration: none; }
    .cx-seg > .is-active,
    .cx-seg > [aria-current="page"],
    .cx-seg > [aria-pressed="true"] {
        color: var(--color-text-primary);
        font-weight: 600;
        background: var(--color-surface);
        box-shadow: var(--shadow-sm);
    }

    /* Cards -------------------------------------------------------------- */
    .cx-card {
        background: var(--color-surface);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-lg);
        margin-bottom: 1rem;
        min-width: 0;
    }
    .cx-card-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .5rem .75rem;
        padding: .875rem 1.125rem;
        border-bottom: 1px solid var(--color-border-subtle, var(--color-border));
    }
    .cx-card-head.is-plain { border-bottom: 0; padding-bottom: .25rem; }
    .cx-card-title {
        margin: 0;
        font-size: .9375rem;
        font-weight: 600;
        line-height: 1.3;
        color: var(--color-text-primary);
    }
    .cx-card-sub { margin: .125rem 0 0; font-size: .75rem; color: var(--color-text-muted); }
    .cx-card-body { padding: 1rem 1.125rem; }
    .cx-card-body > :last-child { margin-bottom: 0; }
    .cx-card-foot {
        padding: .75rem 1.125rem;
        border-top: 1px solid var(--color-border-subtle, var(--color-border));
        font-size: .75rem;
        color: var(--color-text-muted);
    }

    /* Form controls inside cx cards: compact, consistent ----------------- */
    .cx-card .form-group { margin-bottom: 1rem; }
    .cx-card label:not(.form-check-label):not(.custom-control-label) {
        margin-bottom: .3125rem;
        font-size: .75rem;
        font-weight: 600;
        color: var(--color-text-primary);
    }
    .cx-hint { display: block; margin-top: .25rem; font-size: .71875rem; line-height: 1.4; color: var(--color-text-muted); }
    .cx-grid-2 {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0 1rem;
    }
    @media (min-width: 576px) {
        .cx-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    /* Two-column editor (main + narrower aside) ------------------------- */
    .cx-split {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 1rem;
        align-items: start;
    }
    @media (min-width: 992px) {
        .cx-split { grid-template-columns: minmax(0, 1fr) minmax(16rem, 21rem); gap: 1.25rem; }
        .cx-split-aside.is-sticky { position: sticky; top: 5.5rem; }
    }
    .cx-split-main, .cx-split-aside { min-width: 0; }

    /* List rows ---------------------------------------------------------- */
    .cx-list { list-style: none; margin: 0; padding: 0; }
    .cx-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .625rem 1rem;
        padding: .875rem 1.125rem;
        border-top: 1px solid var(--color-border-subtle, var(--color-border));
    }
    .cx-list > .cx-row:first-child { border-top: 0; }
    .cx-row-main { display: flex; align-items: center; gap: .75rem; flex: 1 1 16rem; min-width: 0; }
    .cx-row-text { min-width: 0; }
    .cx-row-title {
        display: block;
        margin: 0;
        font-size: .875rem;
        font-weight: 600;
        line-height: 1.3;
        color: var(--color-text-primary);
        overflow-wrap: anywhere;
    }
    a.cx-row-title:hover { color: var(--color-primary); text-decoration: none; }
    .cx-row-sub { display: block; margin-top: .125rem; font-size: .71875rem; color: var(--color-text-muted); overflow-wrap: anywhere; }
    .cx-row-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.5rem; }
    .cx-row-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-left: auto; }
    .cx-meta { min-width: 6.5rem; }
    .cx-meta-label {
        display: block;
        font-size: .625rem;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: var(--color-text-muted);
    }
    .cx-meta-value { display: block; font-size: .78125rem; color: var(--color-text-primary); }
    .cx-meta-value.is-muted { color: var(--color-text-muted); }

    /* Icon tile ---------------------------------------------------------- */
    .cx-tile {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: var(--radius-md);
        color: var(--color-primary);
        background: var(--color-primary-soft-bg);
    }
    .cx-tile.is-info { color: var(--color-status-info-text); background: var(--color-status-info-soft-bg); }
    .cx-tile.is-neutral { color: var(--color-text-muted); background: var(--color-surface-secondary); }

    /* Status pill -------------------------------------------------------- */
    .cx-pill {
        display: inline-flex;
        align-items: center;
        gap: .3125rem;
        padding: .125rem .5rem;
        font-size: .6875rem;
        font-weight: 600;
        line-height: 1.35;
        border-radius: var(--radius-full);
        white-space: nowrap;
        color: var(--color-text-secondary, var(--color-text-muted));
        background: var(--color-surface-secondary);
        border: 1px solid var(--color-border-subtle, var(--color-border));
    }
    .cx-pill.has-dot::before {
        content: "";
        width: .375rem;
        height: .375rem;
        border-radius: var(--radius-full);
        background: currentColor;
    }
    .cx-pill.is-success { color: var(--color-status-success-text); background: var(--color-status-success-soft-bg); border-color: var(--color-status-success-border); }
    .cx-pill.is-warning { color: var(--color-status-warning-text); background: var(--color-status-warning-soft-bg); border-color: var(--color-status-warning-border); }
    .cx-pill.is-danger  { color: var(--color-status-danger-text);  background: var(--color-status-danger-soft-bg);  border-color: var(--color-status-danger-border); }
    .cx-pill.is-info    { color: var(--color-status-info-text);    background: var(--color-status-info-soft-bg);    border-color: var(--color-status-info-border); }
    .cx-pill.is-pending { color: var(--color-status-pending-text); background: var(--color-status-pending-soft-bg); border-color: var(--color-status-pending-border); }
    .cx-pill.is-accent  { color: var(--color-primary); background: var(--color-primary-soft-bg); border-color: var(--color-primary-border, transparent); }

    /* Callouts ----------------------------------------------------------- */
    .cx-callout {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .5rem .75rem;
        padding: .625rem .875rem;
        font-size: .78125rem;
        line-height: 1.45;
        border-radius: var(--radius-md);
        color: var(--color-text-primary);
        background: var(--color-surface);
        border: 1px solid var(--color-border);
    }
    .cx-callout > .ds-icon { flex: 0 0 auto; color: var(--color-icon-muted, var(--color-text-muted)); }
    .cx-callout-text { flex: 1 1 14rem; min-width: 0; }
    .cx-callout.is-warning { background: var(--color-status-warning-soft-bg); border-color: var(--color-status-warning-border); }
    .cx-callout.is-warning > .ds-icon { color: var(--color-status-warning-icon, var(--color-status-warning-text)); }
    .cx-callout.is-danger { background: var(--color-status-danger-soft-bg); border-color: var(--color-status-danger-border); }
    .cx-callout.is-danger > .ds-icon { color: var(--color-status-danger-icon, var(--color-status-danger-text)); }
    .cx-callout.is-success { background: var(--color-status-success-soft-bg); border-color: var(--color-status-success-border); }
    .cx-callout.is-success > .ds-icon { color: var(--color-status-success-icon, var(--color-status-success-text)); }
    .cx-callout.is-info { background: var(--color-status-info-soft-bg); border-color: var(--color-status-info-border); }

    /* Bottom "add" action of a list card --------------------------------- */
    .cx-add-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .375rem;
        width: 100%;
        padding: .75rem 1rem;
        font-size: .78125rem;
        font-weight: 600;
        color: var(--color-primary);
        background: var(--color-surface-secondary);
        border: 0;
        border-top: 1px solid var(--color-border-subtle, var(--color-border));
        border-radius: 0 0 var(--radius-lg) var(--radius-lg);
        text-decoration: none;
    }
    .cx-add-row:hover { color: var(--color-primary-hover, var(--color-primary)); background: var(--color-primary-soft-bg); text-decoration: none; }
    .cx-add-row.is-standalone {
        min-height: 4rem;
        border: 1px dashed var(--color-border-strong, var(--color-border));
        border-radius: var(--radius-lg);
        background: transparent;
    }

    /* Avatar ------------------------------------------------------------- */
    .cx-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        width: 2rem;
        height: 2rem;
        font-size: .6875rem;
        font-weight: 700;
        border-radius: var(--radius-full);
        color: var(--color-primary);
        background: var(--color-primary-soft-bg);
        text-transform: uppercase;
    }
    .cx-avatar.is-sm { width: 1.5rem; height: 1.5rem; font-size: .5625rem; }

    /* Search field ------------------------------------------------------- */
    .cx-search {
        position: relative;
        display: flex;
        align-items: center;
        flex: 1 1 14rem;
        min-width: 0;
    }
    .cx-search > .ds-icon {
        position: absolute;
        left: .75rem;
        color: var(--color-icon-muted, var(--color-text-muted));
        pointer-events: none;
    }
    .cx-search input { padding-left: 2.25rem; }

    /* Toolbar row inside a card ----------------------------------------- */
    .cx-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .5rem .75rem;
        padding: .75rem 1.125rem;
        border-bottom: 1px solid var(--color-border-subtle, var(--color-border));
    }

    /* Small helpers ----------------------------------------------------- */
    .cx-muted { color: var(--color-text-muted); }
    .min-w-0 { min-width: 0; }
    .cx-nowrap { white-space: nowrap; }
    .cx-sr-only {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
    }
    .cx-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        padding: 0;
        color: var(--color-text-muted);
        background: var(--color-surface);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-md);
    }
    .cx-icon-btn:hover:not(:disabled) { color: var(--color-primary); border-color: var(--color-primary-border, var(--color-primary)); }
    .cx-icon-btn:disabled { opacity: .35; cursor: not-allowed; }
    .cx-link-btn {
        display: inline-flex;
        align-items: center;
        gap: .3125rem;
        padding: .25rem .375rem;
        font-size: .75rem;
        font-weight: 500;
        color: var(--color-text-muted);
        background: transparent;
        border: 0;
        border-radius: var(--radius-sm);
        text-decoration: none;
    }
    .cx-link-btn:hover { color: var(--color-primary); background: var(--color-primary-soft-bg); text-decoration: none; }

    @media (max-width: 575.98px) {
        .cx-page-title { font-size: 1.3125rem; }
        .cx-card-head, .cx-card-body, .cx-row, .cx-toolbar, .cx-card-foot { padding-left: .875rem; padding-right: .875rem; }
        .cx-row-actions { margin-left: 0; width: 100%; }
        .cx-page-actions { width: 100%; }
        .cx-page-actions > .btn { flex: 1 1 auto; justify-content: center; }
    }
</style>
@endonce
