@extends('layouts/contentLayoutMaster')

@section('title', 'Edit booking type')

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

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">{{ $bookingType->name }}</h4>
            <p class="card-text text-muted">
                At <strong>{{ $location->name ?: 'this location' }}</strong>.
            </p>

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

                <button type="submit" class="btn btn-primary">Save changes</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Status</h4>

            <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.booking-types.active', $withType) }}">
                @csrf
                <input type="hidden" name="is_active" value="{{ $bookingType->is_active ? 0 : 1 }}">
                <p class="card-text">
                    This booking type is currently <strong>{{ $bookingType->is_active ? 'active' : 'inactive' }}</strong>.
                </p>
                <button type="submit" class="btn btn-light">
                    {{ $bookingType->is_active ? 'Deactivate' : 'Activate' }}
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Who offers this</h4>
            <p class="card-text text-muted">
                Only people currently authorized for this location can be added. Removing someone's access elsewhere
                takes effect immediately — a name left here never restores it.
            </p>

            @php
                $staleStaff = collect($configuredStaff)->filter(static fn (array $row) => ! $row['eligible']);
            @endphp

            @if ($staleStaff->isNotEmpty())
                <div class="alert alert-warning">
                    <div class="alert-body">
                        {{ $staleStaff->count() }} person(s) listed here are no longer authorized for this location and
                        cannot be scheduled. Save this form to clear them.
                    </div>
                </div>
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
                    <p>Nobody is currently authorized for this location.</p>
                @endforelse

                <button type="submit" class="btn btn-primary mt-1">Save staff</button>
            </form>
        </div>
    </div>

    <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.index', $scope) }}">Back to booking types</a>
@endsection
