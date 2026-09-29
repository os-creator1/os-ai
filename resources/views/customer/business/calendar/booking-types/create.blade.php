@extends('layouts/contentLayoutMaster')

@section('title', 'Add booking type')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid, $location->uid];
    @endphp

    <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.index', $scope) }}"
       class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
        <x-ds-icon name="chevron-left" size="16" aria-hidden="true" />
        Back to booking types
    </a>

    <x-card title="Add booking type">
        <p class="text-caption">
            This booking type will belong to <strong>{{ $location->name ?: 'this location' }}</strong> only.
        </p>

        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.booking-types.store', $scope) }}">
            @csrf

            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" class="form-control" maxlength="120" value="{{ old('name') }}" required>
            </div>

            <div class="form-group">
                <label for="duration_minutes">Duration (minutes)</label>
                <input type="number" id="duration_minutes" name="duration_minutes" class="form-control" min="1" max="1440" value="{{ old('duration_minutes', 30) }}" required>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
            </div>

            <div class="form-group">
                <label for="color">Colour</label>
                <input type="text" id="color" name="color" class="form-control" maxlength="16" value="{{ old('color') }}">
            </div>

            <div class="d-flex" style="gap: .5rem;">
                <x-button type="submit" variant="primary" icon="check">Create booking type</x-button>
                <x-button variant="secondary" :href="route('customer.workspaces.businesses.calendar.booking-types.index', $scope)">Cancel</x-button>
            </div>
        </form>
    </x-card>
@endsection
