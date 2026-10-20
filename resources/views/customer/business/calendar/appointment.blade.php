@extends('layouts/contentLayoutMaster')

@section('title', 'Appointment')

@section('page-style')
    @include('customer.business.calendar._styles')
    <style>
        .mg-appt{max-width:68rem}
        .mg-appt-back{display:inline-flex;align-items:center;gap:.25rem;margin-bottom:1rem;font-size:.875rem;color:var(--color-text-muted)}
        .mg-appt-head{display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem}
        .mg-appt-tile{flex:none;display:flex;flex-direction:column;align-items:center;justify-content:center;width:4.5rem;padding:0 0 .4rem;overflow:hidden;border:1px solid var(--color-border-neutral,#e5e5e5);border-radius:.75rem;background:var(--color-surface,#fff);line-height:1.1}
        .mg-appt-tile__month{align-self:stretch;text-align:center;padding:.2rem 0;margin-bottom:.3rem;font-size:.7rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#fff;background:var(--color-primary)}
        .mg-appt-tile__day{font-size:1.6rem;font-weight:700}
        .mg-appt-tile__weekday{font-size:.7rem;color:var(--color-text-muted)}
        .mg-appt-head__title{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin:0 0 .25rem}
        .mg-appt-when{margin:0;color:var(--color-text-muted);font-size:.9rem}
        .mg-appt-grid{display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:1rem;align-items:start}
        .mg-appt-col{display:flex;flex-direction:column;gap:1rem;min-width:0}
        .mg-appt-grid.is-single{grid-template-columns:minmax(0,1fr)}
        .mg-appt-col>.card{margin-bottom:0}
        .mg-appt-details{margin:0}
        .mg-appt-row{display:flex;align-items:center;gap:.85rem;padding:.75rem 0;border-top:1px solid var(--color-border-neutral,#e5e5e5)}
        .mg-appt-row:first-child{border-top:0;padding-top:0}
        .mg-appt-row:last-child{padding-bottom:0}
        .mg-appt-row__icon{flex:none;display:inline-flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:999px;background:var(--color-surface-muted,#f1efea);color:var(--color-text-muted)}
        .mg-appt-row__body{min-width:0;flex:1 1 auto}
        .mg-appt-details dt{font-size:.7rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted)}
        .mg-appt-details dd{margin:0;font-weight:600;overflow-wrap:anywhere}
        .mg-appt-help{margin:-.25rem 0 .75rem;font-size:.8rem;color:var(--color-text-muted)}
        .mg-appt-resched-foot{display:flex;align-items:center;justify-content:flex-end;gap:.75rem;padding-top:1rem;border-top:1px solid var(--color-border-neutral,#e5e5e5)}
        .mg-appt .btn.mg-appt-complete{background:#1f7a4d!important;border-color:#1f7a4d!important;color:#fff!important}
        .mg-appt-tile.is-cancelled .mg-appt-tile__month{background:#8a8680}
        .mg-appt-tile.is-cancelled .mg-appt-tile__day{text-decoration:line-through;color:var(--color-text-muted)}
        .mg-appt-banner__note{margin-top:.15rem;font-size:.85rem;color:var(--color-text-muted)}
        .mg-appt .mg-appt-danger .btn.btn-danger{background:transparent!important;color:var(--color-danger,#c0392b)!important;border:1px solid #e3b4ae!important}
        .mg-appt .mg-appt-danger .btn.btn-danger:hover{background:var(--color-danger,#c0392b)!important;color:#fff!important;border-color:var(--color-danger,#c0392b)!important}
        .mg-appt-resched{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin-bottom:1rem}
        .mg-appt-resched .form-control{min-width:0;width:100%}
        .mg-appt-outcome{display:flex;flex-direction:column;gap:.5rem}
        .mg-appt-outcome .btn{width:100%}
        .mg-appt-danger{border-color:var(--color-danger,#c0392b)!important}
        .mg-appt-danger .card-title,.mg-appt-danger h2,.mg-appt-danger h3,.mg-appt-danger h4{color:var(--color-danger,#c0392b)}
        .mg-appt-banner{display:flex;gap:.75rem;align-items:flex-start;padding:.875rem 1rem;margin-bottom:1rem;border:1px solid var(--color-border-neutral,#e5e5e5);border-radius:.75rem;background:var(--color-surface-muted,#f6f5f2)}
        .mg-appt-banner.is-cancelled{border-color:#e7bcb7;background:#fbefed}
        html.dark-layout .mg-appt-banner.is-cancelled{border-color:var(--color-status-danger-border);background:var(--color-status-danger-soft-bg)}
        .mg-appt-closed{margin:1rem 0 0;color:var(--color-text-muted)}
        @media (max-width:767.98px){
            .mg-appt-grid{grid-template-columns:minmax(0,1fr)}
            .mg-appt-resched{grid-template-columns:minmax(0,1fr)}
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
            'completed' => 'success', 'cancelled' => 'danger', 'no_show' => 'warning', default => 'accent',
        };
    @endphp

    @php
        $contactUrl = null;
        if (! empty($contact['uid']) && \Illuminate\Support\Facades\Gate::allows('view_contact')
            && \Illuminate\Support\Facades\Route::has('customer.workspaces.businesses.people.show')) {
            $contactUrl = route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact['uid']]);
        }
        $isCancelled = $appointment->status === \App\Enums\Calendar\AppointmentStatus::Cancelled;
        $statusText = $statusLabels[$appointment->status->value] ?? $appointment->status->value;
    @endphp

    <div class="mg-appt" data-role="appointment-page">
        <a href="{{ route('customer.workspaces.businesses.calendar.schedule', $scope) }}?date={{ $startLocal->toDateString() }}"
           class="mg-appt-back" data-role="appointment-back">
            <x-ds-icon name="chevron-left" size="16" aria-hidden="true" />
            Back to calendar
        </a>

        <section class="mg-appt-head">
            <div class="mg-appt-tile {{ $isCancelled ? 'is-cancelled' : '' }}" data-role="appointment-date-tile" aria-hidden="true">
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
                <x-alert :dismissible="true" :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
            @endif
        @endforeach

        @if ($errors->any())
            <x-alert variant="danger" class="mb-2" role="alert">{{ $errors->first() }}</x-alert>
        @endif

        @if ($isCancelled)
            <div class="mg-appt-banner is-cancelled" data-role="appointment-cancellation-banner">
                <x-ds-icon name="calendar-x" size="18" aria-hidden="true" />
                <div>
                    <strong>This appointment was cancelled</strong>
                    @if ($appointment->cancellation_reason)
                        <div>Cancellation reason: <strong>{{ $appointment->cancellation_reason }}</strong></div>
                    @endif
                    <div class="mg-appt-banner__note" data-role="appointment-closed">This appointment is cancelled and can no longer be changed.</div>
                </div>
            </div>
        @endif

        <div class="mg-appt-grid {{ $isScheduled ? '' : 'is-single' }}">
            <div class="mg-appt-col">
                <x-card title="Details" data-section="appointment-details">
                    <dl class="mg-appt-details">
                        <div class="mg-appt-row">
                            <span class="mg-appt-row__icon" aria-hidden="true"><x-ds-icon name="user" size="16" /></span>
                            <div class="mg-appt-row__body"><dt>Customer</dt><dd>{{ $contact['name'] ?? $contact['phone'] ?? '—' }}
                            @if ($contactUrl)
                                <a href="{{ $contactUrl }}" class="ms-1 fw-normal" data-role="appointment-contact-link">View contact</a>
                            @endif</dd></div>
                        </div>
                        <div class="mg-appt-row">
                            <span class="mg-appt-row__icon" aria-hidden="true"><x-ds-icon name="map-pin" size="16" /></span>
                            <div class="mg-appt-row__body"><dt>Location</dt><dd>{{ $location->name ?: 'This location' }}</dd></div>
                        </div>
                        @if ($appointment->meeting_location_type)
                            @php
                                $zoomState = \App\Library\Calendar\Zoom\ZoomLinkGate::state($appointment);
                                $zoomJoin = \App\Library\Calendar\Zoom\ZoomLinkGate::joinUrl($appointment);
                                $meetingLabel = ['zoom' => 'Zoom meeting', 'in_person' => 'In person', 'phone' => 'Phone call', 'custom' => $appointment->meeting_location_custom ?: 'Custom location'][$appointment->meeting_location_type] ?? null;
                            @endphp
                            <div class="mg-appt-row" data-role="appointment-meeting-location">
                                <span class="mg-appt-row__icon" aria-hidden="true"><x-ds-icon name="video" size="16" /></span>
                                <div class="mg-appt-row__body"><dt>Meeting</dt><dd>
                                    {{ $meetingLabel }}
                                    @if ($appointment->meeting_location_type === 'zoom')
                                        @if ($zoomJoin && $appointment->status === \App\Enums\Calendar\AppointmentStatus::Scheduled)
                                            <div class="mt-50"><x-button variant="primary" size="sm" :href="$zoomJoin" target="_blank" rel="noopener" data-role="join-zoom">Join Zoom</x-button></div>
                                        @elseif ($zoomState === 'failed' && $appointment->status === \App\Enums\Calendar\AppointmentStatus::Scheduled)
                                            <div class="text-danger" data-role="zoom-failed">The Zoom meeting could not be created. If the host's Zoom was disconnected, reconnect it in Zoom settings first.</div>
                                            <form method="POST" class="mt-50" action="{{ route('customer.workspaces.businesses.calendar.appointments.zoom-retry', array_merge($scope, [$appointment->uid])) }}">
                                                @csrf
                                                <x-button type="submit" variant="secondary" size="sm" data-role="retry-zoom">Retry Zoom</x-button>
                                            </form>
                                        @elseif ($zoomState === 'pending' && $appointment->status === \App\Enums\Calendar\AppointmentStatus::Scheduled)
                                            <div class="text-caption" data-role="zoom-pending">Creating the Zoom meeting… the join button appears here when it is ready.</div>
                                        @endif
                                    @endif
                                </dd></div>
                            </div>
                        @endif
                        <div class="mg-appt-row">
                            <span class="mg-appt-row__icon" aria-hidden="true"><x-ds-icon name="user-round" size="16" /></span>
                            <div class="mg-appt-row__body"><dt>Staff</dt><dd>{{ $appointment->staff ? $staffName($appointment->staff) : '—' }}</dd></div>
                        </div>
                        <div class="mg-appt-row">
                            <span class="mg-appt-row__icon" aria-hidden="true"><x-ds-icon name="refresh-cw" size="16" /></span>
                            <div class="mg-appt-row__body"><dt>Rescheduled</dt><dd>{{ (int) $appointment->reschedule_count }} time(s)</dd></div>
                        </div>
                    </dl>
                </x-card>

                @if ($isScheduled)
                    <x-card title="Reschedule" data-section="appointment-reschedule">
                        <p class="mg-appt-help">Pick a new date, time or staff member.</p>
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
                            <div class="mg-appt-resched-foot">
                                <span class="text-caption">Times are in {{ $timezone }}.</span>
                                <x-button type="submit" variant="primary" data-role="reschedule">Reschedule</x-button>
                            </div>
                        </form>
                    </x-card>
                @endif
            </div>

            @if ($isScheduled)
                <div class="mg-appt-col">
                    <x-card title="Outcome" data-section="appointment-outcome">
                        <p class="mg-appt-help">How did it go?</p>
                        <div class="mg-appt-outcome">
                            <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.complete', $withAppointment) }}">
                                @csrf
                                <x-button type="submit" variant="primary" icon="check" class="mg-appt-complete" data-role="complete">Mark completed</x-button>
                            </form>

                            <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.appointments.no-show', $withAppointment) }}">
                                @csrf
                                <x-button type="submit" variant="secondary" icon="clock-alert" data-role="no-show">Mark no-show</x-button>
                            </form>
                        </div>
                    </x-card>

                    <x-card title="Cancel appointment" class="mg-appt-danger" data-section="appointment-cancel">
                        <p class="mg-appt-help">Once cancelled, it can no longer be changed.</p>
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

        @if (! $isScheduled && ! $isCancelled)
            <p class="mg-appt-closed" data-role="appointment-closed">
                This appointment is {{ strtolower($statusText) }} and can no longer be changed.
            </p>
        @endif
    </div>
@endsection
