@extends('layouts/contentLayoutMaster')

@section('title', 'Calendar')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

@section('content')
    @php
        // Implementation Contract 15 §12.D — presentation only.
        //
        // Everything below was decided before this view rendered: the Location
        // was authorized through LocationAccessGuard, and $appointments was read
        // for THAT Location alone. Nothing here re-derives an authorization
        // answer, and there is no other read surface — navigation is plain links,
        // so there is no client-side data endpoint to authorize separately.
        $scope = [$workspace->uid, $business->uid, $location->uid];
        $link = static fn (string $view, string $date) => route('customer.workspaces.businesses.calendar.schedule', $scope) . '?' . http_build_query(['view' => $view, 'date' => $date]);
        $rangeLabel = $view === 'day'
            ? $anchor->format('l j F Y')
            : $rangeFrom->format('j M') . ' – ' . $rangeTo->copy()->subDay()->format('j M Y');
        $staffName = static fn ($user) => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? ('User #' . $user->id));
        $statusLabels = ['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled', 'completed' => 'Completed', 'no_show' => 'No-show'];
    @endphp

    @include('customer.business.calendar._module-nav', ['active' => 'schedule'])

    @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $key => $variant)
        @if (session($key))
            <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
        @endif
    @endforeach

    <div data-section="calendar-schedule">
        <div class="calendar-toolbar">
            <div class="calendar-toolbar-nav">
                <x-button variant="secondary" size="sm" :href="$link($view, now($timezone)->toDateString())" data-role="calendar-today">Today</x-button>

                <div class="calendar-toolbar-steps" role="group" aria-label="Move through time">
                    <a href="{{ $link($view, $previous) }}" data-role="calendar-prev" aria-label="Previous {{ $view }}">
                        <x-ds-icon name="chevron-left" size="16" aria-hidden="true" />
                    </a>
                    <a href="{{ $link($view, $next) }}" data-role="calendar-next" aria-label="Next {{ $view }}">
                        <x-ds-icon name="chevron-right" size="16" aria-hidden="true" />
                    </a>
                </div>

                <h2 class="calendar-range-label mb-0" data-role="calendar-range">{{ $rangeLabel }}</h2>
            </div>

            <div class="d-flex align-items-center" style="gap: .75rem;">
                <div class="calendar-view-switch" role="group" aria-label="Calendar view">
                    <a class="{{ $view === 'day' ? 'is-active' : '' }}" data-role="view-day" href="{{ $link('day', $anchor->toDateString()) }}"
                       @if ($view === 'day') aria-current="page" @endif>Day</a>
                    <a class="{{ $view === 'week' ? 'is-active' : '' }}" data-role="view-week" href="{{ $link('week', $anchor->toDateString()) }}"
                       @if ($view === 'week') aria-current="page" @endif>Week</a>
                </div>

                <x-button variant="primary" size="sm" icon="plus" data-role="new-appointment"
                          :href="route('customer.workspaces.businesses.calendar.appointments.create', $scope) . '?date=' . $anchor->toDateString()">
                    New appointment
                </x-button>
            </div>
        </div>

        {{-- FullCalendar mounts here. The <details> below carries the same data,
             server-rendered — usable even if the script above never runs. --}}
        <div id="calendar-grid-wrap">
            <div id="calendar-grid" data-role="calendar-grid"
                 data-view="{{ $view }}"
                 data-date="{{ $anchor->toDateString() }}"
                 data-today="{{ now($timezone)->toDateString() }}"
                 data-create-url="{{ route('customer.workspaces.businesses.calendar.appointments.create', $scope) }}"></div>
        </div>

        <p class="calendar-hint mt-1 mb-2" data-role="calendar-hint">
            Click a time slot to create an appointment.
        </p>
    </div>

    <details class="mb-2" data-section="calendar-agenda">
        <summary class="text-label" style="cursor: pointer;">
            Appointments in view (list) — {{ $appointments->count() }}
        </summary>

        <div class="mt-2">
            @if ($appointments->isEmpty())
                <p class="text-caption mb-0" data-role="calendar-empty">No appointments in this {{ $view }}.</p>
            @else
                <div class="table-responsive">
                    <table class="table" data-role="calendar-agenda">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>What</th>
                                <th>Customer</th>
                                <th>Staff</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($appointments as $appointment)
                                @php
                                    $start = \Illuminate\Support\Carbon::parse($appointment->start_at)->utc()->setTimezone($timezone);
                                    $end = \Illuminate\Support\Carbon::parse($appointment->end_at)->utc()->setTimezone($timezone);
                                    $contact = $contacts[(int) $appointment->contact_id] ?? null;
                                @endphp
                                <tr data-appointment="{{ $appointment->uid }}">
                                    <td>{{ $start->format('D j M, H:i') }}–{{ $end->format('H:i') }}</td>
                                    <td>{{ $appointment->bookingType?->name ?? 'Appointment' }}</td>
                                    <td>{{ $contact['name'] ?? $contact['phone'] ?? '—' }}</td>
                                    <td>{{ $appointment->staff ? $staffName($appointment->staff) : '—' }}</td>
                                    <td><x-badge :variant="match($appointment->status->value) { 'completed' => 'success', 'cancelled' => 'neutral', 'no_show' => 'danger', default => 'accent' }">{{ $statusLabels[$appointment->status->value] ?? $appointment->status->value }}</x-badge></td>
                                    <td class="text-right">
                                        <a href="{{ route('customer.workspaces.businesses.calendar.appointments.show', array_merge($scope, [$appointment->uid])) }}">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </details>
