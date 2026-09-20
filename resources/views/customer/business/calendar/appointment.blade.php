@extends('layouts/contentLayoutMaster')

@section('title', 'Appointment')

@section('content')
    @php
        // Implementation Contract 15 §12.D — presentation only. The lifecycle
        // controls are offered only while the appointment is `scheduled`, because
        // that is the one state §7.4 lets any transition start from. The engine
        // re-reads the status under its own lock regardless, so hiding a control
        // here is a courtesy and never the guard.
        $scope = [$workspace->uid, $business->uid, $location->uid];
        $withAppointment = array_merge($scope, [$appointment->uid]);
        $staffName = static fn ($user) => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? ('User #' . $user->id));
        $isScheduled = $appointment->status === \App\Enums\Calendar\AppointmentStatus::Scheduled;
        $statusLabels = ['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled', 'completed' => 'Completed', 'no_show' => 'No-show'];
    @endphp

    <section class="mb-2">
        <h1 class="h3 mb-25">{{ $appointment->bookingType?->name ?? 'Appointment' }}</h1>
        <p class="text-body mb-0">
            {{ $startLocal->format('l j F Y, H:i') }}–{{ $endLocal->format('H:i') }} ({{ $timezone }})
            · <span data-role="appointment-status">{{ $statusLabels[$appointment->status->value] ?? $appointment->status->value }}</span>
        </p>
    </section>

    @foreach (['flash_success' => 'success', 'flash_error' => 'danger'] as $key => $variant)
        @if (session($key))
            <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
        @endif
    @endforeach

    @if ($errors->any())
        <x-alert variant="danger" class="mb-2" role="alert">{{ $errors->first() }}</x-alert>
    @endif

    <div class="card" data-section="appointment-details">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Location</dt>
                <dd class="col-sm-9">{{ $location->name ?: 'This location' }}</dd>
                <dt class="col-sm-3">Customer</dt>
                <dd class="col-sm-9">{{ $contact['name'] ?? $contact['phone'] ?? '—' }}</dd>
                <dt class="col-sm-3">Staff</dt>
                <dd class="col-sm-9">{{ $appointment->staff ? $staffName($appointment->staff) : '—' }}</dd>
                <dt class="col-sm-3">Rescheduled</dt>
                <dd class="col-sm-9">{{ (int) $appointment->reschedule_count }} time(s)</dd>
                @if ($appointment->cancellation_reason)
                    <dt class="col-sm-3">Cancellation reason</dt>
                    <dd class="col-sm-9">{{ $appointment->cancellation_reason }}</dd>
                @endif
            </dl>
        </div>
    </div>

    @if ($isScheduled)
        <div class="card" data-section="appointment-reschedule">
            <div class="card-body">
                <h2 class="h5">Reschedule</h2>
                <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.reschedule', $withAppointment) }}">
                    @csrf
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="date">New date</label>
                            <input type="date" id="date" name="date" class="form-control" value="{{ old('date', $startLocal->toDateString()) }}" required>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="time">New start time</label>
                            <input type="time" id="time" name="time" class="form-control" value="{{ old('time', $startLocal->format('H:i')) }}" required>
                        </div>
                        <div class="form-group col-md-5">
                            <label for="staff">Staff</label>
                            <select id="staff" name="staff" class="form-control">
                                @foreach ($staff as $member)
                                    <option value="{{ $member->id }}"
                                            @selected((int) old('staff', $appointment->staff_user_id) === (int) $member->id)>{{ $staffName($member) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" data-role="reschedule">Reschedule</button>
                </form>
            </div>
        </div>

        <div class="card" data-section="appointment-outcome">
            <div class="card-body">
                <h2 class="h5">Outcome</h2>

                <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.complete', $withAppointment) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-success" data-role="complete">Mark completed</button>
                </form>

                <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.no-show', $withAppointment) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-warning" data-role="no-show">Mark no-show</button>
                </form>

                <hr>

                <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.cancel', $withAppointment) }}">
                    @csrf
                    <div class="form-group">
                        <label for="reason">Cancellation reason (optional)</label>
                        <input type="text" id="reason" name="reason" class="form-control" maxlength="255" value="{{ old('reason') }}">
                    </div>
                    <button type="submit" class="btn btn-outline-danger" data-role="cancel">Cancel appointment</button>
                </form>
            </div>
        </div>
    @else
        <p class="text-muted" data-role="appointment-closed">
            This appointment is {{ strtolower($statusLabels[$appointment->status->value] ?? $appointment->status->value) }} and can no longer be changed.
        </p>
    @endif

    <a href="{{ route('customer.workspaces.businesses.calendar.schedule', $scope) }}?date={{ $startLocal->toDateString() }}">Back to calendar</a>
@endsection
