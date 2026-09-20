@extends('layouts/contentLayoutMaster')

@section('title', 'New appointment')

@section('content')
    @php
        // Implementation Contract 15 §12.D — presentation only. Submitting does
        // not book anything by itself: the server re-checks tenancy, entitlement,
        // Location access, Contact and Booking Type reach, and then hands the
        // request to AppointmentBookingService, which re-derives staff
        // eligibility and availability and takes the locks (§7).
        $scope = [$workspace->uid, $business->uid, $location->uid];
        $staffName = static fn ($user) => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? ('User #' . $user->id));
        $selectedContact = old('contact_uid');
    @endphp

    <section class="mb-2">
        <h1 class="h3 mb-25">New appointment</h1>
        <p class="text-body mb-0">At <strong>{{ $location->name ?: 'this location' }}</strong> · times in {{ $timezone }}</p>
    </section>

    @foreach (['flash_success' => 'success', 'flash_error' => 'danger'] as $key => $variant)
        @if (session($key))
            <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
        @endif
    @endforeach

    @if ($errors->any())
        <x-alert variant="danger" class="mb-2" role="alert">{{ $errors->first() }}</x-alert>
    @endif

    {{-- Contact search is a plain GET so it needs no script and adds no separate endpoint. --}}
    <div class="card" data-section="contact-search">
        <div class="card-body">
            <h2 class="h5">1. Find the customer</h2>
            <form method="GET" action="{{ route('customer.workspaces.businesses.calendar.appointments.create', $scope) }}" class="form-inline mb-1">
                <input type="hidden" name="date" value="{{ $prefillDate }}">
                <input type="hidden" name="time" value="{{ $prefillTime }}">
                <label class="sr-only" for="q">Search contacts</label>
                <input type="search" id="q" name="q" class="form-control mr-1" placeholder="Name or phone" value="{{ $search }}">
                <button type="submit" class="btn btn-outline-primary">Search</button>
            </form>
            @if ($search !== '' && $contacts === [])
                <p class="mb-0" data-role="no-contacts">No matching contacts for this location.</p>
            @endif
        </div>
    </div>

    <div class="card" data-section="appointment-form">
        <div class="card-body">
            <h2 class="h5">2. Book</h2>

            @if ($bookingTypes->isEmpty())
                <p class="mb-0">There are no active booking types at this location yet.
                    <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.create', $scope) }}">Add one</a>.</p>
            @else
                <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.store', $scope) }}">
                    @csrf

                    <div class="form-group">
                        <label>Customer</label>
                        @forelse ($contacts as $contact)
                            <div class="custom-control custom-radio">
                                <input type="radio" class="custom-control-input" id="contact-{{ $contact['uid'] }}"
                                       name="contact_uid" value="{{ $contact['uid'] }}" required
                                       @checked($selectedContact === $contact['uid'])>
                                <label class="custom-control-label" for="contact-{{ $contact['uid'] }}">
                                    {{ $contact['name'] ?? 'Unnamed' }} · {{ $contact['phone'] }}
                                </label>
                            </div>
                        @empty
                            <p class="text-muted mb-0">Search above, then choose a customer.</p>
                        @endforelse
                    </div>

                    <div class="form-group">
                        <label for="booking_type_uid">What</label>
                        <select id="booking_type_uid" name="booking_type_uid" class="form-control" required>
                            @foreach ($bookingTypes as $type)
                                <option value="{{ $type->uid }}" @selected(old('booking_type_uid') === $type->uid)>
                                    {{ $type->name }} ({{ $type->duration_minutes }} min)
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="staff">Who</label>
                        <select id="staff" name="staff" class="form-control" required>
                            <option value="auto" @selected(old('staff', 'auto') === 'auto')>Assign automatically (round-robin)</option>
                            @foreach ($staff as $member)
                                <option value="{{ $member->id }}" @selected((string) old('staff') === (string) $member->id)>{{ $staffName($member) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="date">Date</label>
                            <input type="date" id="date" name="date" class="form-control" value="{{ old('date', $prefillDate) }}" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="time">Start time</label>
                            <input type="time" id="time" name="time" class="form-control" value="{{ old('time', $prefillTime) }}" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" data-role="book-appointment">Book appointment</button>
                    <a class="btn btn-light" href="{{ route('customer.workspaces.businesses.calendar.schedule', $scope) }}?date={{ $prefillDate }}">Cancel</a>
                </form>
            @endif
        </div>
    </div>
@endsection
