@extends('layouts/contentLayoutMaster')

{{--
    Lane C §C5.1 — the Stripe account that receives THIS agency's SaaS revenue.

    It is the agency's account and the agency's money; the platform takes no cut
    of it. Nothing on this page is, or could be, a secret key: `stripe_account_id`
    is a provider identifier and it is shown masked even so.
--}}

@section('title', 'SaaS revenue account')

@section('content')
    <section id="agency-saas-stripe">
        <h2 class="mb-2">Stripe account for client subscriptions</h2>

        <p class="text-caption" data-role="agency-stripe-fee-disclosure">
            {{ __('This platform takes no fee or cut of your client payments. You pay Stripe\'s own processing fees directly, and you are responsible for payment losses, chargebacks and negative balances on your account under Stripe\'s Connected Account Agreement. Stripe may also charge separate platform-level or per-account fees under its own Connect pricing; those are between you (or, where applicable, Jazmin Media as the platform) and Stripe, and are not eliminated or guaranteed by this configuration. Review your Stripe Connect and Services Agreements for the exact terms.') }}
        </p>

        <x-card title="Status" class="mb-2">
            @if ($connection === null)
                <p data-role="agency-stripe-state">{{ __('Not connected') }}</p>
                <p class="text-caption">
                    {{ __('Connect a Stripe account to bill your clients. Their subscriptions are charged on your own account — the money is yours, and we take no cut of it.') }}
                </p>

                @if ($isOwner)
                    <form method="POST" action="{{ route('customer.workspaces.agency.saas.stripe.connect', [$agencyWorkspace->uid]) }}"
                          data-role="agency-stripe-connect">
                        @csrf
                        <label>{{ __('Country') }}
                            <input type="text" name="country" maxlength="2" value="US" required>
                        </label>
                        <label>{{ __('Billing email (optional)') }}
                            <input type="email" name="email">
                        </label>
                        <button type="submit">{{ __('Create Stripe account') }}</button>
                    </form>
                    <p class="text-caption mb-2">{{ __('You finish setup on Stripe. We never see or store your bank details.') }}</p>

                    <p class="mb-1">{{ __('Already have a Stripe account?') }}</p>
                    <a href="{{ route('customer.workspaces.agency.saas.stripe.connect-existing', [$agencyWorkspace->uid]) }}"
                       data-role="agency-stripe-connect-existing" class="btn btn-outline-primary">
                        {{ __('Connect existing Stripe account') }}
                    </a>
                    <p class="text-caption mb-0">
                        {{ __('You\'ll sign in on Stripe\'s own page to authorize the connection — we never ask for your account ID or any Stripe credential directly.') }}
                    </p>
                @else
                    <p class="text-caption mb-0" data-role="agency-stripe-owner-only">
                        {{ __('Only the agency owner can connect the account that receives your revenue.') }}
                    </p>
                @endif
            @else
                <dl class="row" data-role="agency-stripe-summary">
                    <dt class="col-sm-5">{{ __('Status') }}</dt>
                    <dd class="col-sm-7" data-role="agency-stripe-state">
                        @switch($connection->status->value)
                            @case('active') {{ __('Ready to take payments') }} @break
                            @case('onboarding') {{ __('Setup not finished') }} @break
                            @case('restricted') {{ __('Restricted by Stripe') }} @break
                            @case('incompatible') {{ __('Not compatible with this platform') }} @break
                            @case('disconnected') {{ __('Disconnected') }} @break
                            @default {{ __('Pending') }}
                        @endswitch
                    </dd>

                    <dt class="col-sm-5">{{ __('Account') }}</dt>
                    <dd class="col-sm-7" data-role="agency-stripe-account">{{ $connection->maskedAccountId() }}</dd>

                    @if ($connection->status->value === 'incompatible')
                        <dt class="col-sm-5">{{ __('Why') }}</dt>
                        <dd class="col-sm-7" data-role="agency-stripe-requirement">
                            {{ __('This account\'s Stripe fee, loss-liability, requirement-collection or Dashboard configuration does not match what this platform requires (code: :reason). It cannot take client payments as connected. Disconnect it below, then create a new account or connect a different existing one.', ['reason' => $connection->requirements_disabled_reason]) }}
                        </dd>
                    @elseif ($connection->requirements_disabled_reason !== null)
                        <dt class="col-sm-5">{{ __('Stripe needs') }}</dt>
                        <dd class="col-sm-7" data-role="agency-stripe-requirement">{{ $connection->requirements_disabled_reason }}</dd>
                    @endif

                    @if ($connection->last_synced_at !== null)
                        <dt class="col-sm-5">{{ __('Last checked') }}</dt>
                        <dd class="col-sm-7">{{ $connection->last_synced_at->diffForHumans() }}</dd>
                    @endif
                </dl>

                @if ($isOwner)
                    {{--
                        Incompatible is never offered "Finish setup": Stripe's
                        controller.stripe_dashboard.type cannot change after
                        an account exists, so resuming onboarding can never
                        fix this — only disconnecting and connecting a
                        different, compatible account can.
                    --}}
                    @if (! $chargeReady && $connection->status->value !== 'incompatible')
                        <form method="POST" action="{{ route('customer.workspaces.agency.saas.stripe.resume', [$agencyWorkspace->uid]) }}"
                              data-role="agency-stripe-resume">
                            @csrf
                            <button type="submit">{{ __('Finish Stripe setup') }}</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('customer.workspaces.agency.saas.stripe.disconnect', [$agencyWorkspace->uid]) }}"
                          data-role="agency-stripe-disconnect">
                        @csrf
                        <label>
                            <input type="checkbox" name="confirm" value="1" required>
                            {{ __('Disconnect this account. New client subscriptions stop immediately; existing ones are not cancelled for you.') }}
                        </label>
                        <button type="submit">{{ __('Disconnect') }}</button>
                    </form>
                @endif
            @endif
        </x-card>

        @if ($history->count() > 1)
            <x-card title="Previous accounts">
                <ul data-role="agency-stripe-history">
                    @foreach ($history as $row)
                        <li>{{ $row->maskedAccountId() }} — {{ $row->status->value }}</li>
                    @endforeach
                </ul>
            </x-card>
        @endif
    </section>

    {{--
        P1-C — automatic status polling. No manual "Refresh" button exists
        on this page any more (Task D): while the connection is in a
        non-terminal state (pending/onboarding/restricted), this polls
        AgencySaasController::stripeStatus() — a cheap, LOCAL-only read by
        default — every few seconds. That endpoint's own server-side
        throttle (AgencyStripeConnectManager::refreshForStatusPoll(), 15s)
        decides on its own, independently of this script, when a poll is
        also allowed to reach Stripe; several open tabs polling
        simultaneously still cannot exceed that one shared rate. On any
        status change, OR any change to WHY it is restricted (Stripe can
        move an account from one outstanding requirement to a different
        one — or clear it — while the status stays "restricted" the whole
        time; PR #380 finding 2), the page reloads once to render the
        exact same server-side Blade state every other flow already
        produces, rather than duplicating that rendering logic in
        JavaScript. Polling never
        starts at all for a non-owner (nothing here would be able to
        refresh anyway — see stripeStatus()'s own owner-only rule) or once
        the connection is already in a state that can never change on its
        own (Active, Incompatible — its Dashboard type is immutable — or no
        connection at all).
    --}}
    @if ($isOwner && $connection !== null && in_array($connection->status->value, ['pending', 'onboarding', 'restricted'], true))
        <script>
            (function () {
                var pollUrl = @json(route('customer.workspaces.agency.saas.stripe.status-poll', [$agencyWorkspace->uid]));
                var initialStatus = @json($connection->status->value);
                var initialReason = @json($connection->requirements_disabled_reason);
                var pollIntervalMs = 5000;
                var timer = null;

                function csrfToken() {
                    var meta = document.querySelector('meta[name="csrf-token"]');

                    return meta ? meta.getAttribute('content') : '';
                }

                function poll() {
                    fetch(pollUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken(),
                            'Accept': 'application/json',
                        },
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            // PR #380 finding 2 — a status-value change is
                            // NOT the only visible change worth reloading
                            // for: `reason` can change (one outstanding
                            // requirement resolving into a different one,
                            // or clearing) while `status` stays
                            // "restricted" throughout, and the page's own
                            // "Why" text must not go stale in that case.
                            if (data.status && (data.status !== initialStatus || data.reason !== initialReason)) {
                                window.clearInterval(timer);
                                window.location.reload();

                                return;
                            }

                            // A terminal status the last full page render did
                            // not yet reflect (e.g. Active reached between
                            // renders) also stops polling without waiting for
                            // a change to be detected above.
                            if (data.status === 'active' || data.status === 'incompatible') {
                                window.clearInterval(timer);
                            }
                        })
                        .catch(function () {
                            // A transient network error just waits for the
                            // next tick — never surfaced as a page error.
                        });
                }

                timer = window.setInterval(poll, pollIntervalMs);
            })();
        </script>
    @endif
@endsection
