@extends('layouts/contentLayoutMaster')

{{--
    Lane C §C6/§C8 — the CLIENT's own view of what their agency bills them.

    THE CLIENT IS TOLD WHO IS CHARGING THEM, on every state of this page. The
    money goes to their agency, not to us, and a customer who cannot tell the
    difference cannot dispute the right charge with the right party.

    THE PRICE SHOWN IS THEIR OWN SNAPSHOT (§C3.4): repricing the agency's plan
    tomorrow does not change what an existing subscriber is told they pay.

    There is no card field anywhere on this page, and there could not be: every
    money action leaves for Stripe's own hosted pages.
--}}

@php($workspaceUid = $workspace->uid)

@section('title', 'Your agency plan')

@section('content')
    <section id="agency-client-plan">
        <x-card title="Your plan">
            @if (! $agencyPlan['has_agency_billing'])
                <p data-role="agency-plan-state">{{ __('No agency plan') }}</p>
                <p class="text-caption mb-0">{{ __('Your agency has not offered you a plan yet.') }}</p>
            @else
                <p class="text-caption" data-role="agency-plan-biller">
                    {{ __('Billed by :agency, on their own Stripe account.', ['agency' => $agencyPlan['agency_name']]) }}
                </p>

                <dl class="row" data-role="agency-plan-summary">
                    <dt class="col-sm-5">{{ __('Plan') }}</dt>
                    <dd class="col-sm-7" data-role="agency-plan-name">{{ $agencyPlan['plan_name'] }}</dd>

                    <dt class="col-sm-5">{{ __('Status') }}</dt>
                    <dd class="col-sm-7" data-role="agency-plan-state">
                        @switch($agencyPlan['state'])
                            @case('offered') {{ __('Offered — not yet active') }} @break
                            @case('awaiting_payment') {{ __('Waiting for payment') }} @break
                            @case('trialing') {{ __('Trial') }} @break
                            @case('active') {{ __('Active') }} @break
                            @case('past_due') {{ __('Payment problem') }} @break
                            @case('locked') {{ __('Locked') }} @break
                            @case('cancelling') {{ __('Ending at period end') }} @break
                            @case('ended') {{ __('Ended') }} @break
                            @case('agency_unavailable') {{ __('Unavailable') }} @break
                            @case('inactive') {{ __('Inactive') }} @break
                            @case('suspended') {{ __('Suspended') }} @break
                            @default {{ __('Unknown') }}
                        @endswitch
                    </dd>

                    @if ($agencyPlan['price'] !== null)
                        <dt class="col-sm-5">{{ __('Price') }}</dt>
                        <dd class="col-sm-7" data-role="agency-plan-price">
                            {{ $agencyPlan['price'] }} {{ $agencyPlan['currency_code'] }} / {{ $agencyPlan['billing_cycle'] }}
                        </dd>
                    @endif

                    @if ($agencyPlan['trial_ends_at'] !== null)
                        <dt class="col-sm-5">{{ __('Trial ends') }}</dt>
                        <dd class="col-sm-7" data-role="agency-plan-trial-end">{{ $agencyPlan['trial_ends_at']->toFormattedDateString() }}</dd>
                    @elseif ($agencyPlan['is_offer'] && $agencyPlan['trial_days'] !== null)
                        <dt class="col-sm-5">{{ __('Free trial') }}</dt>
                        <dd class="col-sm-7" data-role="agency-plan-trial-offer">{{ $agencyPlan['trial_days'] }} {{ __('days') }}</dd>
                    @endif

                    @if ($agencyPlan['current_period_end'] !== null)
                        <dt class="col-sm-5">
                            {{ $agencyPlan['cancel_at_period_end'] ? __('Access ends') : __('Next renewal') }}
                        </dt>
                        <dd class="col-sm-7" data-role="agency-plan-period-end">{{ $agencyPlan['current_period_end']->toFormattedDateString() }}</dd>
                    @endif

                    @if ($agencyPlan['pending_plan_name'] !== null)
                        <dt class="col-sm-5">{{ __('Scheduled change') }}</dt>
                        <dd class="col-sm-7" data-role="agency-plan-pending">
                            {{ __('Moving to :plan', ['plan' => $agencyPlan['pending_plan_name']]) }}
                            @if ($agencyPlan['pending_effective_at'] !== null)
                                — {{ $agencyPlan['pending_effective_at']->toFormattedDateString() }}
                            @endif
                        </dd>
                    @endif
                </dl>

                {{-- §C6 — THE CONSENT STEP. The client reviews the real terms and says yes themselves. --}}
                @if ($agencyPlan['can_consent'])
                    @if ($agencyPlan['plan_description'] !== null)
                        <p data-role="agency-plan-description">{{ $agencyPlan['plan_description'] }}</p>
                    @endif

                    <form method="POST" action="{{ route('customer.workspaces.agency-plan.checkout', [$workspaceUid]) }}"
                          data-role="agency-plan-consent">
                        @csrf
                        <label>
                            <input type="checkbox" name="confirm" value="1" required>
                            {{ __('I agree to pay :agency :price :currency per :cycle for this plan.', [
                                'agency' => $agencyPlan['agency_name'],
                                'price' => $agencyPlan['price'],
                                'currency' => $agencyPlan['currency_code'],
                                'cycle' => $agencyPlan['billing_cycle'],
                            ]) }}
                        </label>
                        <button type="submit">{{ __('Continue to payment') }}</button>
                        <span class="text-caption">{{ __('You pay on Stripe. We never see or store your card.') }}</span>
                    </form>
                @endif

                {{-- §C7 — the billing problem, its deadline, and the REAL recovery action. --}}
                @if (in_array($agencyPlan['state'], ['past_due', 'locked'], true))
                    <div role="alert" data-role="agency-plan-billing-problem">
                        <p>{{ __('Your agency could not take your latest payment.') }}</p>
                        @if ($agencyPlan['grace_ends_at'] !== null)
                            <p>{{ __('Your account stays fully usable until :date. After that it locks until payment succeeds.', [
                                'date' => $agencyPlan['grace_ends_at']->toFormattedDateString(),
                            ]) }}</p>
                        @endif
                        @if ($agencyPlan['can_manage_payment_method'])
                            <a href="{{ route('customer.workspaces.agency-plan.payment-method', [$workspaceUid]) }}"
                               data-role="agency-plan-fix-payment">{{ __('Update your payment method') }}</a>
                        @endif
                    </div>
                @endif

                {{-- The agency's own account is the problem, and saying so is the honest thing. --}}
                @if ($agencyPlan['state'] === 'agency_unavailable')
                    <div role="alert" data-role="agency-plan-agency-unavailable">
                        <p>{{ __('Access is unavailable because of the status of the agency account that manages this one. Your setup and data are saved — please contact your agency.') }}</p>
                    </div>
                @endif

                @if ($agencyPlan['can_manage_payment_method'])
                    <p>
                        <a href="{{ route('customer.workspaces.agency-plan.payment-method', [$workspaceUid]) }}"
                           data-role="agency-plan-payment-method">{{ __('Manage payment method') }}</a>
                        <span class="text-caption">{{ __('Opens Stripe. We never see or store your card.') }}</span>
                    </p>
                @endif

                {{-- §C7 — change plan, with the resulting behaviour stated BEFORE confirming. --}}
                @if ($agencyPlan['can_change_plan'] && count($agencyPlan['available_plans']) > 0)
                    <form method="POST" action="{{ route('customer.workspaces.agency-plan.change', [$workspaceUid]) }}"
                          data-role="agency-plan-change">
                        @csrf
                        <fieldset>
                            <legend>{{ __('Change plan') }}</legend>
                            @foreach ($agencyPlan['available_plans'] as $plan)
                                <label>
                                    <input type="radio" name="plan_uid" value="{{ $plan['uid'] }}" required>
                                    {{ $plan['name'] }} — {{ $plan['price'] }} {{ $plan['currency_code'] }} / {{ $plan['billing_cycle'] }}
                                    <span data-role="agency-plan-direction-{{ $plan['tier_value'] }}">
                                        @if ($plan['direction'] === 'upgrade')
                                            {{ __('Upgrade — takes effect immediately, and you are billed the difference now.') }}
                                        @else
                                            {{ __('Downgrade — takes effect at the end of your current billing period. Nothing is deleted.') }}
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                            <label>
                                <input type="checkbox" name="confirm" value="1" required>
                                {{ __('I understand what this change does.') }}
                            </label>
                            <button type="submit">{{ __('Change plan') }}</button>
                        </fieldset>
                    </form>
                @endif

                {{-- §C7 — start again after it has ended. Same account, new subscription. --}}
                @if ($agencyPlan['can_resubscribe'] && count($agencyPlan['available_plans']) > 0)
                    <form method="POST" action="{{ route('customer.workspaces.agency-plan.resubscribe', [$workspaceUid]) }}"
                          data-role="agency-plan-resubscribe">
                        @csrf
                        <fieldset>
                            <legend>{{ __('Start again') }}</legend>
                            <p class="text-caption">{{ __('Your account, your business and everything in it are still here. Choose a plan to pick up where you left off.') }}</p>
                            @foreach ($agencyPlan['available_plans'] as $plan)
                                <label>
                                    <input type="radio" name="plan_uid" value="{{ $plan['uid'] }}" required>
                                    {{ $plan['name'] }} — {{ $plan['price'] }} {{ $plan['currency_code'] }} / {{ $plan['billing_cycle'] }}
                                </label>
                            @endforeach
                            <label>
                                <input type="checkbox" name="confirm" value="1" required>
                                {{ __('I want to start a new subscription with this agency.') }}
                            </label>
                            <button type="submit">{{ __('Start again') }}</button>
                        </fieldset>
                    </form>
                @endif

                {{-- §C7 — cancel, or undo a scheduled cancellation. --}}
                @if ($agencyPlan['can_resume'])
                    <form method="POST" action="{{ route('customer.workspaces.agency-plan.resume', [$workspaceUid]) }}"
                          data-role="agency-plan-resume">
                        @csrf
                        <button type="submit">{{ __('Keep my subscription') }}</button>
                    </form>
                @elseif ($agencyPlan['can_cancel'])
                    <form method="POST" action="{{ route('customer.workspaces.agency-plan.cancel', [$workspaceUid]) }}"
                          data-role="agency-plan-cancel">
                        @csrf
                        <label>
                            <input type="checkbox" name="confirm" value="1" required>
                            {{ __('Yes, cancel my subscription. I keep access until the end of the period I have paid for.') }}
                        </label>
                        <button type="submit">{{ __('Cancel subscription') }}</button>
                    </form>
                @endif
            @endif
        </x-card>
    </section>
@endsection
