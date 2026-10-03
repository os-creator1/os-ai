{{--
    Shared Calendar module shell: page title, Location/timezone subordinate
    line, and a compact real-link subnav across the three existing Calendar
    surfaces. Included (never duplicated) by schedule/picker/appointment/
    create/booking-types/availability views, so all six stay visually and
    structurally one module.

    Deliberately plain <a> links with aria-current="page" for the active
    one, NOT the x-tabs component: x-tabs toggles same-page panels via
    data-bs-toggle, but these three destinations are separate authorized
    routes/pages, which real navigation links represent correctly for
    keyboard and screen-reader users (a "tab" that actually navigates away
    is a known accessibility anti-pattern).

    Polish pass: the three links still work as ordinary links (new tab,
    middle-click, no JavaScript). `data-calendar-nav` only lets
    _scripts.blade.php fetch the destination's section and swap it into
    #calendar-content on a plain click, so the shell does not reload.

    Props:
      workspace, business (required)
      location (optional — absent only on the picker, which has none yet)
      active   'schedule' | 'booking-types' | 'availability' | null
      hasSiblingLocations (optional, only meaningful when $location is set)
--}}
@php
    $calendarScope = $location ? [$workspace->uid, $business->uid, $location->uid] : null;
    $calendarIndexUrl = route('customer.workspaces.businesses.calendar.index', [$workspace->uid, $business->uid]);
@endphp

<div class="calendar-module-header mb-2" data-role="calendar-module-header">
    <div class="d-flex flex-wrap align-items-start justify-content-between" style="gap: .75rem;">
        <div>
            <h1 class="text-page-title mb-25">Calendar</h1>
            @if ($location)
                <p class="text-caption mb-0" data-role="calendar-location-subline">
                    <span data-role="calendar-location-name">{{ $location->name ?: 'This location' }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ $timezone ?? $business->timezone ?? config('app.timezone') }}</span>
                    @if ($hasSiblingLocations ?? false)
                        <span aria-hidden="true">·</span>
                        <a href="{{ $calendarIndexUrl }}" data-role="change-location" class="calendar-change-location">Change location</a>
                    @endif
                </p>
            @else
                <p class="text-caption mb-0">Choose a location to see its schedule.</p>
            @endif
        </div>
    </div>

    @if ($calendarScope)
        <nav class="calendar-subnav mt-2" aria-label="Calendar">
            <a href="{{ route('customer.workspaces.businesses.calendar.schedule', $calendarScope) }}"
               class="calendar-subnav-link {{ $active === 'schedule' ? 'is-active' : '' }}"
               data-calendar-nav data-calendar-key="schedule"
               @if ($active === 'schedule') aria-current="page" @endif>
                <x-ds-icon name="calendar-days" size="15" aria-hidden="true" />
                Calendar view
            </a>
            <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.index', $calendarScope) }}"
               class="calendar-subnav-link {{ $active === 'booking-types' ? 'is-active' : '' }}"
               data-calendar-nav data-calendar-key="booking-types"
               @if ($active === 'booking-types') aria-current="page" @endif>
                <x-ds-icon name="tag" size="15" aria-hidden="true" />
                Booking types
            </a>
            <a href="{{ route('customer.workspaces.businesses.calendar.availability.index', $calendarScope) }}"
               class="calendar-subnav-link {{ $active === 'availability' ? 'is-active' : '' }}"
               data-calendar-nav data-calendar-key="availability"
               @if ($active === 'availability') aria-current="page" @endif>
                <x-ds-icon name="clock" size="15" aria-hidden="true" />
                Staff availability
            </a>
        </nav>
    @endif
</div>
