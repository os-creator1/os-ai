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

                    <form method="POST" action="{{ route('customer.workspaces.agency.saas.stripe.sync', [$agencyWorkspace->uid]) }}"
                          data-role="agency-stripe-sync">
                        @csrf
                        <button type="submit">{{ __('Refresh status from Stripe') }}</button>
                    </form>

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
@endsection
