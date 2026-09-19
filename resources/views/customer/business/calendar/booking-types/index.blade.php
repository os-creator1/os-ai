@extends('layouts/contentLayoutMaster')

@section('title', 'Booking types')

@section('content')
    @php
        // Implementation Contract 15 §5.1 / §12.B — presentation only. Every
        // authorization answer was decided before this view rendered; nothing
        // here re-derives one, and no control is shown that the write path
        // would refuse.
        $scope = [$workspace->uid, $business->uid, $location->uid];
    @endphp

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Booking types</h4>
            <p class="card-text text-muted">
                What customers can book at <strong>{{ $location->name ?: 'this location' }}</strong>, and how long each takes.
                Booking types belong to one location — they are never shared between locations.
            </p>

            <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.create', $scope) }}" class="btn btn-primary mb-2">
                Add booking type
            </a>

            @if ($bookingTypes->isEmpty())
                <p class="mb-0">No booking types yet. Add one to describe what can be booked here.</p>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bookingTypes as $bookingType)
                                <tr>
                                    <td>{{ $bookingType->name }}</td>
                                    <td>{{ $bookingType->duration_minutes }} min</td>
                                    <td>{{ $bookingType->is_active ? 'Active' : 'Inactive' }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.edit', array_merge($scope, [$bookingType->uid])) }}">
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <a href="{{ route('customer.workspaces.businesses.calendar.availability.index', $scope) }}">
                Staff availability at this location
            </a>
        </div>
    </div>
@endsection
