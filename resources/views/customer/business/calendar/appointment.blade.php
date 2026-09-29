@extends('layouts/contentLayoutMaster')

@section('title', 'Appointment')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

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
        $statusVariant = match ($appointment->status->value) {
            'completed' => 'success', 'cancelled' => 'neutral', 'no_show' => 'danger', default => 'accent',
        };
    @endphp

    <a href="{{ route('customer.workspaces.businesses.calendar.schedule', $scope) }}?date={{ $startLocal->toDateString() }}"
       class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
        <x-ds-icon name="chevron-left" size="16" aria-hidden="true" />
        Back to calendar
    </a>

    <section class="mb-2">
        <h1 class="text-page-title mb-25">{{ $appointment->bookingType?->name ?? 'Appointment' }}</h1>
        <p class="text-caption mb-0">
            {{ $startLocal->format('l j F Y, H:i') }}–{{ $endLocal->format('H:i') }} ({{ $timezone }})
            · <x-badge :variant="$statusVariant" data-role="appointment-status">{{ $statusLabels[$appointment->status->value] ?? $appointment->status->value }}</x-badge>
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

    <x-card title="Details" class="mb-2" data-section="appointment-details">
        <dl class="row mb-0">
            <dt class="col-sm-3 text-label">Location</dt>
            <dd class="col-sm-9">{{ $location->name ?: 'This location' }}</dd>
            <dt class="col-sm-3 text-label">Customer</dt>
            <dd class="col-sm-9">{{ $contact['name'] ?? $contact['phone'] ?? '—' }}</dd>
            <dt class="col-sm-3 text-label">Staff</dt>
            <dd class="col-sm-9">{{ $appointment->staff ? $staffName($appointment->staff) : '—' }}</dd>
            <dt class="col-sm-3 text-label">Rescheduled</dt>
            <dd class="col-sm-9">{{ (int) $appointment->reschedule_count }} time(s)</dd>
            @if ($appointment->cancellation_reason)
                <dt class="col-sm-3 text-label">Cancellation reason</dt>
                <dd class="col-sm-9">{{ $appointment->cancellation_reason }}</dd>
            @endif
        </dl>
    </x-card>

    @if ($isScheduled)
        <x-card title="Reschedule" class="mb-2" data-section="appointment-reschedule">
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
                <x-button type="submit" variant="primary" data-role="reschedule">Reschedule</x-button>
            </form>
        </x-card>

        <x-card title="Outcome" class="mb-2" data-section="appointment-outcome">
            <div class="d-flex flex-wrap align-items-center" style="gap: .5rem;">
                <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.complete', $withAppointment) }}">
                    @csrf
                    <x-button type="submit" variant="primary" size="sm" icon="check" data-role="complete">Mark completed</x-button>
                </form>

                <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.no-show', $withAppointment) }}">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm" icon="clock-alert" data-role="no-show">Mark no-show</x-button>
                </form>
            </div>

            <hr>

            <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.cancel', $withAppointment) }}">
                @csrf
                <div class="form-group">
                    <label for="reason">Cancellation reason (optional)</label>
                    <input type="text" id="reason" name="reason" class="form-control" maxlength="255" value="{{ old('reason') }}">
                </div>
                <x-button type="submit" variant="danger" size="sm" icon="x" data-role="cancel">Cancel appointment</x-button>
            </form>
        </x-card>
    @else
        <p class="text-caption" data-role="appointment-closed">
            This appointment is {{ strtolower($statusLabels[$appointment->status->value] ?? $appointment->status->value) }} and can no longer be changed.
        </p>
    @endif
@endsection
