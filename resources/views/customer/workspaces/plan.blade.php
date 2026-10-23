@extends('layouts/contentLayoutMaster')

{{--
    Settings → Plan & subscription: this account's AI Business OS plan
    (WorkspacePlanPresenter) and its canonical V1 subscription
    (CustomerSubscriptionPresenter). Every line is a persisted fact — plan,
    status, the features that exist today, canonical capacity, the customer's
    own price snapshot and the plans the catalogue actually offers. No plan
    name, price, count or capability is written into this page; a renamed or
    additional plan appears here the moment the catalogue has it. The inherited
    SMS "Pricing Plans" / subscription pages are a different domain and are not
    linked from here.
--}}

@section('title', 'Plan & subscription')

@section('page-style')
    @include('customer.settings._redesign-assets')
@endsection

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $hasSubscription = $plan !== null && ! $subscription['is_complimentary'] && $subscription['has_subscription'];
        $subscriptionStates = [
            'trialing' => ['Trial', 'accent'],
            'active' => ['Active', 'success'],
            'past_due' => ['Payment problem', 'danger'],
            'locked' => ['Locked', 'danger'],
            'cancelling' => ['Ending at period end', 'warn'],
            'ended' => ['Ended', ''],
            'inactive' => ['Inactive', ''],
            'suspended' => ['Suspended', 'warn'],
        ];
        $stateChip = $hasSubscription ? ($subscriptionStates[$subscription['state']] ?? ['Unknown', '']) : null;
        $periodEnd = $subscription['current_period_end'] ?? null;
        $billingProblem = $hasSubscription && in_array($subscription['state'], ['past_due', 'locked'], true);
    @endphp

    <section id="workspace-plan">
    <div class="sr-page">
        <a href="{{ route('customer.workspaces.settings.show', $workspaceUid) }}" class="sr-back">
            <x-ds-icon name="arrow-left" size="14" aria-hidden="true" /> Back to Settings
        </a>
        <div class="sr-head">
            <div class="sr-head__main">
                <h1 class="sr-title">Plan &amp; subscription</h1>
            </div>
        </div>

        {{-- Your plan --}}
        <div class="sr-card">
            @if ($plan === null)
                <div class="sr-card__body">
                    <p class="mb-0" data-role="plan-unassigned">No plan is set up for {{ $accountName }} yet. Please contact support.</p>
                </div>
            @else
                <div class="sr-card__body d-flex align-items-center justify-content-between gap-4 flex-wrap">
                    <div class="d-flex align-items-center gap-3">
                        <span class="sr-tile sr-tile--solid sr-tile--lg"><x-ds-icon name="trending-up" size="22" /></span>
                        <div>
                            <div class="sr-eyebrow mb-0">Your plan</div>
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <h2 class="mb-0" style="font-size:1.25rem;font-weight:700" data-role="plan-name">{{ $plan['name'] }}</h2>
                                <span class="sr-chip sr-chip--{{ $plan['status_variant'] === 'success' ? 'success' : ($plan['status_variant'] === 'warning' ? 'warn' : '') }}">{{ $plan['status'] }}</span>
                                @if ($plan['complimentary'])
                                    <span class="sr-chip">Complimentary</span>
                                @endif
                                @if ($stateChip !== null && strcasecmp(__($stateChip[0]), $plan['status']) !== 0)
                                    <span class="sr-chip sr-chip--{{ $stateChip[1] }}" data-role="subscription-state">{{ __($stateChip[0]) }}</span>
                                @endif
                            </div>
                            <div class="sr-card__sub">{{ $accountName }}</div>
                        </div>
                    </div>

                    @if ($hasSubscription && $subscription['price'] !== null)
                        {{-- A subscriber's own price snapshot is what they are actually charged. --}}
                        <div class="text-end">
                            <div style="font-size:1.375rem;font-weight:700;line-height:1.1" data-role="subscription-price">{{ $subscription['currency_code'] }} {{ $subscription['price'] }}</div>
                            <div class="sr-card__sub">{{ \App\Library\PlatformBilling\BillingCycle::perLabel((string) $subscription['billing_cycle']) }}</div>
                            @if (! empty($billing))
                                <dl class="mb-0 mt-1" data-role="plan-billing" style="font-size:.75rem;color:var(--color-text-muted)">
                                    <dt class="d-inline fw-normal">Listed price:</dt> <dd class="d-inline">{{ $billing[0]['value'] }}</dd>
                                </dl>
                            @endif
                        </div>
                    @elseif (! empty($billing))
                        <div class="text-end">
                            <dl class="mb-0" data-role="plan-billing">
                                <dt class="visually-hidden">{{ $billing[0]['label'] }}</dt>
                                <dd class="mb-0" style="font-size:1.125rem;font-weight:700">{{ $billing[0]['value'] }}</dd>
                            </dl>
                        </div>
                    @endif
                </div>

                @if ($subscription['is_complimentary'])
                    <div class="sr-card__foot sr-card__foot--note">
                        <x-ds-icon name="gift" size="16" aria-hidden="true" />
                        <span data-role="subscription-state">{{ __('Complimentary account') }}</span>
                        &middot; <span>{{ __('Your account is provided at no charge, so there is nothing to pay or cancel.') }}</span>
                    </div>
                @elseif (! $subscription['has_subscription'])
                    <div class="sr-card__foot sr-card__foot--note">
                        <x-ds-icon name="info" size="16" aria-hidden="true" />
                        <span data-role="subscription-state">{{ __('No subscription yet') }}</span>
                        &middot; <span>{{ __('Choose a plan to start your subscription.') }}</span>
                    </div>
                @else
                    @if ($subscription['trial_ends_at'] !== null || $subscription['pending_tier_name'] !== null || $subscription['private_pricing'] !== null)
                        <div class="sr-card__body" style="border-top:1px solid var(--color-border);padding-block:var(--space-3)">
                            <dl class="sr-dl mb-0" data-role="subscription-summary">
                                @if ($subscription['trial_ends_at'] !== null)
                                    <div><dt class="fw-normal">{{ __('Trial ends') }}</dt><dd class="mb-0" style="color:var(--color-text-primary)" data-role="subscription-trial-end">{{ $subscription['trial_ends_at']->toFormattedDateString() }}</dd></div>
                                @endif
                                @if ($subscription['pending_tier_name'] !== null)
                                    <div><dt class="fw-normal">{{ __('Scheduled change') }}</dt>
                                        <dd class="mb-0" style="color:var(--color-text-primary)" data-role="subscription-pending">
                                            {{ __('Moving to :plan', ['plan' => $subscription['pending_tier_name']]) }}
                                            @if ($subscription['pending_effective_at'] !== null)
                                                — {{ $subscription['pending_effective_at']->toFormattedDateString() }}
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                                @if ($subscription['private_pricing'] !== null)
                                    <div><dt class="fw-normal">{{ __('Private pricing') }}</dt><dd class="mb-0" style="color:var(--color-text-primary)" data-role="subscription-private-pricing">{{ $subscription['private_pricing']['summary'] }}</dd></div>
                                @endif
                            </dl>
                        </div>
                    @endif

                    @if ($billingProblem)
                        {{-- §9/§13 — the billing problem, its deadline, and the REAL recovery action. --}}
                        <div class="sr-card__body" style="padding-top:0">
                            <div class="sr-callout sr-callout--danger mb-0" role="alert" data-role="subscription-billing-problem">
                                <x-ds-icon name="triangle-alert" size="16" />
                                <div>
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
                            </div>
                        </div>
                    @endif

                    @if ($subscription['can_manage_payment_method'] || $periodEnd !== null)
                        <div class="sr-card__foot">
                            <span class="sr-card__meta" style="white-space:normal">
                                @if ($periodEnd !== null)
                                    <x-ds-icon name="calendar" size="14" aria-hidden="true" />
                                    {{ $subscription['cancel_at_period_end'] ? __('Access ends on') : __('Renews on') }} <strong style="color:var(--color-text-primary)">{{ $periodEnd->toFormattedDateString() }}</strong>
                                @endif
                            </span>
                            @if ($subscription['can_manage_payment_method'])
                            <span class="d-flex align-items-center gap-3 flex-wrap">
                                <span class="text-caption">{{ __('Opens Stripe. We never see or store your card.') }}</span>
                                <a href="{{ route('customer.workspaces.plan.payment-method', [$workspaceUid]) }}"
                                   class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                   data-role="subscription-payment-method"><x-ds-icon name="credit-card" size="14" aria-hidden="true" /> {{ __('Manage payment method') }}</a>
                            </span>
                            @endif
                        </div>
                    @endif
                @endif
            @endif
        </div>

        @if ($plan !== null)
            @if (! empty($included) || ! empty($capacity))
                <div class="sr-grid-2" style="grid-template-columns:minmax(0,1.6fr) minmax(0,1fr)">
                    @if (! empty($included))
                        <div class="sr-card">
                            <div class="sr-card__head"><h2 class="sr-card__title">What's included</h2></div>
                            <div class="sr-card__body">
                                <ul class="sr-included" data-role="plan-included">
                                    @foreach ($included as $feature)
                                        <li>
                                            <span class="sr-check"><x-ds-icon name="check" size="12" aria-hidden="true" /></span>
                                            <div>
                                                <div class="fw-bolder">{{ $feature['name'] }}</div>
                                                <span class="sr-incl-desc">{{ $feature['description'] }}</span>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    @if (! empty($capacity))
                        <div class="sr-card">
                            <div class="sr-card__head"><h2 class="sr-card__title">Capacity</h2></div>
                            <div class="sr-card__body">
                                @if (! empty($meter) && $meter['total'] !== null && $meter['total'] > 0)
                                    @php($usedPct = min(100, (int) round($meter['used'] / $meter['total'] * 100)))
                                    <div class="d-flex justify-content-between align-items-baseline mb-2" style="font-size:.8125rem">
                                        <strong>{{ $meter['label'] }}</strong>
                                        <span data-role="plan-meter-text">{{ $meter['used'] }} of {{ $meter['total'] }} in use</span>
                                    </div>
                                    <div class="sr-meter sr-meter--single mb-3" role="img" aria-label="{{ $meter['used'] }} of {{ $meter['total'] }} {{ strtolower($meter['label']) }} in use">
                                        <span class="sr-meter__seg is-on" style="flex:0 0 {{ max($usedPct, $meter['used'] > 0 ? 4 : 0) }}%"></span>
                                        <span class="sr-meter__seg" style="flex:1"></span>
                                    </div>
                                @endif
                                <dl class="row mb-0" data-role="plan-capacity" style="font-size:.8125rem">
                                    @foreach ($capacity as $row)
                                        <dt class="col-sm-5">{{ $row['label'] }}</dt>
                                        <dd class="col-sm-7">{{ $row['value'] }}</dd>
                                    @endforeach
                                </dl>
                                @if ($capacity_note !== null)
                                    <p class="text-caption mt-2 mb-0" data-role="plan-capacity-note">{{ $capacity_note }}</p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Change plan / start again / cancel — all from CustomerSubscriptionPresenter. --}}
            @include('customer.workspaces.partials.subscription', ['subscription' => $subscription])
        @endif
    </div>
    </section>
    @include('legal._notice', ['doc' => 'refund-policy', 'text' => 'Renewal, cancellation and refunds are governed by the'])
@endsection
