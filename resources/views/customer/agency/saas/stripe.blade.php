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
                        <button type="submit">{{ __('Connect Stripe') }}</button>
                    </form>
                    <p class="text-caption mb-0">{{ __('You finish setup on Stripe. We never see or store your bank details.') }}</p>
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
                            @case('disconnected') {{ __('Disconnected') }} @break
                            @default {{ __('Pending') }}
                        @endswitch
                    </dd>

                    <dt class="col-sm-5">{{ __('Account') }}</dt>
                    <dd class="col-sm-7" data-role="agency-stripe-account">{{ $connection->maskedAccountId() }}</dd>

                    @if ($connection->requirements_disabled_reason !== null)
                        <dt class="col-sm-5">{{ __('Stripe needs') }}</dt>
                        <dd class="col-sm-7" data-role="agency-stripe-requirement">{{ $connection->requirements_disabled_reason }}</dd>
                    @endif

                    @if ($connection->last_synced_at !== null)
                        <dt class="col-sm-5">{{ __('Last checked') }}</dt>
                        <dd class="col-sm-7">{{ $connection->last_synced_at->diffForHumans() }}</dd>
                    @endif
                </dl>

                @if ($isOwner)
                    @if (! $chargeReady)
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
