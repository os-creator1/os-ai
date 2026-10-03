@extends('layouts/contentLayoutMaster')

@section('title', 'Booking types')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

@section('content')
    @php
        // Implementation Contract 15 §5.1 / §12.B — presentation only. Every
        // authorization answer was decided before this view rendered; nothing
        // here re-derives one, and no control is shown that the write path
        // would refuse.
        $scope = [$workspace->uid, $business->uid, $location->uid];
    @endphp

    @include('customer.business.calendar._module-nav', ['active' => 'booking-types'])

    <x-card title="Booking types" data-section="booking-types-list">
        <x-slot name="actions">
            <x-button variant="primary" size="sm" icon="plus" :href="route('customer.workspaces.businesses.calendar.booking-types.create', $scope)" data-role="add-booking-type">
                Add booking type
            </x-button>
        </x-slot>

        <p class="text-caption">
            What customers can book at <strong>{{ $location->name ?: 'this location' }}</strong>, and how long each takes.
            Booking types belong to one location — they are never shared between locations.
        </p>

        @if ($bookingTypes->isEmpty())
            <x-empty-state icon="tag" title="No booking types yet." description="Add one to describe what can be booked here.">
                <x-slot name="action">
                    <x-button variant="primary" icon="plus" :href="route('customer.workspaces.businesses.calendar.booking-types.create', $scope)">Add booking type</x-button>
                </x-slot>
            </x-empty-state>
        @else
            <div class="table-responsive">
                <table class="table" data-role="booking-types-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Public booking link</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookingTypes as $bookingType)
                            <tr data-booking-type="{{ $bookingType->uid }}">
                                <td class="text-label">{{ $bookingType->name }}</td>
                                <td>{{ $bookingType->duration_minutes }} min</td>
                                <td>
                                    <x-badge :variant="$bookingType->is_active ? 'success' : 'neutral'">
                                        {{ $bookingType->is_active ? 'Active' : 'Inactive' }}
                                    </x-badge>
                                </td>
                                <td>
                                    @if ($bookingType->is_active && ! ($readiness[$bookingType->id]['ready'] ?? false))
                                        <span class="text-caption" data-role="booking-link-unavailable">
                                            Not bookable yet. {{ $readiness[$bookingType->id]['reason'] ?? '' }}
                                        </span>
                                    @elseif ($bookingType->is_active)
                                        <a href="{{ route('public.booking.show', [$bookingType->public_booking_uuid]) }}" target="_blank" rel="noopener"
                                           class="d-inline-flex align-items-center gap-1 mb-1">
                                            Open booking page
                                            <x-ds-icon name="external-link" size="13" aria-hidden="true" />
                                        </a>
                                        <input class="form-control form-control-sm" type="text" readonly
                                               aria-label="Public booking link for {{ $bookingType->name }}"
                                               value="{{ route('public.booking.show', [$bookingType->public_booking_uuid]) }}">
                                    @endif
                                </td>
                                <td class="text-right">
                                    <x-button variant="secondary" size="sm" icon="pencil"
                                              :href="route('customer.workspaces.businesses.calendar.booking-types.edit', array_merge($scope, [$bookingType->uid]))">
                                        Edit
                                    </x-button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
@endsection
