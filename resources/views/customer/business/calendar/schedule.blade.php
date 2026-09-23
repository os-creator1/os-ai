@extends('layouts/contentLayoutMaster')

@section('title', 'Calendar')

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

    <section class="mb-2">
        <h1 class="h3 mb-25">Calendar</h1>
        <p class="text-body mb-0">
            <strong data-role="calendar-location-name">{{ $location->name ?: 'This location' }}</strong>
            · times shown in {{ $timezone }}
            @if ($hasSiblingLocations)
                · <a href="{{ route('customer.workspaces.businesses.calendar.index', [$workspace->uid, $business->uid]) }}" data-role="change-location">Change location</a>
            @endif
        </p>
    </section>

    @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $key => $variant)
        @if (session($key))
            <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
        @endif
    @endforeach

    <div class="card" data-section="calendar-schedule">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-1" style="gap: .5rem;">
                <div class="btn-group" role="group" aria-label="Move through time">
                    <a class="btn btn-outline-secondary" data-role="calendar-prev" href="{{ $link($view, $previous) }}">‹ Previous</a>
                    <a class="btn btn-outline-secondary" data-role="calendar-today" href="{{ $link($view, now($timezone)->toDateString()) }}">Today</a>
                    <a class="btn btn-outline-secondary" data-role="calendar-next" href="{{ $link($view, $next) }}">Next ›</a>
                </div>

                <h2 class="h5 mb-0" data-role="calendar-range">{{ $rangeLabel }}</h2>

                <div class="btn-group" role="group" aria-label="Calendar view">
                    <a class="btn {{ $view === 'day' ? 'btn-primary' : 'btn-outline-primary' }}" data-role="view-day" href="{{ $link('day', $anchor->toDateString()) }}">Day</a>
                    <a class="btn {{ $view === 'week' ? 'btn-primary' : 'btn-outline-primary' }}" data-role="view-week" href="{{ $link('week', $anchor->toDateString()) }}">Week</a>
                </div>
            </div>

            <div class="mb-1">
                <a class="btn btn-primary" data-role="new-appointment"
                   href="{{ route('customer.workspaces.businesses.calendar.appointments.create', $scope) }}?date={{ $anchor->toDateString() }}">
                    New appointment
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('customer.workspaces.businesses.calendar.booking-types.index', $scope) }}">Booking types</a>
                <a class="btn btn-outline-secondary" href="{{ route('customer.workspaces.businesses.calendar.availability.index', $scope) }}">Staff availability</a>
            </div>

            {{-- FullCalendar mounts here. The table below is the same data, server-rendered. --}}
            <div id="calendar-grid" data-role="calendar-grid"
                 data-view="{{ $view }}"
                 data-date="{{ $anchor->toDateString() }}"
                 data-create-url="{{ route('customer.workspaces.businesses.calendar.appointments.create', $scope) }}"></div>
        </div>
    </div>

    <div class="card" data-section="calendar-agenda">
        <div class="card-body">
            <h3 class="h5">Appointments in view</h3>

            @if ($appointments->isEmpty())
                <p class="mb-0" data-role="calendar-empty">No appointments in this {{ $view }}.</p>
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
                                    <td>{{ $statusLabels[$appointment->status->value] ?? $appointment->status->value }}</td>
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
    </div>
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
                // The server-rendered agenda below carries the same appointments,
                // so the page remains fully usable without the grid.
                return;
            }

            var pad = function (n) { return (n < 10 ? '0' : '') + n; };

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
                height: 640,

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
                // `height` with no `expandRows` still gives a scrollable
                // 24-hour grid honouring `scrollTime` — rows simply keep their
                // natural size instead of stretching to fill empty space, which
                // is a cosmetic difference only.
                events: @json($events),

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
