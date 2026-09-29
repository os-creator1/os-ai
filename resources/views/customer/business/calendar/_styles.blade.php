{{--
    Shared visual pass for the Calendar module — Contract 15 UI completion
    lane. Included via @section('page-style') on every Calendar view, so
    the module-nav/toolbar look identical everywhere and the FullCalendar
    overrides live in exactly one place.

    Plain inline <style>, not a new SCSS partial: this repository compiles
    resources/scss/** through a Mix/Node build this lane cannot run, and
    every color below is a `var(--color-*)` runtime custom property already
    defined by resources/scss/base/tokens/_colors.scss — no new hex value
    is introduced, and an active per-tenant theme override still applies.
--}}
<style>
    /* ---------------------------------------------------------------
       Module header / subnav
    --------------------------------------------------------------- */
    .calendar-subnav {
        display: flex;
        flex-wrap: wrap;
        gap: .25rem;
        border-bottom: 1px solid var(--color-border, #E5E1DA);
    }

    .calendar-subnav-link {
        display: inline-flex;
        align-items: center;
        gap: .375rem;
        padding: .5rem .25rem;
        margin-bottom: -1px;
        font-size: .8125rem;
        font-weight: 500;
        color: var(--color-text-muted, #6F6D67);
        border-bottom: 2px solid transparent;
        text-decoration: none;
        white-space: nowrap;
    }

    .calendar-subnav-link + .calendar-subnav-link {
        margin-left: 1rem;
    }

    .calendar-subnav-link:hover {
        color: var(--color-text-primary, #262522);
        text-decoration: none;
    }

    .calendar-subnav-link.is-active {
        color: var(--color-primary, #B5524C);
        border-bottom-color: var(--color-primary, #B5524C);
    }

    .calendar-change-location {
        color: var(--color-link, var(--color-primary));
    }

    /* ---------------------------------------------------------------
       Toolbar — compact, single row on desktop, wraps on narrow widths
    --------------------------------------------------------------- */
    .calendar-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding: .75rem 1rem;
        background: var(--color-surface, #fff);
        border: 1px solid var(--color-border, #E5E1DA);
        border-bottom: none;
        border-radius: .5rem .5rem 0 0;
    }

    .calendar-toolbar-nav {
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .calendar-toolbar-steps {
        display: inline-flex;
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .375rem;
        overflow: hidden;
    }

    .calendar-toolbar-steps a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        color: var(--color-text-primary, #262522);
    }

    .calendar-toolbar-steps a:hover {
        background: var(--color-row-hover, var(--color-primary-soft-bg));
    }

    .calendar-toolbar-steps a + a {
        border-left: 1px solid var(--color-border, #E5E1DA);
    }

    .calendar-range-label {
        font-size: .9375rem;
        font-weight: 600;
        color: var(--color-text-primary, #262522);
        white-space: nowrap;
    }

    .calendar-view-switch {
        display: inline-flex;
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .375rem;
        overflow: hidden;
    }

    .calendar-view-switch a {
        padding: .375rem .75rem;
        font-size: .8125rem;
        font-weight: 500;
        color: var(--color-text-primary, #262522);
    }

    .calendar-view-switch a:hover {
        text-decoration: none;
        background: var(--color-row-hover, var(--color-primary-soft-bg));
    }

    .calendar-view-switch a.is-active {
        background: var(--color-primary, #B5524C);
        color: var(--color-text-inverse, #fff);
    }

    .calendar-view-switch a + a {
        border-left: 1px solid var(--color-border, #E5E1DA);
    }

    .calendar-hint {
        font-size: .8125rem;
        color: var(--color-text-muted, #6F6D67);
    }

    @media (max-width: 575.98px) {
        .calendar-toolbar {
            justify-content: flex-start;
        }

        .calendar-toolbar > * {
            width: 100%;
        }

        .calendar-range-label {
            order: -1;
            width: 100%;
            text-align: center;
        }
    }

    /* ---------------------------------------------------------------
       FullCalendar restyle — the vendored v5.7.2 build's own stylesheet
       is loaded as-is; this overrides its defaults, it doesn't replace
       the engine. Scoped to #calendar-grid so nothing else on the page
       is affected.
    --------------------------------------------------------------- */
    #calendar-grid-wrap {
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: 0 0 .5rem .5rem;
        background: var(--color-surface, #fff);
        overflow: hidden;
    }

    #calendar-grid {
        --fc-border-color: var(--color-border-subtle, #F2F0ED);
        --fc-page-bg-color: var(--color-surface, #fff);
        --fc-neutral-bg-color: var(--color-surface-secondary, #FBFAF7);
        --fc-list-event-hover-bg-color: var(--color-row-hover, var(--color-primary-soft-bg));
        --fc-today-bg-color: transparent;
    }

    #calendar-grid .fc {
        font-family: inherit;
        font-size: .8125rem;
    }

    #calendar-grid .fc-col-header-cell {
        background: var(--color-surface-secondary, #FBFAF7);
        padding: .5rem 0;
    }

    #calendar-grid .fc-col-header-cell-cushion {
        color: var(--color-text-muted, #6F6D67);
        font-weight: 600;
        text-transform: uppercase;
        font-size: .6875rem;
        letter-spacing: .03em;
        padding: .25rem;
    }

    #calendar-grid .fc-col-header-cell.calendar-is-today .fc-col-header-cell-cushion {
        color: var(--color-primary, #B5524C);
    }

    #calendar-grid .fc-timegrid-col.calendar-is-today {
        background: var(--color-primary-soft-bg, #F4E6E4);
    }

    #calendar-grid .fc-timegrid-axis-cushion,
    #calendar-grid .fc-timegrid-slot-label-cushion {
        color: var(--color-text-muted, #6F6D67);
        font-size: .75rem;
    }

    #calendar-grid .fc-timegrid-slot {
        height: 2.6em;
    }

    #calendar-grid .fc-timegrid-slot-minor {
        border-top-style: dotted;
    }

    #calendar-grid .fc-timegrid-slot-lane:hover {
        background: var(--color-row-hover, var(--color-primary-soft-bg));
        cursor: pointer;
    }

    #calendar-grid .fc-event {
        border: none;
        border-radius: .25rem;
        padding: 1px 4px;
        font-size: .75rem;
        line-height: 1.25;
        box-shadow: 0 1px 2px var(--color-shadow-tint, rgba(38, 37, 34, .08));
    }

    #calendar-grid .fc-event:hover {
        filter: brightness(0.97);
    }

    #calendar-grid .fc-event .fc-event-main {
        padding: 2px 4px;
    }

    /* Status colors — the existing classNames() output, restyled with
       design tokens instead of FullCalendar's default blue for every
       event regardless of state. */
    #calendar-grid .calendar-status-scheduled {
        background: var(--color-primary-soft-bg, #F4E6E4);
        border-left: 3px solid var(--color-primary, #B5524C) !important;
        color: var(--color-text-primary, #262522);
    }

    #calendar-grid .calendar-status-completed {
        background: var(--color-status-success-soft-bg, #DFF7E9);
        border-left: 3px solid var(--color-status-success, #28C76F) !important;
        color: var(--color-text-primary, #262522);
    }

    #calendar-grid .calendar-status-cancelled {
        background: var(--color-surface-secondary, #FBFAF7);
        border-left: 3px solid var(--color-border-strong, #A29F9A) !important;
        color: var(--color-text-muted, #6F6D67);
        text-decoration: line-through;
    }

    #calendar-grid .calendar-status-no_show {
        background: var(--color-status-danger-soft-bg, #FCE5E6);
        border-left: 3px solid var(--color-status-danger, #EA5455) !important;
        color: var(--color-text-primary, #262522);
    }

    #calendar-grid .fc-scrollgrid {
        border-color: var(--color-border-subtle, #F2F0ED);
    }
</style>
