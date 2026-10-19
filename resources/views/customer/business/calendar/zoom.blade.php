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

    @php
        $mineActive = $mine && $mine->isActive();
        $mineBroken = $mine && in_array($mine->state, ['revoked', 'failed'], true);
        $connectUrl = route('customer.workspaces.businesses.calendar.integrations.zoom.connect', [$workspace->uid, $business->uid]);
    @endphp

    <style>
        .zoom-page { display: grid; gap: 1rem; }
        .zoom-grid { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr); align-items: start; }
        @media (max-width: 991.98px) { .zoom-grid { grid-template-columns: minmax(0, 1fr); } }
        .zoom-account { display: flex; gap: .875rem; align-items: flex-start; }
        .zoom-account__icon { flex: none; width: 2.75rem; height: 2.75rem; border-radius: .75rem; display: grid; place-items: center; background: var(--color-accent-subtle, #EAF1FF); color: var(--color-accent, #2D8CFF); }
        .zoom-account__title { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; font-weight: 600; margin: 0; }
        .zoom-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1rem; }
        .zoom-actions form { margin: 0; }
        .zoom-trust { display: flex; gap: .5rem; align-items: flex-start; margin: 1rem 0 0; padding-top: .875rem; border-top: 1px solid var(--color-border, #E7E5DF); }
        .zoom-steps { list-style: none; margin: 0 0 1rem; padding: 0; display: grid; gap: .875rem; }
        .zoom-steps li { display: flex; gap: .75rem; align-items: flex-start; font-size: .8125rem; }
        .zoom-steps__icon { flex: none; width: 1.75rem; height: 1.75rem; border-radius: .5rem; display: grid; place-items: center; background: var(--color-surface-muted, #F3F1EC); color: var(--color-text-muted, #6F6D67); }
        .zoom-hosts__row { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.4fr) minmax(0, .8fr) auto; gap: .75rem; align-items: center; padding: .75rem 0; border-top: 1px solid var(--color-border, #E7E5DF); }
        .zoom-hosts__row--head { border-top: 0; padding: 0 0 .5rem; font-size: .6875rem; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
        .zoom-hosts__row form { margin: 0; }
        .zoom-hosts__label { display: none; }
        @media (max-width: 767.98px) {
            .zoom-hosts__row--head { display: none; }
            .zoom-hosts__row { grid-template-columns: minmax(0, 1fr) auto; }
            .zoom-hosts__row > :nth-child(2) { grid-column: 1 / -1; order: 3; }
        }
    </style>

    @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $flashKey => $flashVariant)
        {{-- The connected banner below already says "Zoom connected", so that flash would only repeat it. --}}
        @if (session($flashKey) && ! ($flashKey === 'flash_success' && $mineActive && $eligible && $configured))
            <x-alert :dismissible="true" :variant="$flashVariant" class="mb-2" role="status">{{ session($flashKey) }}</x-alert>
        @endif
    @endforeach

    <div class="zoom-page" data-section="zoom-integration">
        @if (! $eligible)
            <x-alert variant="neutral" class="mb-0" role="status" data-role="zoom-not-eligible">
                Zoom meetings are available on Growth and Agency plans, and for clients managed by an Agency. Your current plan does not include them.
            </x-alert>
        @elseif (! $configured)
            <x-alert variant="neutral" class="mb-0" role="status" data-role="zoom-not-configured">
                Zoom is not set up on this platform yet, so it cannot be connected. Ask your administrator.
            </x-alert>
        @else
            @if ($mineActive)
                <x-alert variant="success" title="Zoom connected" class="mb-0" data-role="zoom-banner">New appointments with a Zoom location will get a meeting link automatically.</x-alert>
            @elseif ($mineBroken)
                <x-alert variant="danger" title="Zoom needs to be reconnected" class="mb-0" data-role="zoom-banner">MotionGrove could not use your Zoom connection. Reconnect Zoom so appointments set to Zoom can get meeting links again.</x-alert>
            @else
                <x-alert variant="accent" title="Zoom is not connected" class="mb-0" data-role="zoom-banner">Connect your Zoom account and MotionGrove will add a Zoom meeting link to bookings that use Zoom automatically.</x-alert>
            @endif

            <div class="zoom-grid">
                <x-card class="mb-0" data-section="zoom-mine">
                    <div class="zoom-account">
                        <span class="zoom-account__icon" aria-hidden="true"><x-ds-icon name="video" :size="22" /></span>
                        <div class="flex-grow-1" style="min-width: 0;">
                            @if ($mineActive)
                                <h2 class="zoom-account__title text-section-heading">Your Zoom account <x-badge variant="success">Connected</x-badge></h2>
                                <p class="mb-1 mt-25" data-role="zoom-status">{{ $mine->zoom_display_name ?: 'Zoom account' }}@if ($mine->zoom_account_email) · {{ $mine->zoom_account_email }}@endif</p>
                                <p class="text-caption mb-0">Meetings for appointments you host are created in this Zoom account.</p>
                            @elseif ($mineBroken)
                                <h2 class="zoom-account__title text-section-heading">Your Zoom account <x-badge variant="danger">Needs reconnecting</x-badge></h2>
                                <p class="mb-1 mt-25" data-role="zoom-status">Zoom no longer lets MotionGrove act for this account.</p>
                                <p class="text-caption mb-0">Appointments set to Zoom cannot get a meeting link until you reconnect.</p>
                            @else
                                <h2 class="zoom-account__title text-section-heading">Connect your Zoom account <x-badge variant="neutral">Not connected</x-badge></h2>
                                <p class="mb-1 mt-25" data-role="zoom-status">Link the Zoom account you host meetings from.</p>
                                <p class="text-caption mb-0">Booking types set to Zoom stay closed to customers until every host has connected Zoom.</p>
                            @endif

                            <div class="zoom-actions">
                                <form method="POST" action="{{ $connectUrl }}" data-role="zoom-connect-form" onsubmit="var b=this.querySelector('button');b.disabled=true;b.setAttribute('aria-busy','true');var h=document.getElementById('zoom-connect-helper');if(h){h.hidden=false;}">
                                    @csrf
                                    <x-button type="submit" :variant="$mineActive ? 'secondary' : 'primary'" data-role="zoom-connect">{{ $mine ? 'Reconnect Zoom' : 'Connect Zoom' }}</x-button>
                                </form>
                                @if ($mineActive)
                                    <form method="POST" action="{{ $disconnectUrl($mine) }}" onsubmit="return confirm('Disconnect Zoom? Booking types that use your Zoom account will stop accepting bookings until it is reconnected.');">
                                        @csrf
                                        <x-button type="submit" variant="secondary" data-role="zoom-disconnect">Disconnect</x-button>
                                    </form>
                                @endif
                            </div>
                            <p id="zoom-connect-helper" class="text-caption mt-1 mb-0" role="status" data-role="zoom-redirecting" hidden>Redirecting to Zoom…</p>

                            <p class="zoom-trust text-caption mb-0">
                                <x-ds-icon name="lock" :size="14" class="flex-none mt-25" aria-hidden="true" />
                                <span>You sign in to Zoom on zoom.us and approve MotionGrove there. MotionGrove never sees your Zoom password.</span>
                            </p>
                        </div>
                    </div>
                </x-card>

                <x-card title="How Zoom works with bookings" class="mb-0" data-section="zoom-how-it-works">
                    <ul class="zoom-steps">
                        <li><span class="zoom-steps__icon"><x-ds-icon name="plus" :size="14" /></span><span>A <strong>unique meeting</strong> is created for every appointment that uses Zoom.</span></li>
                        <li><span class="zoom-steps__icon"><x-ds-icon name="refresh-cw" :size="14" /></span><span>It <strong>moves or cancels</strong> automatically when the appointment does.</span></li>
                        <li><span class="zoom-steps__icon"><x-ds-icon name="mail" :size="14" /></span><span>Customers get the <strong>join link</strong> in their confirmation and reminders.</span></li>
                        <li><span class="zoom-steps__icon"><x-ds-icon name="tag" :size="14" /></span><span>Choose <strong>Zoom meeting</strong> as the location on a booking type, or on a single appointment.</span></li>
                    </ul>
                    @if ($location)
                        <x-button :href="route('customer.workspaces.businesses.calendar.booking-types.index', [$workspace->uid, $business->uid, $location->uid])" variant="secondary" data-role="zoom-booking-types-link">Go to booking types <x-ds-icon name="arrow-right" :size="14" /></x-button>
                    @endif
                </x-card>
            </div>

            <x-card title="Zoom hosts in this business" class="mb-0" data-section="zoom-hosts">
                <p class="text-caption">Each person connects their own Zoom account. A booking type can also use one shared host, chosen in its settings.</p>
                @if ($connections->isEmpty())
                    <p class="text-caption mb-0" data-role="zoom-no-hosts">No one has connected Zoom yet. Hosts appear here as soon as they connect.</p>
                @else
                    <div class="zoom-hosts__row zoom-hosts__row--head" aria-hidden="true"><span>Person</span><span>Zoom account</span><span>Status</span><span></span></div>
                    @foreach ($connections as $connection)
                        @php([$variant, $label] = $stateLabel($connection))
                        <div class="zoom-hosts__row" data-role="zoom-host-row">
                            <span class="fw-semibold">{{ $nameOf($users[$connection->user_id] ?? null) }}@if ((int) $connection->user_id === (int) auth()->id()) <span class="text-caption fw-normal">You</span>@endif</span>
                            <span class="text-caption" style="overflow-wrap: anywhere;">{{ $connection->zoom_account_email ?: '—' }}</span>
                            <span><x-badge :variant="$variant">{{ $label }}</x-badge></span>
                            <span class="text-end">
                                @if ($connection->isActive() && ((int) $connection->user_id === (int) auth()->id() || $isOwner))
                                    <form method="POST" action="{{ $disconnectUrl($connection) }}" onsubmit="return confirm('Disconnect this Zoom host?');">
                                        @csrf
                                        <x-button type="submit" size="sm" variant="secondary">Disconnect</x-button>
                                    </form>
                                @elseif (! $connection->isActive() && (int) $connection->user_id === (int) auth()->id())
                                    <form method="POST" action="{{ $connectUrl }}">
                                        @csrf
                                        <x-button type="submit" size="sm" variant="primary">Reconnect</x-button>
                                    </form>
                                @endif
                            </span>
                        </div>
                    @endforeach
                @endif
            </x-card>
        @endif
    </div>
@endsection
