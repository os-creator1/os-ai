@extends('layouts/contentLayoutMaster')

@section('title', 'Book appointment')

@section('page-style')
    @include('customer.business.calendar._styles')
    @include('customer.business.calendar._book-styles')
@endsection

@section('content')
    @php
        // Implementation Contract 15 §12.D — presentation only. Submitting does not book
        // anything by itself: the server re-checks tenancy, entitlement, Location access,
        // Contact and Booking Type reach, and then hands the request to
        // AppointmentBookingService, which re-derives staff eligibility and availability
        // and takes the locks (§7). The days and times drawn here come from the same
        // canonical availability pair, and are advisory.
        $scope = [$workspace->uid, $business->uid, $location->uid];
        $staffName = static fn ($user) => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? ('User #' . $user->id));
        $calendarUrl = route('customer.workspaces.businesses.calendar.schedule', $scope) . '?date=' . $prefillDate;
        $createUrl = route('customer.workspaces.businesses.calendar.appointments.create', $scope);
        $profileUrl = $contact === null ? null : route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact['uid']]);
        $first = $contact['first'] ?? null;
        $carry = array_filter([
            'date' => request()->query('date'),
            'time' => request()->query('time'),
        ], static fn ($v) => is_string($v) && $v !== '');
        $changeUrl = $createUrl . '?' . http_build_query($carry + ['change' => 1]);
        $newTypeUrl = route('customer.workspaces.businesses.calendar.booking-types.create', $scope)
            . ($contact === null ? '' : '?' . http_build_query(['contact' => $contact['uid']]));
        $chev = '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="%s"/></svg>';
    @endphp

    <div class="bk" data-section="book-appointment">
        <div class="bk-head">
            @if ($contact !== null)
                <a href="{{ $profileUrl }}" class="bk-back" data-role="book-back">
                    <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>{{ $contact['name'] }}
                </a>
            @else
                <a href="{{ $calendarUrl }}" class="bk-back" data-role="book-back">
                    <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>Back to calendar
                </a>
            @endif
            <h1>Book appointment</h1>
            <p class="bk-sub">
                @if ($contact === null)
                    Choose who this appointment is for · {{ $location->name ?: 'this location' }}
                @elseif ($bookingTypes->isEmpty())
                    {{ $first }} is selected. Add an appointment type to start booking.
                @else
                    Pick a type and a time for {{ $first }}.
                @endif
            </p>
        </div>

        @foreach (['flash_success' => 'success', 'flash_error' => 'danger'] as $key => $variant)
            @if (session($key))
                <div class="bk-alert" role="{{ $variant === 'danger' ? 'alert' : 'status' }}">{{ session($key) }}</div>
            @endif
        @endforeach

        @if ($errors->any())
            <div class="bk-alert" role="alert">{{ $errors->first() }}</div>
        @endif

        @if ($contact !== null)
            <section class="bk-card bk-contact" data-section="selected-contact" data-contact-uid="{{ $contact['uid'] }}">
                <span class="bk-avatar" aria-hidden="true">{{ $contact['initials'] }}</span>
                <div class="bk-contact-body">
                    <span class="bk-lb" style="font-size:11px">Appointment for</span>
                    <b>{{ $contact['name'] }}</b>
                    <small>{{ trim($contact['phone'] . ($contact['group'] ? ' · ' . $contact['group'] : ''), ' ·') }}</small>
                </div>
                <a href="{{ $changeUrl }}" class="bk-btn bk-change" data-role="change-contact">Change</a>
            </section>
        @endif

        @if ($contact === null)
            {{-- Customer selector: a plain GET search, so it needs no script and no extra endpoint. --}}
            <section class="bk-card bk-find" data-section="contact-search">
                <span class="bk-lb">Find the customer</span>
                <form method="GET" action="{{ $createUrl }}">
                    @foreach ($carry as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <input type="search" id="q" name="q" class="bk-input" aria-label="Search contacts" placeholder="Name or phone" value="{{ $search }}" autofocus>
                    <button type="submit" class="bk-btn bk-change">Search</button>
                </form>

                @if ($search !== '' && $contacts === [])
                    <p class="bk-sub mb-0" data-role="no-contacts">No matching contacts for this location.</p>
                @endif

                @if ($contacts !== [])
                    <ul class="bk-results" data-role="contact-results">
                        @foreach ($contacts as $found)
                            @php $foundName = $found['name'] ?? 'Unnamed'; @endphp
                            <li>
                                <a class="bk-result" href="{{ $createUrl . '?' . http_build_query($carry + ['contact' => $found['uid']]) }}" data-contact-uid="{{ $found['uid'] }}">
                                    <span class="bk-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($foundName, 0, 1)) }}</span>
                                    <span class="t"><b>{{ $foundName }}</b><small>{{ $found['phone'] }}</small></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($search === '')
                    <p class="bk-sub mb-0">Search by name or phone, then choose the customer.</p>
                @endif
            </section>
        @elseif ($bookingTypes->isEmpty())
            {{-- BookNoTypes — nothing bookable yet. The contact card above stays; no booking form is rendered. --}}
            <div class="bk-empty-wrap">
                <section class="bk-card bk-empty" data-section="no-booking-types">
                    <span class="bk-empty-ic" aria-hidden="true">
                        <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M8 3v4M16 3v4M12 13v4M10 15h4"/></svg>
                    </span>
                    <div class="bk-empty-copy">
                        <b>No appointment types yet</b>
                        <span>Create one — like a 30-minute consultation — and you can book {{ $first }} right after.</span>
                    </div>
                    <a href="{{ $newTypeUrl }}" class="bk-btn bk-primary" data-role="create-booking-type">
                        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Create appointment type
                    </a>
                    <small>You'll come back here with {{ $first }} still selected.</small>
                </section>
            </div>
        @else
            @php
                $zoomAvailable = app(\App\Library\Calendar\Zoom\ZoomConnectionManager::class)->isEnabledFor($business);
                $selectedType = collect($typeCards)->firstWhere('uid', $screen['type']);
                $selectedStaff = $screen['staff'] === 'auto' ? null : $staff->first(fn ($u) => (string) $u->id === $screen['staff']);
                $dateLabel = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $screen['date'])->format('D, M j');
                $timeLabel = $screen['time'] === null ? null : (collect($screen['slots'])->firstWhere('time', $screen['time'])['label'] ?? null);
                $weekFirst = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $screen['days'][0]['date']);
                $weekLast = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $screen['days'][6]['date']);
                $weekLabel = $weekFirst->format('M j') . ' – ' . ($weekFirst->month === $weekLast->month ? $weekLast->format('j') : $weekLast->format('M j'));
                $thisMonday = \Illuminate\Support\Carbon::now($timezone)->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->toDateString();
                $config = [
                    'slotsUrl' => route('customer.workspaces.businesses.calendar.appointments.slots', $scope),
                    'types' => $typeCards,
                    'staff' => $staff->mapWithKeys(fn ($u) => [(string) $u->id => $staffName($u)])->all(),
                    'timezoneLabel' => $timezoneLabel,
                    'thisMonday' => $thisMonday,
                    'state' => ['type' => $screen['type'], 'staff' => $screen['staff'], 'date' => $screen['date'], 'time' => $screen['time'], 'week' => $screen['days'][0]['date']],
                ];
            @endphp

            <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.store', $scope) }}"
                  class="bk-grid" data-section="appointment-form" data-booking-config='@json($config)'>
                @csrf
                <input type="hidden" name="contact_uid" value="{{ $contact['uid'] }}">
                <input type="hidden" name="date" value="{{ $screen['date'] }}" data-field="date">
                <input type="hidden" name="time" value="{{ $screen['time'] }}" data-field="time">

                <div class="bk-main">
                    <section class="bk-card bk-panel" aria-labelledby="bk-type-l">
                        <span class="bk-lb" id="bk-type-l">Appointment type</span>
                        <div class="bk-types" role="radiogroup" aria-labelledby="bk-type-l">
                            @foreach ($typeCards as $card)
                                <label class="bk-ty {{ $card['uid'] === $screen['type'] ? 'on' : '' }}">
                                    <input type="radio" name="booking_type_uid" value="{{ $card['uid'] }}" @checked($card['uid'] === $screen['type'])>
                                    <span class="rd"></span>
                                    <span class="tx"><b>{{ $card['name'] }}</b><small>{{ $card['minutes'] }} min · {{ $card['where'] }}</small></span>
                                </label>
                            @endforeach
                        </div>
                    </section>

                    <section class="bk-card bk-panel" style="gap:14px" aria-labelledby="bk-when-l">
                        <div class="bk-week-head">
                            <span class="bk-lb" id="bk-when-l">Date &amp; time</span>
                            <button type="button" class="bk-nav" data-action="week-prev" aria-label="Previous week" @disabled($screen['days'][0]['date'] <= $thisMonday)>{!! sprintf($chev, 'M14 7l-5 5 5 5') !!}</button>
                            <b data-role="week-label">{{ $weekLabel }}</b>
                            <button type="button" class="bk-nav" data-action="week-next" aria-label="Next week">{!! sprintf($chev, 'M10 7l5 5-5 5') !!}</button>
                        </div>
                        <div class="bk-days" data-role="days">
                            @foreach ($screen['days'] as $day)
                                <button type="button" class="bk-dy {{ $day['date'] === $screen['date'] ? 'on' : '' }}" data-date="{{ $day['date'] }}" @disabled(! $day['available'] && $day['date'] !== $screen['date'])>{{ $day['weekday'] }}<b>{{ $day['day'] }}</b></button>
                            @endforeach
                        </div>
                        <div class="bk-times" data-role="times" aria-live="polite">
                            @forelse ($screen['slots'] as $slot)
                                <button type="button" class="bk-tm {{ $slot['time'] === $screen['time'] ? 'on' : '' }}" data-time="{{ $slot['time'] }}" data-label="{{ $slot['label'] }}">{{ $slot['label'] }}</button>
                            @empty
                                <div class="bk-times-empty">{{ $screen['hasStaff'] ? 'No open times on this day. Pick another day.' : 'Nobody is assigned to this appointment type yet, so there is nothing to book.' }}</div>
                            @endforelse
                        </div>
                        <div class="bk-tz">
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>
                            Times in {{ $timezoneLabel }}
                        </div>
                    </section>

                    <section class="bk-card bk-panel bk-with">
                        <div class="bk-field">
                            <label class="bk-lb" for="staff">With</label>
                            <select id="staff" name="staff" class="bk-select" required>
                                <option value="auto" @selected($screen['staff'] === 'auto')>Assign automatically</option>
                                @foreach ($staff as $member)
                                    <option value="{{ $member->id }}" @selected($screen['staff'] === (string) $member->id)>{{ $staffName($member) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="bk-field" data-section="meeting-location" style="flex:2 1 300px">
                            <details class="bk-more" @if (old('meeting_location_type')) open @endif>
                                <summary>
                                    <svg class="ic" viewBox="0 0 24 24" aria-hidden="true" style="width:14px;height:14px"><path d="M7 10l5 5 5-5"/></svg>
                                    Change where it happens
                                </summary>
                                <div class="bk-more-body">
                                    <select id="meeting_location_type" name="meeting_location_type" class="bk-select" aria-label="Meeting location">
                                        <option value="" @selected(! old('meeting_location_type'))>Appointment type default</option>
                                        @if ($zoomAvailable)
                                            <option value="zoom" @selected(old('meeting_location_type') === 'zoom')>Zoom meeting</option>
                                        @endif
                                        <option value="in_person" @selected(old('meeting_location_type') === 'in_person')>In person</option>
                                        <option value="phone" @selected(old('meeting_location_type') === 'phone')>Phone call</option>
                                        <option value="custom" @selected(old('meeting_location_type') === 'custom')>Custom meeting link or location</option>
                                    </select>
                                    <input type="text" name="meeting_location_custom" class="bk-input" maxlength="500" placeholder="A meeting link or an address (for a custom location)" value="{{ old('meeting_location_custom') }}">
                                    @if ($zoomAvailable)
                                        <small style="font-size:12.5px;color:#8A817C">Zoom creates a unique meeting for this appointment automatically. The host must have connected Zoom.</small>
                                    @endif
                                </div>
                            </details>
                        </div>
                    </section>
                </div>

                <aside class="bk-card bk-summary" data-section="booking-summary" aria-live="polite">
                    <span class="bk-lb" style="margin-bottom:6px">Summary</span>
                    <b class="when" data-sum="when">{{ $timeLabel === null ? 'Pick a time' : $dateLabel . ' · ' . $timeLabel }}</b>
                    <span class="what" data-sum="what">{{ $selectedType['name'] }} · {{ $selectedType['minutes'] }} min</span>
                    <div class="bk-sr"><span>Customer</span><b>{{ $contact['name'] }}</b></div>
                    <div class="bk-sr"><span>With</span><b data-sum="with">{{ $selectedStaff ? $staffName($selectedStaff) : 'Assigned automatically' }}</b></div>
                    <div class="bk-sr"><span>Where</span><b data-sum="where">{{ $selectedType['where'] }}</b></div>
                    <div class="bk-sr"><span>Time zone</span><b>{{ $timezoneLabel }}</b></div>
                    <button type="submit" class="bk-btn bk-submit" data-role="book-appointment" @disabled($timeLabel === null)>Book appointment</button>
                    <p class="bk-hint" data-role="book-hint" @if ($timeLabel !== null) hidden @endif>Choose a day and time to book.</p>
                </aside>
            </form>

        @endif
    </div>
@endsection

@if ($screen !== null)
    @section('page-script')
        @include('customer.business.calendar._book-script')
    @endsection
@endif
