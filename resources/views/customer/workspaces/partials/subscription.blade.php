{{--
    Implementation Contract 21 §13 — Plan & subscription, on the CANONICAL V1
    lane-A subscription.

    Never legacy Ultimate SMS plan semantics (§4). The price shown is the
    customer's own SNAPSHOT (§10.1): repricing the catalog tomorrow must not
    change what an existing subscriber is told they pay.
--}}
@php($workspaceUid = request()->route('workspaceUid'))

<x-card title="Subscription">
    @if ($subscription['is_complimentary'])
        {{-- §11.1 — a complimentary account is never presented as a paid one. --}}
        <p data-role="subscription-state">{{ __('Complimentary account') }}</p>
        <p class="text-caption mb-0">{{ __('Your account is provided at no charge, so there is nothing to pay or cancel.') }}</p>
    @elseif (! $subscription['has_subscription'])
        <p data-role="subscription-state">{{ __('No subscription yet') }}</p>
        <p class="text-caption mb-0">{{ __('Choose a plan to start your subscription.') }}</p>
    @else
        <dl class="row" data-role="subscription-summary">
            <dt class="col-sm-5">{{ __('Plan') }}</dt>
            <dd class="col-sm-7" data-role="subscription-tier">{{ $subscription['tier_name'] }}</dd>

            <dt class="col-sm-5">{{ __('Status') }}</dt>
            <dd class="col-sm-7" data-role="subscription-state">
                @switch($subscription['state'])
                    @case('trialing') {{ __('Trial') }} @break
                    @case('active') {{ __('Active') }} @break
                    @case('past_due') {{ __('Payment problem') }} @break
                    @case('locked') {{ __('Locked') }} @break
                    @case('cancelling') {{ __('Ending at period end') }} @break
                    @case('ended') {{ __('Ended') }} @break
                    @case('inactive') {{ __('Inactive') }} @break
                    @case('suspended') {{ __('Suspended') }} @break
                    @default {{ __('Unknown') }}
                @endswitch
            </dd>

            @if ($subscription['price'] !== null)
                <dt class="col-sm-5">{{ __('Price') }}</dt>
                <dd class="col-sm-7" data-role="subscription-price">
                    {{ $subscription['price'] }} {{ $subscription['currency_code'] }} / {{ $subscription['billing_cycle'] }}
                </dd>
            @endif

            @if ($subscription['trial_ends_at'] !== null)
                <dt class="col-sm-5">{{ __('Trial ends') }}</dt>
                <dd class="col-sm-7" data-role="subscription-trial-end">{{ $subscription['trial_ends_at']->toFormattedDateString() }}</dd>
            @endif

            @if ($subscription['current_period_end'] !== null)
                <dt class="col-sm-5">
                    {{ $subscription['cancel_at_period_end'] ? __('Access ends') : __('Next renewal') }}
                </dt>
                <dd class="col-sm-7" data-role="subscription-period-end">{{ $subscription['current_period_end']->toFormattedDateString() }}</dd>
            @endif

            @if ($subscription['pending_tier_name'] !== null)
                <dt class="col-sm-5">{{ __('Scheduled change') }}</dt>
                <dd class="col-sm-7" data-role="subscription-pending">
                    {{ __('Moving to :plan', ['plan' => $subscription['pending_tier_name']]) }}
                    @if ($subscription['pending_effective_at'] !== null)
                        — {{ $subscription['pending_effective_at']->toFormattedDateString() }}
                    @endif
                </dd>
            @endif
        </dl>

        {{-- §9/§13 — the billing problem, its deadline, and the REAL recovery action. --}}
        @if (in_array($subscription['state'], ['past_due', 'locked'], true))
            <div role="alert" data-role="subscription-billing-problem">
                <p>{{ __('We could not take your latest payment.') }}</p>
                @if ($subscription['grace_ends_at'] !== null)
                    <p>{{ __('Your account stays fully usable until :date. After that it locks until payment succeeds.', [
                        'date' => $subscription['grace_ends_at']->toFormattedDateString(),
                    ]) }}</p>
                @endif
                @if ($subscription['can_manage_payment_method'])
                    <a href="{{ route('customer.workspaces.plan.payment-method', [$workspaceUid]) }}"
                       data-role="subscription-fix-payment">{{ __('Update your payment method') }}</a>
                @endif
            </div>
        @endif

        @if ($subscription['can_manage_payment_method'])
            <p>
                <a href="{{ route('customer.workspaces.plan.payment-method', [$workspaceUid]) }}"
                   data-role="subscription-payment-method">{{ __('Manage payment method') }}</a>
                <span class="text-caption">{{ __('Opens Stripe. We never see or store your card.') }}</span>
            </p>
        @endif

        {{-- §10.2 — change plan, with the resulting behaviour stated BEFORE confirming. --}}
        @if (count($subscription['available_plans']) > 0)
            <form method="POST" action="{{ route('customer.workspaces.plan.change', [$workspaceUid]) }}"
                  data-role="subscription-change-plan">
                @csrf
                <fieldset>
                    <legend>{{ __('Change plan') }}</legend>
                    @foreach ($subscription['available_plans'] as $plan)
                        <label>
                            <input type="radio" name="tier" value="{{ $plan['tier_value'] }}" required>
                            {{ $plan['display_name'] }} — {{ $plan['price'] }} {{ $plan['currency_code'] }} / {{ $plan['billing_cycle'] }}
                            <span data-role="plan-direction-{{ $plan['tier_value'] }}">
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

        {{-- §10.3 — cancel, or undo a scheduled cancellation. --}}
        @if ($subscription['can_resume'])
            <form method="POST" action="{{ route('customer.workspaces.plan.resume', [$workspaceUid]) }}"
                  data-role="subscription-resume">
                @csrf
                <button type="submit">{{ __('Keep my subscription') }}</button>
            </form>
        @elseif (in_array($subscription['state'], ['trialing', 'active', 'past_due', 'locked'], true))
            <form method="POST" action="{{ route('customer.workspaces.plan.cancel', [$workspaceUid]) }}"
                  data-role="subscription-cancel">
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
