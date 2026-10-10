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
    /* The wrap clips vertically (FullCalendar scrolls the hours itself) and
       scrolls sideways only when the grid's own min-width (set by the section
       script from the day count) is wider than the workspace — a narrow
       screen scrolls instead of squeezing day columns unreadable. */
    #calendar-grid-wrap {
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: 0 0 .5rem .5rem;
        background: var(--color-surface, #fff);
        overflow-x: auto;
        overflow-y: hidden;
    }

    /* The grid lines. FullCalendar draws every hour/day line from
       --fc-border-color, and the line colour used to be --color-border-subtle
       (#F2F0ED on a white surface, ~1.1:1) — present but all-but-invisible.
       --color-border keeps them thin and neutral yet always legible; the
       half-hour rows stay dotted and fainter so the hours still read first. */
    #calendar-grid {
        --fc-border-color: var(--color-border, #E5E1DA);
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
        border-top-color: var(--color-border-subtle, #F2F0ED);
    }

    /* Day separators, stated explicitly rather than left to the theme
       default so the day-column boundary can never silently disappear. */
    #calendar-grid .fc-col-header-cell,
    #calendar-grid .fc-timegrid-col {
        border-left: 1px solid var(--fc-border-color);
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

    /* FullCalendar's stylesheet paints every event's text white
       (--fc-event-text-color) — unreadable on the pale status colours below.
       Inherit the status rule's own text colour instead. */
    #calendar-grid .fc-event .fc-event-main {
        padding: 2px 4px;
        color: inherit;
    }

    /* Event content (see eventContent in _scripts.blade.php): start–end time
       first, then the title. The box height is the appointment's true
       duration; only the content adapts to a short one. */
    #calendar-grid .calendar-event-body {
        display: flex;
        flex-direction: column;
        height: 100%;
        overflow: hidden;
        line-height: 1.2;
    }

    #calendar-grid .calendar-event-time {
        flex: none;
        font-size: .6875rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* <= 30 min: tighter padding and line height, no wrapping. */
    #calendar-grid .fc-event.calendar-event--compact {
        padding: 0;
    }

    /* The event itself is the size container, so the rules below can adapt
       to how wide THIS event is (an overlapped one is only half a column). */
    #calendar-grid .fc-event.calendar-event--compact {
        container-type: inline-size;
    }

    #calendar-grid .calendar-event--compact .fc-event-main {
        padding: 1px 4px;
    }

    #calendar-grid .calendar-event--compact .calendar-event-body {
        line-height: 1.15;
    }

    #calendar-grid .calendar-event--compact .calendar-event-title {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: .6875rem;
    }

    /* <= 25 min: a second line cannot fit, so time and title share one
       line. The title is what gets the ellipsis, never the time. */
    #calendar-grid .calendar-event--single .calendar-event-body {
        flex-direction: row;
        align-items: flex-start;
        gap: .375rem;
    }

    #calendar-grid .calendar-event--single .calendar-event-title {
        flex: 1 1 auto;
    }

    /* Side-by-side overlapping events can leave too little width for a
       title next to the time: drop the title (it is on the tooltip) rather
       than show a bare ellipsis or clip the time. */
    @container (max-width: 7rem) {
        #calendar-grid .calendar-event--single .calendar-event-title {
            display: none;
        }
    }

    @container (max-width: 3.5rem) {
        #calendar-grid .calendar-event--compact .calendar-event-title {
            display: none;
        }
    }

    /* Very narrow (a half-column overlap on a small screen): give the time
       every pixel it needs — it is the one thing that is never cut. */
    /* Narrower still (half a column on a tablet): not even the full range
       fits, so the end time goes first and the START time is never cut. The
       tooltip always carries the whole range. */
    @container (max-width: 4rem) {
        #calendar-grid .calendar-event--compact .calendar-event-time-end {
            display: none;
        }
    }

    @container (max-width: 5rem) {
        #calendar-grid .calendar-event--compact .fc-event-main {
            padding: 1px 2px;
        }

        #calendar-grid .calendar-event--compact .calendar-event-time {
            font-size: .625rem;
            letter-spacing: -.01em;
        }
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

    /* ---------------------------------------------------------------
       Section swap (Calendar view / Booking types / Staff availability).
       Only #calendar-content is replaced; the old section stays put until
       the new one is ready, and a slow request just dims it.
    --------------------------------------------------------------- */
    #calendar-content {
        position: relative;
        transition: opacity .12s ease;
    }

    #calendar-content.is-loading {
        opacity: .55;
        pointer-events: none;
    }

    #calendar-content.is-loading::after {
        content: '';
        position: absolute;
        top: 3rem;
        left: 50%;
        width: 1.5rem;
        height: 1.5rem;
        margin-left: -.75rem;
        border: 2px solid var(--color-border, #E5E1DA);
        border-top-color: var(--color-primary, #B5524C);
        border-radius: 50%;
        animation: calendar-spin .7s linear infinite;
    }

    /* The grid was rendered for a different day count than this screen can
       show; hold it invisible for the one extra request instead of flashing
       the wrong range. */
    #calendar-content.is-reconciling {
        opacity: 0;
    }

    @keyframes calendar-spin {
        to { transform: rotate(360deg); }
    }

    @media (prefers-reduced-motion: reduce) {
        #calendar-content { transition: none; }
        #calendar-content.is-loading::after { animation: none; }
    }

    /* ---------------------------------------------------------------
       Weekly hours editor (Staff availability): one row per day — an
       open/closed switch, its hours, and an "Add hours" action.
    --------------------------------------------------------------- */
    .availability-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    .availability-person {
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .availability-person select {
        width: auto;
        min-width: 12rem;
    }

    .availability-week {
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .5rem;
        overflow: hidden;
    }

    .availability-day {
        display: grid;
        grid-template-columns: 9.5rem minmax(0, 1fr) auto;
        align-items: start;
        gap: .5rem 1rem;
        padding: .75rem 1rem;
    }

    .availability-day + .availability-day {
        border-top: 1px solid var(--color-border-subtle, #F2F0ED);
    }

    .availability-day-name {
        margin: 0;
        padding-top: .25rem;
        font-weight: 500;
    }

    .availability-intervals {
        display: flex;
        flex-direction: column;
        gap: .5rem;
    }

    .availability-interval {
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .availability-interval input[type="time"] {
        width: 8rem;
    }

    .availability-to {
        color: var(--color-text-muted, #6F6D67);
    }

    /* The first set of hours has no remove control (switch the day off
       instead); it keeps its space so every row lines up. */
    .availability-interval:first-child .availability-remove {
        visibility: hidden;
    }

    .availability-closed {
        display: none;
        padding-top: .25rem;
        color: var(--color-text-muted, #6F6D67);
    }

    .availability-day.is-closed .availability-closed {
        display: block;
    }

    .availability-day.is-closed .availability-intervals,
    .availability-day.is-closed [data-add-interval] {
        display: none;
    }

    @media (max-width: 575.98px) {
        .availability-day {
            grid-template-columns: 1fr auto;
        }

        .availability-day-body {
            grid-column: 1 / -1;
            order: 3;
        }

        .availability-interval input[type="time"] {
            flex: 1 1 0;
            width: auto;
            min-width: 0;
            padding-left: .5rem;
            padding-right: .25rem;
            font-size: .8125rem;
        }
    }

    /* ---------------------------------------------------------------
       Time off (Staff availability): the same list-of-rows look as the
       weekly hours, with a quiet "Add time off" that opens an inline editor.
    --------------------------------------------------------------- */
    .timeoff-list {
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .5rem;
        overflow: hidden;
    }

    .timeoff-row {
        display: grid;
        grid-template-columns: minmax(8rem, 12rem) minmax(0, 1.4fr) minmax(0, 1fr) auto;
        align-items: center;
        gap: .25rem 1rem;
        padding: .75rem 1rem;
    }

    .timeoff-row + .timeoff-row {
        border-top: 1px solid var(--color-border-subtle, #F2F0ED);
    }

    .timeoff-range {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .25rem .5rem;
    }

    .timeoff-reason {
        overflow-wrap: anywhere;
    }

    .timeoff-footer {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 1rem;
        margin-top: .75rem;
    }

    .timeoff-footer[hidden] {
        display: none;
    }

    .timeoff-footer.is-empty {
        justify-content: space-between;
        padding: .75rem 1rem;
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .5rem;
    }

    .timeoff-editor {
        margin-top: .75rem;
        padding: 1rem;
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .5rem;
        background: var(--color-surface-secondary, #FBFAF7);
    }

    .timeoff-editor[hidden] {
        display: none;
    }

    .timeoff-fields {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.2fr);
        gap: .75rem 1rem;
    }

    .timeoff-field .form-label {
        margin-bottom: .25rem;
        font-size: .75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: var(--color-text-muted, #6F6D67);
    }

    .timeoff-editor-actions {
        display: flex;
        justify-content: flex-end;
        gap: .5rem;
        margin-top: 1rem;
    }

    @media (max-width: 991.98px) {
        .timeoff-fields {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .timeoff-row {
            grid-template-columns: minmax(0, 1fr) auto;
        }

        .timeoff-range,
        .timeoff-reason {
            grid-column: 1 / -1;
            order: 3;
        }
    }

    @media (max-width: 575.98px) {
        .timeoff-fields {
            grid-template-columns: minmax(0, 1fr);
        }

        .timeoff-footer.is-empty {
            flex-wrap: wrap;
        }
    }

    .calendar-sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        padding: 0;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
</style>
