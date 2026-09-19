@extends('layouts/contentLayoutMaster')

@section('title', 'Add booking type')

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid, $location->uid];
    @endphp

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Add booking type</h4>
            <p class="card-text text-muted">
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

                <button type="submit" class="btn btn-primary">Create booking type</button>
                <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.index', $scope) }}" class="btn btn-light">Cancel</a>
            </form>
        </div>
    </div>
@endsection
