@extends(request()->query('fragment') === '1' ? 'customer.business.calendar._fragment' : 'customer.business.calendar._frame')

@section('title', 'Zoom')
@section('calendar-active', 'zoom')

@section('calendar-section')
    @php
        // Presentation only. Every authorization and eligibility answer was decided before this rendered.
        $nameOf = static fn ($user) => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? 'Team member');
        $isOwner = (int) $workspace->owner_user_id === (int) auth()->id();
        $disconnectUrl = static fn ($connection) => route('customer.workspaces.businesses.calendar.integrations.zoom.disconnect', [$workspace->uid, $business->uid, $connection->id]);
        $stateLabel = static fn ($c) => match (true) {
            $c->isActive() => ['success', 'Connected'],
            $c->state === 'revoked' => ['danger', 'Needs reconnecting'],
            $c->state === 'failed' => ['danger', 'Needs reconnecting'],
            default => ['neutral', 'Not connected'],
        };
    @endphp

    @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $flashKey => $flashVariant)
        @if (session($flashKey))
            <x-alert :dismissible="true" :variant="$flashVariant" class="mb-2" role="status">{{ session($flashKey) }}</x-alert>
        @endif
    @endforeach

    <div data-section="zoom-integration">
        <p class="bt-intro">
            Connect Zoom once and MotionGrove creates a unique Zoom meeting for every appointment that uses it, moves it when the appointment
            moves, and cancels it when the appointment is cancelled. Customers receive the join link in their confirmation and reminders.
            Choose <strong>Zoom meeting</strong> as the meeting location on a booking type, or on a single appointment.
        </p>

        @if (! $eligible)
            <x-alert variant="neutral" class="mb-2" role="status" data-role="zoom-not-eligible">
                Zoom meetings are available on Growth and Agency plans, and for clients managed by an Agency. Your current plan does not include them.
            </x-alert>
        @elseif (! $configured)
            <x-alert variant="neutral" class="mb-2" role="status" data-role="zoom-not-configured">
                Zoom is not set up on this platform yet, so it cannot be connected. Ask your administrator.
            </x-alert>
        @else
            <x-card title="Your Zoom account" class="mb-2" data-section="zoom-mine">
                @if ($mine && $mine->isActive())
                    <p class="mb-1" data-role="zoom-status">
                        <x-badge variant="success">Connected</x-badge>
                        {{ $mine->zoom_display_name ?: 'Zoom account' }}@if ($mine->zoom_account_email) · {{ $mine->zoom_account_email }}@endif
                    </p>
                    <p class="text-caption">Meetings for appointments you host are created in this Zoom account.</p>
                @elseif ($mine && in_array($mine->state, ['revoked', 'failed'], true))
                    <p class="mb-1" data-role="zoom-status"><x-badge variant="danger">Needs reconnecting</x-badge> Zoom no longer lets MotionGrove act for this account.</p>
                    <p class="text-caption">Appointments set to Zoom cannot get a meeting link until you reconnect.</p>
                @else
                    <p class="mb-1" data-role="zoom-status"><x-badge variant="neutral">Not connected</x-badge></p>
                    <p class="text-caption">Booking types set to Zoom stay closed to customers until every host has connected Zoom.</p>
                @endif

                <div class="d-flex flex-wrap" style="gap: .5rem;">
                    <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.integrations.zoom.connect', [$workspace->uid, $business->uid]) }}">
                        @csrf
                        <x-button type="submit" variant="primary" data-role="zoom-connect">{{ $mine && $mine->isActive() ? 'Reconnect Zoom' : ($mine ? 'Reconnect Zoom' : 'Connect Zoom') }}</x-button>
                    </form>
                    @if ($mine && $mine->isActive())
                        <form method="POST" action="{{ $disconnectUrl($mine) }}" onsubmit="return confirm('Disconnect Zoom? Booking types that use your Zoom account will stop accepting bookings until it is reconnected.');">
                            @csrf
                            <x-button type="submit" variant="secondary" data-role="zoom-disconnect">Disconnect</x-button>
                        </form>
                    @endif
                </div>
                <p class="text-caption mt-1 mb-0">You sign in to Zoom on zoom.us and approve MotionGrove there. MotionGrove never sees your Zoom password.</p>
            </x-card>

            <x-card title="Zoom hosts in this business" class="mb-2" data-section="zoom-hosts">
                @if ($connections->isEmpty())
                    <p class="text-caption mb-0">No one has connected Zoom yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>Person</th><th>Zoom account</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($connections as $connection)
                                @php([$variant, $label] = $stateLabel($connection))
                                <tr data-role="zoom-host-row">
                                    <td>{{ $nameOf($users[$connection->user_id] ?? null) }}</td>
                                    <td>{{ $connection->zoom_account_email ?: '—' }}</td>
                                    <td><x-badge :variant="$variant">{{ $label }}</x-badge></td>
                                    <td class="text-end">
                                        @if ($connection->isActive() && ((int) $connection->user_id === (int) auth()->id() || $isOwner))
                                            <form method="POST" action="{{ $disconnectUrl($connection) }}" onsubmit="return confirm('Disconnect this Zoom host?');">
                                                @csrf
                                                <x-button type="submit" size="sm" variant="secondary">Disconnect</x-button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="text-caption mt-1 mb-0">Each person connects their own Zoom account. A booking type can also use one shared host chosen in its settings.</p>
            </x-card>
        @endif
    </div>
@endsection
