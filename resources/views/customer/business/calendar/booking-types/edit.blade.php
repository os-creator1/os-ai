@extends('layouts/contentLayoutMaster')

@section('title', 'Edit booking type')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

@section('content')
    @php
        // Implementation Contract 15 §5.1 / §6 — the staff list below is
        // CONFIGURATION INTENT, never authorization. A nominated staff member
        // who is no longer authorized for this location is shown as such and
        // is not silently treated as bookable; the write path re-derives
        // eligibility again on every save.
        $scope = [$workspace->uid, $business->uid, $location->uid];
        $withType = array_merge($scope, [$bookingType->uid]);
        $staffName = static fn ($user) => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? ('User #' . $user->id));
        $configuredIds = collect($configuredStaff)->pluck('user.id')->map(static fn ($id) => (int) $id)->all();
    @endphp

    <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.index', $scope) }}"
       class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
        <x-ds-icon name="chevron-left" size="16" aria-hidden="true" />
        Back to booking types
    </a>

    <section class="mb-2">
        <h1 class="text-page-title mb-25">{{ $bookingType->name }}</h1>
        <p class="text-caption mb-0">At <strong>{{ $location->name ?: 'this location' }}</strong></p>
    </section>

    <x-card title="Details" class="mb-2">
        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.booking-types.update', $withType) }}">
            @csrf

            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" class="form-control" maxlength="120" value="{{ old('name', $bookingType->name) }}" required>
            </div>

            <div class="form-group">
                <label for="duration_minutes">Duration (minutes)</label>
                <input type="number" id="duration_minutes" name="duration_minutes" class="form-control" min="1" max="1440" value="{{ old('duration_minutes', $bookingType->duration_minutes) }}" required>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="3">{{ old('description', $bookingType->description) }}</textarea>
            </div>

            <div class="form-group">
                <label for="color">Colour</label>
                <input type="text" id="color" name="color" class="form-control" maxlength="16" value="{{ old('color', $bookingType->color) }}">
            </div>

            <x-button type="submit" variant="primary" icon="check">Save changes</x-button>
        </form>
    </x-card>

    <x-card title="Public booking page" class="mb-2" data-section="public-booking-page">
        @if ($readiness['ready'])
            <p class="text-caption">Share this link. Customers pick a date and time, enter their details and are booked into the calendar.</p>
            <a href="{{ route('public.booking.show', [$bookingType->public_booking_uuid]) }}" target="_blank" rel="noopener"
               class="d-inline-flex align-items-center gap-1 mb-1" data-role="open-public-page">
                Open booking page
                <x-ds-icon name="external-link" size="13" aria-hidden="true" />
            </a>
            <input class="form-control form-control-sm" type="text" readonly
                   aria-label="Public booking link for {{ $bookingType->name }}"
                   value="{{ route('public.booking.show', [$bookingType->public_booking_uuid]) }}">
        @else
            <x-alert variant="warning" class="mb-1" data-role="public-page-not-ready">
                Customers cannot book this yet. {{ $readiness['reason'] }}
            </x-alert>
        @endif
        <p class="text-caption mt-1 mb-0">
            Times start every 30 minutes within each person's working hours, from now up to 30 days ahead,
            and are shown in the customer's time zone. Appointments are held at
            <strong>{{ $location->name ?: 'this location' }}</strong>.
        </p>
    </x-card>

    <x-card title="Status" class="mb-2">
        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.booking-types.active', $withType) }}">
            @csrf
            <input type="hidden" name="is_active" value="{{ $bookingType->is_active ? 0 : 1 }}">
            <p class="mb-2">
                This booking type is currently
                <x-badge :variant="$bookingType->is_active ? 'success' : 'neutral'">{{ $bookingType->is_active ? 'Active' : 'Inactive' }}</x-badge>
            </p>
            <x-button type="submit" variant="secondary" size="sm">
                {{ $bookingType->is_active ? 'Deactivate' : 'Activate' }}
            </x-button>
        </form>
    </x-card>

    <x-card title="Who offers this" class="mb-2">
        <p class="text-caption">
            Only people currently authorized for this location can be added. Removing someone's access elsewhere
            takes effect immediately — a name left here never restores it.
        </p>

        @php
            $staleStaff = collect($configuredStaff)->filter(static fn (array $row) => ! $row['eligible']);
        @endphp

        @if ($staleStaff->isNotEmpty())
            <x-alert variant="warning" class="mb-2">
                {{ $staleStaff->count() }} person(s) listed here are no longer authorized for this location and
                cannot be scheduled. Save this form to clear them.
            </x-alert>
        @endif

        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.booking-types.staff', $withType) }}">
            @csrf
            <input type="hidden" name="staff_user_ids[]" value="">

            @forelse ($eligibleStaff as $candidate)
                <div class="custom-control custom-checkbox mb-1">
                    <input type="checkbox"
                           class="custom-control-input"
                           id="staff-{{ $candidate->id }}"
                           name="staff_user_ids[]"
                           value="{{ $candidate->id }}"
                           @checked(in_array((int) $candidate->id, $configuredIds, true))>
                    <label class="custom-control-label" for="staff-{{ $candidate->id }}">{{ $staffName($candidate) }}</label>
                </div>
            @empty
                <p class="text-caption">Nobody is currently authorized for this location.</p>
            @endforelse

            <x-button type="submit" variant="primary" class="mt-1" icon="check">Save staff</x-button>
        </form>
    </x-card>
@endsection
