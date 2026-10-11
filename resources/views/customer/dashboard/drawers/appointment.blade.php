{{--
    Home V2 drawer — Book an appointment.

    The booking itself is appointments.store → AppointmentBookingService: the
    engine decides availability, conflicts, staff eligibility and the
    immutable Location. This form only gathers the same five inputs the module
    form does, and a refusal ("that time is no longer free") is shown as the
    engine gave it. Contacts are found by search within this Location's
    bookable set; nobody is created here.
--}}
@php $fragment = $fragmentUrl; @endphp
<div data-role="drawer-appointment">
    @if(count($locations) > 1)
        <form class="mb-1" data-home-search data-fragment="{{ $fragment }}" action="{{ $fragment }}" method="GET">
            <label for="home-appt-location">Location</label>
            <div class="d-flex gap-50">
                <select id="home-appt-location" name="location" class="form-select">
                    @foreach($locations as $l)
                        <option value="{{ $l->uid }}" @selected($l->id === $location->id)>{{ $l->name ?: 'Main location' }}</option>
                    @endforeach
                </select>
                <x-button type="submit" variant="secondary" size="sm">Switch</x-button>
            </div>
        </form>
    @endif

    @if($bookingTypes->isEmpty())
        <p class="mb-0" data-role="drawer-empty">There is no active booking type at this location yet. Create one first, then book against it.</p>
    @else
        <form class="mb-1" data-home-search data-fragment="{{ $fragment }}" action="{{ $fragment }}" method="GET">
            <input type="hidden" name="location" value="{{ $location->uid }}">
            <label for="home-appt-search">Find the customer</label>
            <div class="d-flex gap-50">
                <input type="search" id="home-appt-search" name="q" class="form-control" value="{{ $search }}" placeholder="Name or phone number" autocomplete="off">
                <x-button type="submit" variant="secondary" size="sm">Search</x-button>
            </div>
        </form>

        <form class="home-drawer-form" data-home-form="appointment" action="{{ $storeUrl }}" method="POST">
            @csrf

            <fieldset class="mb-1">
                <legend class="h6 text-label">Customer</legend>
                @if($search === '')
                    <p class="text-caption text-muted mb-0">Search above to choose who this is for.</p>
                @elseif($contacts === [])
                    <p class="text-caption mb-0" data-role="appointment-no-contact">No existing contact matches "{{ $search }}" at this location.</p>
                @else
                    @foreach($contacts as $c)
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="contact_uid" id="home-contact-{{ $loop->index }}" value="{{ $c['uid'] }}" @checked($loop->first) required>
                            <label class="form-check-label" for="home-contact-{{ $loop->index }}">{{ $c['name'] ?: $c['phone'] }} @if($c['name'])<span class="text-muted">· {{ $c['phone'] }}</span>@endif</label>
                        </div>
                    @endforeach
                @endif
            </fieldset>

            <div class="mb-1">
                <label for="home-appt-type">Booking type</label>
                <select id="home-appt-type" name="booking_type_uid" class="form-select" required>
                    @foreach($bookingTypes as $t)
                        <option value="{{ $t->uid }}">{{ $t->name }} ({{ $t->duration_minutes }} min)</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-1">
                <label for="home-appt-staff">Who</label>
                <select id="home-appt-staff" name="staff" class="form-select" required>
                    <option value="auto">Whoever is free</option>
                    @foreach($staff as $s)
                        <option value="{{ $s->id }}">{{ trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')) ?: $s->email }}</option>
                    @endforeach
                </select>
            </div>

            <div class="row g-1 mb-1">
                <div class="col-7">
                    <label for="home-appt-date">Date</label>
                    <input type="date" id="home-appt-date" name="date" class="form-control" min="{{ $today }}" value="{{ $today }}" required>
                </div>
                <div class="col-5">
                    <label for="home-appt-time">Time</label>
                    <input type="time" id="home-appt-time" name="time" class="form-control" value="09:00" required>
                </div>
            </div>
            <p class="text-caption text-muted">Times are in {{ $timezone }}. The booking is checked against availability when you confirm.</p>

            <x-button type="submit" data-role="appointment-submit" :disabled="$contacts === []">Book appointment</x-button>
        </form>
    @endif
</div>
