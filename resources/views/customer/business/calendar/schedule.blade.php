@extends(request()->query('fragment') === '1' ? 'customer.business.calendar._fragment' : 'customer.business.calendar._frame')

@section('title', 'Calendar')
@section('calendar-active', 'schedule')

@section('vendor-style')
    {{-- FullCalendar v5.7.2's own stylesheet. The vendored JS bundle does NOT inject it, so without
         this link the grid has no table layout, no borders and collapsed day columns. It is not in
         the Mix manifest, so it is referenced directly (as the script below is). When the schedule is
         reached by a tab swap instead of a page load, _scripts.blade.php loads it on demand. --}}
    <link rel="stylesheet" href="{{ asset('vendors/css/calendars/fullcalendar.min.css') }}">
@endsection

@section('vendor-script')
    {{-- Vendored FullCalendar v5.7.2 (public/vendors/js/calendar/fullcalendar.min.js). --}}
    <script src="{{ asset('vendors/js/calendar/fullcalendar.min.js') }}"></script>
@endsection

@section('calendar-section')
    @php
        // Implementation Contract 15 §12.D — presentation only.
        //
        // Everything below was decided before this view rendered: the Location
        // was authorized through LocationAccessGuard, and $appointments was read
        // for THAT Location alone. Nothing here re-derives an authorization
        // answer, and there is no other read surface — navigation is plain links
        // (a tab/range swap re-requests this same page for its section only), so
        // there is no client-side data endpoint to authorize separately.
        $scope = [$workspace->uid, $business->uid, $location->uid];
        // `days` is only carried when it is not the classic 7, so the everyday URL stays clean.
        $link = static fn (string $view, string $date) => route('customer.workspaces.businesses.calendar.schedule', $scope)
            . '?' . http_build_query(['view' => $view, 'date' => $date] + ($view === 'week' && $days !== 7 ? ['days' => $days] : []));
        $rangeLabel = $view === 'day'
            ? $anchor->format('l j F Y')
            : $rangeFrom->format('j M') . ' – ' . $rangeTo->copy()->subDay()->format('j M Y');
        $staffName = static fn ($user) => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? ('User #' . $user->id));
        $statusLabels = ['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled', 'completed' => 'Completed', 'no_show' => 'No-show'];
    @endphp

    @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $key => $variant)
        @if (session($key))
            <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
        @endif
    @endforeach

    <div data-section="calendar-schedule">
        <div class="calendar-toolbar">
            <div class="calendar-toolbar-nav">
                <x-button variant="secondary" size="sm" :href="$link($view, $todayAnchor)" data-role="calendar-today" data-calendar-nav>Today</x-button>

                <div class="calendar-toolbar-steps" role="group" aria-label="Move through time">
                    <a href="{{ $link($view, $previous) }}" data-role="calendar-prev" data-calendar-nav aria-label="Previous {{ $view }}">
                        <x-ds-icon name="chevron-left" size="16" aria-hidden="true" />
                    </a>
                    <a href="{{ $link($view, $next) }}" data-role="calendar-next" data-calendar-nav aria-label="Next {{ $view }}">
                        <x-ds-icon name="chevron-right" size="16" aria-hidden="true" />
                    </a>
                </div>

                <h2 class="calendar-range-label mb-0" data-role="calendar-range">{{ $rangeLabel }}</h2>
            </div>

            <div class="d-flex align-items-center" style="gap: .75rem;">
                <div class="calendar-view-switch" role="group" aria-label="Calendar view">
                    <a class="{{ $view === 'day' ? 'is-active' : '' }}" data-role="view-day" data-calendar-nav href="{{ $link('day', $dayAnchor) }}"
                       @if ($view === 'day') aria-current="page" @endif>Day</a>
                    <a class="{{ $view === 'week' ? 'is-active' : '' }}" data-role="view-week" data-calendar-nav href="{{ $link('week', $anchor->toDateString()) }}"
                       @if ($view === 'week') aria-current="page" @endif>Week</a>
                </div>

                <x-button variant="primary" size="sm" icon="plus" data-role="new-appointment"
                          :href="route('customer.workspaces.businesses.calendar.appointments.create', $scope) . '?date=' . $anchor->toDateString()">
                    New appointment
                </x-button>
            </div>
        </div>

        {{-- FullCalendar mounts here. The <details> below carries the same data,
             server-rendered — usable even if the script never runs. The events ride along
             as inert JSON (a script of type application/json never executes), so a section
             swap can hand them to FullCalendar without running any inline script. --}}
        <div id="calendar-grid-wrap">
            <div id="calendar-grid" data-role="calendar-grid"
                 data-view="{{ $view }}"
                 data-date="{{ $anchor->toDateString() }}"
                 data-range-start="{{ $rangeFrom->toDateString() }}"
                 data-days="{{ $view === 'day' ? 1 : $days }}"
                 data-today="{{ now($timezone)->toDateString() }}"
                 data-create-url="{{ route('customer.workspaces.businesses.calendar.appointments.create', $scope) }}"></div>
            <script type="application/json" data-role="calendar-events">@json($events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS)</script>
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
