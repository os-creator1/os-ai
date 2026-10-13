@extends('layouts/contentLayoutMaster')

@section('title', 'Appointment')

@section('page-style')
    @include('customer.business.calendar._styles')
    <style>
        .mg-appt{max-width:68rem}
        .mg-appt-back{display:inline-flex;align-items:center;gap:.25rem;margin-bottom:1rem;font-size:.875rem;color:var(--color-text-muted)}
        .mg-appt-head{display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem}
        .mg-appt-tile{flex:none;display:flex;flex-direction:column;align-items:center;justify-content:center;width:4.5rem;padding:.5rem 0;border:1px solid var(--color-border-neutral,#e5e5e5);border-radius:.75rem;background:var(--color-surface,#fff);line-height:1.1}
        .mg-appt-tile__month{font-size:.7rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--color-primary)}
        .mg-appt-tile__day{font-size:1.6rem;font-weight:700}
        .mg-appt-tile__weekday{font-size:.7rem;color:var(--color-text-muted)}
        .mg-appt-head__title{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin:0 0 .25rem}
        .mg-appt-when{margin:0;color:var(--color-text-muted);font-size:.9rem}
        .mg-appt-grid{display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:1rem;align-items:start}
        .mg-appt-col{display:flex;flex-direction:column;gap:1rem;min-width:0}
        .mg-appt-grid.is-single{grid-template-columns:minmax(0,1fr)}
        .mg-appt-details{display:grid;grid-template-columns:8rem minmax(0,1fr);gap:.6rem 1rem;margin:0}
        .mg-appt-details dt{font-weight:500;color:var(--color-text-muted)}
        .mg-appt-details dd{margin:0;overflow-wrap:anywhere}
        .mg-appt-resched{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin-bottom:1rem}
        .mg-appt-resched .form-control{min-width:0;width:100%}
        .mg-appt-outcome{display:flex;flex-direction:column;gap:.5rem}
        .mg-appt-outcome .btn{width:100%}
        .mg-appt-danger{border-color:var(--color-danger,#c0392b)!important}
        .mg-appt-danger .card-title,.mg-appt-danger h2,.mg-appt-danger h3,.mg-appt-danger h4{color:var(--color-danger,#c0392b)}
        .mg-appt-banner{display:flex;gap:.75rem;align-items:flex-start;padding:.875rem 1rem;margin-bottom:1rem;border:1px solid var(--color-border-neutral,#e5e5e5);border-radius:.75rem;background:var(--color-surface-muted,#f6f5f2)}
        .mg-appt-banner.is-cancelled{border-color:#e7bcb7;background:#fbefed}
        .mg-appt-closed{margin:1rem 0 0;color:var(--color-text-muted)}
        @media (max-width:767.98px){
            .mg-appt-grid{grid-template-columns:minmax(0,1fr)}
            .mg-appt-resched{grid-template-columns:minmax(0,1fr)}
            .mg-appt-details{grid-template-columns:minmax(0,1fr);gap:.1rem}
            .mg-appt-details dd{margin-bottom:.6rem}
            .mg-appt-head{align-items:flex-start}
        }
    </style>
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

    @php
        $contactUrl = null;
        if (! empty($contact['uid']) && \Illuminate\Support\Facades\Gate::allows('view_contact')
            && \Illuminate\Support\Facades\Route::has('customer.workspaces.businesses.people.show')) {
            $contactUrl = route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact['uid']]);
        }
        $statusText = $statusLabels[$appointment->status->value] ?? $appointment->status->value;
    @endphp

    <div class="mg-appt" data-role="appointment-page">
        <a href="{{ route('customer.workspaces.businesses.calendar.schedule', $scope) }}?date={{ $startLocal->toDateString() }}"
           class="mg-appt-back" data-role="appointment-back">
            <x-ds-icon name="chevron-left" size="16" aria-hidden="true" />
            Back to calendar
        </a>

        <section class="mg-appt-head">
            <div class="mg-appt-tile" data-role="appointment-date-tile" aria-hidden="true">
                <span class="mg-appt-tile__month">{{ $startLocal->format('M') }}</span>
                <span class="mg-appt-tile__day">{{ $startLocal->format('j') }}</span>
                <span class="mg-appt-tile__weekday">{{ $startLocal->format('D') }}</span>
            </div>
            <div>
                <h1 class="text-page-title mg-appt-head__title">
                    {{ $appointment->bookingType?->name ?? 'Appointment' }}
                    <x-badge :variant="$statusVariant" data-role="appointment-status">{{ $statusText }}</x-badge>
                </h1>
                <p class="mg-appt-when">
                    {{ $startLocal->format('l j F Y, H:i') }}–{{ $endLocal->format('H:i') }} ({{ $timezone }})
                </p>
            </div>
        </section>

        @foreach (['flash_success' => 'success', 'flash_error' => 'danger'] as $key => $variant)
            @if (session($key))
                <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
            @endif
        @endforeach

        @if ($errors->any())
            <x-alert variant="danger" class="mb-2" role="alert">{{ $errors->first() }}</x-alert>
        @endif

        @if (! $isScheduled && $appointment->cancellation_reason)
            <div class="mg-appt-banner is-cancelled" data-role="appointment-cancellation-banner">
                <x-ds-icon name="circle-alert" size="18" aria-hidden="true" />
                <div><strong>Cancellation reason</strong><div>{{ $appointment->cancellation_reason }}</div></div>
            </div>
        @endif

        <div class="mg-appt-grid {{ $isScheduled ? '' : 'is-single' }}">
            <div class="mg-appt-col">
                <x-card title="Details" data-section="appointment-details">
                    <dl class="mg-appt-details">
                        <dt>Customer</dt>
                        <dd>
                            {{ $contact['name'] ?? $contact['phone'] ?? '—' }}
                            @if ($contactUrl)
                                <a href="{{ $contactUrl }}" class="ms-1" data-role="appointment-contact-link">View contact</a>
                            @endif
                        </dd>
                        <dt>Location</dt>
                        <dd>{{ $location->name ?: 'This location' }}</dd>
                        <dt>Staff</dt>
                        <dd>{{ $appointment->staff ? $staffName($appointment->staff) : '—' }}</dd>
                        <dt>Rescheduled</dt>
                        <dd>{{ (int) $appointment->reschedule_count }} time(s)</dd>
                    </dl>
                </x-card>

                @if ($isScheduled)
                    <x-card title="Reschedule" data-section="appointment-reschedule">
                        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.reschedule', $withAppointment) }}">
                            @csrf
                            <div class="mg-appt-resched">
                                <div class="form-group mb-0">
                                    <label for="date">New date</label>
                                    <input type="date" id="date" name="date" class="form-control" value="{{ old('date', $startLocal->toDateString()) }}" required>
                                </div>
                                <div class="form-group mb-0">
                                    <label for="time">New start time</label>
                                    <input type="time" id="time" name="time" class="form-control" value="{{ old('time', $startLocal->format('H:i')) }}" required>
                                </div>
                                <div class="form-group mb-0">
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
                            <span class="text-caption ms-1">Times are in {{ $timezone }}.</span>
                        </form>
                    </x-card>
                @endif
            </div>

            @if ($isScheduled)
                <div class="mg-appt-col">
                    <x-card title="Outcome" data-section="appointment-outcome">
                        <div class="mg-appt-outcome">
                            <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.complete', $withAppointment) }}">
                                @csrf
                                <x-button type="submit" variant="primary" icon="check" data-role="complete">Mark completed</x-button>
                            </form>

                            <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.no-show', $withAppointment) }}">
                                @csrf
                                <x-button type="submit" variant="secondary" icon="clock-alert" data-role="no-show">Mark no-show</x-button>
                            </form>
                        </div>
                    </x-card>

                    <x-card title="Cancel appointment" class="mg-appt-danger" data-section="appointment-cancel">
                        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.cancel', $withAppointment) }}">
                            @csrf
                            <div class="form-group">
                                <label for="reason">Cancellation reason (optional)</label>
                                <input type="text" id="reason" name="reason" class="form-control" maxlength="255" value="{{ old('reason') }}">
                            </div>
                            <x-button type="submit" variant="danger" icon="x" data-role="cancel">Cancel appointment</x-button>
                        </form>
                    </x-card>
                </div>
            @endif
        </div>

        @unless ($isScheduled)
            <p class="mg-appt-closed" data-role="appointment-closed">
                This appointment is {{ strtolower($statusText) }} and can no longer be changed.
            </p>
        @endunless
    </div>
@endsection