@endsection

@section('vendor-script')
    {{-- Vendored FullCalendar v5.7.2 (public/vendors/js/calendar/fullcalendar.min.js). It is not in the
         Mix manifest, so it is referenced directly; v5 injects its own stylesheet. --}}
    <script src="{{ asset('vendors/js/calendar/fullcalendar.min.js') }}"></script>
@endsection

@section('page-script')
    <script>
        (function () {
            var mount = document.getElementById('calendar-grid');

            if (!mount || typeof FullCalendar === 'undefined') {
                // The server-rendered <details> agenda carries the same
                // appointments, so the page remains fully usable without the grid.
                return;
            }

            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            var todayLocal = mount.dataset.today;

            // Grid height is a static NUMBER computed once, before render — never
            // 'auto'/'parent' and never combined with `expandRows`. See the note
            // on that option below: this is what keeps the calendar filling more
            // of the viewport on a tall screen without resurrecting the documented
            // resize/reflow freeze.
            var wrap = document.getElementById('calendar-grid-wrap');
            var available = window.innerHeight - wrap.getBoundingClientRect().top - 24;
            var gridHeight = Math.max(640, Math.min(available, 1100));

            var calendar = new FullCalendar.Calendar(mount, {
                initialView: mount.dataset.view === 'day' ? 'timeGridDay' : 'timeGridWeek',
                initialDate: mount.dataset.date,

                // The events carry the Business's LOCAL wall-clock with no offset.
                // FullCalendar 5.7.2 supports only 'local' and 'UTC' without a
                // timezone plugin, so 'UTC' displays those strings verbatim — a
                // named Business timezone shown correctly whatever the viewer's
                // browser timezone is.
                timeZone: 'UTC',

                headerToolbar: false,      // navigation is server-side links above
                firstDay: 1,
                allDaySlot: false,
                nowIndicator: false,       // "now" would be computed in the browser's zone, not the Business's
                slotMinTime: '00:00:00',
                slotMaxTime: '24:00:00',
                scrollTime: '08:00:00',
                height: gridHeight,

                // `expandRows: true` is deliberately OMITTED. Reproduced directly:
                // combined with a fixed pixel `height` inside this theme's
                // Bootstrap flex `.card-body`, FullCalendar 5.7.2 enters an
                // infinite resize/reflow loop trying to stretch each timeGrid row
                // to fill the container — the container's own size depends on
                // that same reflow, so it never settles and the tab's main thread
                // never becomes idle again (confirmed: even a trivial JS
                // evaluation times out afterwards). Isolated by bisection: with
                // `height: 640` alone the grid renders instantly; re-adding
                // `expandRows: true` reproduces the freeze every time. A fixed
                // numeric `height` (whether the literal 640 or one computed once
                // up front, as above) with no `expandRows` still gives a
                // scrollable 24-hour grid honouring `scrollTime` — rows simply
                // keep their natural size instead of stretching to fill empty
                // space, which is a cosmetic difference only.
                events: @json($events),

                // FullCalendar's own built-in "today" highlight compares against
                // the BROWSER's current date in whatever `timeZone` is configured
                // — which here is the literal string 'UTC', not the Business's
                // named zone the events are drawn in. For a Business meaningfully
                // ahead of or behind UTC that can highlight the wrong day. The
                // correct day is computed server-side instead (`data-today`,
                // the Business's own `now($timezone)`) and applied here.
                dayHeaderClassNames: function (arg) {
                    var d = arg.date;
                    var iso = d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
                    return iso === todayLocal ? ['calendar-is-today'] : [];
                },
                dayCellClassNames: function (arg) {
                    var d = arg.date;
                    var iso = d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
                    return iso === todayLocal ? ['calendar-is-today'] : [];
                },

                // An empty slot starts a booking at that Location, date and time.
                // The click only builds a URL; the server re-checks everything.
                dateClick: function (info) {
                    var d = info.date;
                    var date = d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
                    var time = pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
                    window.location.href = mount.dataset.createUrl + '?date=' + date + '&time=' + time;
                }
            });

            calendar.render();
        })();
    </script>
@endsection
