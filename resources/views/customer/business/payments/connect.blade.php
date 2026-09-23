{{--
    Implementation Contract 17 Sub-slice D — the Stripe Connect status page
    for money lane B.

    READ-ONLY FOR NON-OWNERS. Staff who already passed the gate chain may see
    whether the business can be paid; only an owner sees the controls, and the
    server refuses them regardless of what this page renders (§6.2).

    NO ACCOUNT ID IS EVER A FORM FIELD. Every action posts to a route that
    derives the connected account from the Business's own row, so the browser
    cannot name or substitute another account.

    No secret, no API key and no raw provider payload is rendered here. The
    Stripe account id is shown because it is the Business's own identifier and
    is useful for support, and it is escaped like everything else.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Payments')

@php
    use App\Enums\Documents\StripeConnectionStatus;
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Online payments</h4>
            <span class="text-caption">{{ $business->name }}</span>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    <x-card :padded="true" class="mb-2" data-section="stripe-connection">
        <p class="text-caption mb-1" data-role="posture-note">
            Payments go directly to this business's own Stripe account. This business is the merchant of
            record, receives the funds, and pays Stripe's fees. This platform never holds the money.
        </p>

        @if($connection === null)
            <p data-role="connection-state" data-status="none">No Stripe account is connected yet.</p>

            @if($isOwner)
                <form method="POST" action="{{ route('customer.workspaces.businesses.payments.connect.start', [$workspaceUid, $businessUid]) }}" data-role="connect-form">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">Connect with Stripe</button>
                </form>
            @else
                <p class="text-caption" data-role="owner-only-note">Only an owner can connect a Stripe account.</p>
            @endif
        @else
            <p data-role="connection-state" data-status="{{ $connection->status->value }}">
                @switch($connection->status)
                    @case(StripeConnectionStatus::Active)
                        Connected and ready to accept payments.
                        @break
                    @case(StripeConnectionStatus::Restricted)
                        Stripe needs more information before this business can accept payments.
                        @break
                    @default
                        Stripe onboarding has been started but is not finished.
                @endswitch
            </p>

            <ul class="list-unstyled text-caption mb-1" data-role="capabilities">
                <li data-capability="charges" data-enabled="{{ $connection->charges_enabled ? '1' : '0' }}">Accept payments: {{ $connection->charges_enabled ? 'Yes' : 'Not yet' }}</li>
                <li data-capability="payouts" data-enabled="{{ $connection->payouts_enabled ? '1' : '0' }}">Receive payouts: {{ $connection->payouts_enabled ? 'Yes' : 'Not yet' }}</li>
                <li data-capability="details" data-enabled="{{ $connection->details_submitted ? '1' : '0' }}">Details submitted: {{ $connection->details_submitted ? 'Yes' : 'Not yet' }}</li>
                <li data-role="stripe-account">Stripe account: {{ $connection->stripe_account_id }}</li>
                @if($connection->requirements_disabled_reason)
                    <li data-role="disabled-reason">Stripe reason: {{ $connection->requirements_disabled_reason }}</li>
                @endif
                @if($connection->last_synced_at)
                    <li>Last checked {{ $connection->last_synced_at->diffForHumans() }}</li>
                @endif
            </ul>

            @if($isOwner)
                <div class="d-flex gap-1 flex-wrap">
                    @if($connection->status !== StripeConnectionStatus::Active)
                        <a class="btn btn-primary btn-sm" href="{{ route('customer.workspaces.businesses.payments.connect.resume', [$workspaceUid, $businessUid]) }}" data-role="resume-link">Continue on Stripe</a>
                    @endif
                    <form method="POST" action="{{ route('customer.workspaces.businesses.payments.connect.refresh', [$workspaceUid, $businessUid]) }}" data-role="refresh-form">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Check status</button>
                    </form>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.payments.connect.disconnect', [$workspaceUid, $businessUid]) }}" data-role="disconnect-form">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Disconnect</button>
                    </form>
                </div>
            @else
                <p class="text-caption" data-role="owner-only-note">Only an owner can change this Stripe connection.</p>
            @endif
        @endif

        {{-- §11.4 — payability is a separate question, and Sub-slice E owns
             actually charging. Stated plainly rather than implied. --}}
        <p class="text-caption mt-1" data-role="charge-ready" data-ready="{{ $chargeReady ? '1' : '0' }}">
            @if($chargeReady)
                This business can be paid online once payment collection is switched on.
            @else
                This business cannot be paid online yet.
            @endif
        </p>
    </x-card>

    @if($history->count() > 1)
        <x-card :padded="true" data-section="connection-history">
            <p class="text-section-heading mb-1">Previous connections</p>
            <ul class="list-unstyled text-caption mb-0">
                @foreach($history as $row)
                    @continue($connection !== null && (int) $row->id === (int) $connection->id)
                    <li data-role="history-row" data-status="{{ $row->status->value }}">
                        {{ $row->stripe_account_id }} — {{ $row->status->value }}
                        @if($row->disconnected_at), disconnected {{ $row->disconnected_at->format('j F Y') }}@endif
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
@endsection
