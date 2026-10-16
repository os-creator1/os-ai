{{--
    Implementation Contract 21 §13 — the ACTIONS half of Plan & subscription, on
    the CANONICAL V1 lane-A subscription (the summary half is the hero card in
    plan.blade.php).

    Never legacy Ultimate SMS plan semantics (§4). The plans offered come from
    the catalogue through CustomerSubscriptionPresenter — none is named here —
    and every price is the plan's own configured price. The customer's current
    price is their SNAPSHOT (§10.1): repricing the catalog tomorrow must not
    change what an existing subscriber is told they pay.
--}}
@php($workspaceUid = request()->route('workspaceUid'))

@if (! $subscription['is_complimentary'] && $subscription['has_subscription'])

    {{--
        §10.4 — an ENDED subscription gets "start again", never the
        upgrade/downgrade controls. There is no live provider subscription
        to change, so those controls could only ever fail; this form opens a
        NEW hosted Checkout on the SAME account.
    --}}
    @if ($subscription['can_resubscribe'] && count($subscription['resubscribe_plans']) > 0)
        <div class="sr-card">
            <div class="sr-card__head">
                <div>
                    <h2 class="sr-card__title">{{ __('Start subscription again') }}</h2>
                    <p class="sr-card__sub">{{ __('Your account, your business and everything in it are still here. Choose a plan to pick up where you left off.') }}</p>
                </div>
            </div>
            <form method="POST" action="{{ route('customer.workspaces.plan.resubscribe', [$workspaceUid]) }}" data-role="subscription-resubscribe">
                @csrf
                <div class="sr-card__body">
                    <div class="sr-plans mb-3">
                        @foreach ($subscription['resubscribe_plans'] as $plan)
                            <label class="sr-plan">
                                <span class="sr-plan__top">
                                    <span class="d-flex align-items-center gap-2">
                                        <input type="radio" name="tier" value="{{ $plan['tier_value'] }}" required class="form-check-input mt-0">
                                        {{ $plan['display_name'] }}
                                    </span>
                                </span>
                                <span class="d-block sr-plan__price">{{ $plan['price'] }} {{ $plan['currency_code'] }} <small>/ {{ $plan['billing_cycle_per'] }}</small></span>
                            </label>
                        @endforeach
                    </div>
                    <div class="sr-confirm">
                        <label class="form-check mb-0" style="font-size:.8125rem">
                            <input type="checkbox" class="form-check-input" name="confirm" value="1" required>
                            {{ __('I want to start a new subscription on this account.') }}
                        </label>
                        <button type="submit" class="btn btn-primary">{{ __('Start subscription again') }}</button>
                    </div>
                </div>
            </form>
        </div>
    @endif

    {{-- §10.2 — change plan, with the resulting behaviour stated BEFORE confirming. --}}
    @if (count($subscription['available_plans']) > 0)
        <div class="sr-card">
            <div class="sr-card__head">
                <div>
                    <h2 class="sr-card__title">{{ __('Change plan') }}</h2>
                    <p class="sr-card__sub">{{ __('Pick the plan you want to move to. What happens next is stated before you confirm.') }}</p>
                </div>
            </div>
            <form method="POST" action="{{ route('customer.workspaces.plan.change', [$workspaceUid]) }}" data-role="subscription-change-plan">
                @csrf
                <div class="sr-card__body">
                    <div class="sr-plans mb-3">
                        {{-- The plan the customer is on now, as a fixed reference, from their own snapshot. --}}
                        <div class="sr-plan is-current" data-role="subscription-current-plan">
                            <span class="sr-plan__top">
                                <span>{{ $subscription['tier_name'] }}</span>
                                <span class="sr-chip">{{ __('Current plan') }}</span>
                            </span>
                            @if ($subscription['price'] !== null)
                                <span class="d-block sr-plan__price">{{ $subscription['price'] }} {{ $subscription['currency_code'] }} <small>/ {{ \App\Library\PlatformBilling\BillingCycle::perLabel((string) $subscription['billing_cycle']) }}</small></span>
                            @endif
                        </div>

                        @foreach ($subscription['available_plans'] as $plan)
                            <label class="sr-plan">
                                <span class="sr-plan__top">
                                    <span class="d-flex align-items-center gap-2">
                                        <input type="radio" name="tier" value="{{ $plan['tier_value'] }}" required class="form-check-input mt-0">
                                        {{ $plan['display_name'] }}
                                    </span>
                                    <span class="sr-chip {{ $plan['direction'] === 'upgrade' ? 'sr-chip--accent' : '' }}">{{ $plan['direction'] === 'upgrade' ? __('Upgrade') : __('Downgrade') }}</span>
                                </span>
                                <span class="d-block sr-plan__price">{{ $plan['price'] }} {{ $plan['currency_code'] }} <small>/ {{ $plan['billing_cycle_per'] }}</small></span>
                                <span class="d-block sr-plan__note" data-role="plan-direction-{{ $plan['tier_value'] }}">
                                    @if ($plan['direction'] === 'upgrade')
                                        {{ __('Upgrade — takes effect immediately, and you are billed the difference now.') }}
                                    @else
                                        {{ __('Downgrade — takes effect at the end of your current billing period. Nothing is deleted.') }}
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @if ($subscription['private_pricing'] !== null)
                        <label class="form-check mb-3" style="font-size:.8125rem" data-role="subscription-ends-private-pricing">
                            <input type="checkbox" class="form-check-input" name="confirm_ends_private_pricing" value="1" required>
                            {{ __('I understand this ends my private pricing: I will move to the standard price of the plan I choose.') }}
                        </label>
                    @endif

                    <div class="sr-confirm">
                        <label class="form-check mb-0" style="font-size:.8125rem">
                            <input type="checkbox" class="form-check-input" name="confirm" value="1" required>
                            {{ __('I understand what this change does.') }}
                        </label>
                        <button type="submit" class="btn btn-primary">{{ __('Change plan') }}</button>
                    </div>
                </div>
            </form>
        </div>
    @endif

    {{-- §10.3 — cancel, or undo a scheduled cancellation. --}}
    @if ($subscription['can_resume'])
        <div class="sr-card">
            <form method="POST" action="{{ route('customer.workspaces.plan.resume', [$workspaceUid]) }}" data-role="subscription-resume">
                @csrf
                <div class="sr-card__body sr-confirm">
                    <div>
                        <h2 class="sr-card__title">{{ __('Your subscription is set to end') }}</h2>
                        @if ($subscription['current_period_end'] !== null)
                            <p class="sr-card__sub mb-0">{{ __('You keep access until :date.', ['date' => $subscription['current_period_end']->toFormattedDateString()]) }}</p>
                        @endif
                    </div>
                    <button type="submit" class="btn btn-primary">{{ __('Keep my subscription') }}</button>
                </div>
            </form>
        </div>
    @elseif (in_array($subscription['state'], ['trialing', 'active', 'past_due', 'locked'], true))
        <div class="sr-card">
            <form method="POST" action="{{ route('customer.workspaces.plan.cancel', [$workspaceUid]) }}" data-role="subscription-cancel">
                @csrf
                <div class="sr-card__body">
                    <h2 class="sr-card__title">Cancel subscription</h2>
                    @if ($subscription['current_period_end'] !== null)
                        <p class="sr-card__sub mb-3">{{ __('You keep access until the end of the period you\'ve paid for (:date).', ['date' => $subscription['current_period_end']->toFormattedDateString()]) }}</p>
                    @else
                        <p class="sr-card__sub mb-3">{{ __('You keep access until the end of the period you\'ve paid for.') }}</p>
                    @endif
                    <div class="sr-confirm sr-confirm--boxed">
                        <label class="form-check mb-0" style="font-size:.8125rem">
                            <input type="checkbox" class="form-check-input" name="confirm" value="1" required>
                            {{ __('Yes, cancel my subscription. I keep access until the end of the period I have paid for.') }}
                        </label>
                        <button type="submit" class="btn btn-sm btn-sr-danger-outline">{{ __('Cancel subscription') }}</button>
                    </div>
                </div>
            </form>
        </div>
    @endif
@endif
