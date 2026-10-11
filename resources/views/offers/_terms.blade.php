{{--
    The EXACT terms of a Private Offer, as the customer would be bound by them.

    $terms is an OfferTerms; every figure comes from it (and so from the same
    calculation the server re-runs when the customer accepts) — nothing
    commercial is written here. Money is rendered from integer minor units.
--}}
@php
    use App\Library\PlatformBilling\Offers\PlatformMoney;

    $code = $terms->currencyCode();
    $money = fn (int $minor): string => PlatformMoney::display($minor, $code);
    $date = fn (?string $iso): string => \Carbon\CarbonImmutable::parse($iso)->utc()->toFormattedDateString();
    $label = mb_strtolower((string) $terms->get('cycle_label'));
    $isChange = $terms->kind() === 'billing_change';
    $paid = $terms->paidPhases();
    $promotion = $terms->promotion();
    $hasPromoEnd = $terms->hasPromotionEnd();
    $first = $paid[0];
    $negotiated = $terms->get('standard_source') === 'negotiated';
@endphp

<div class="card mb-1" data-role="offer-terms">
    <div class="card-body">
        <h2 class="h4 mb-25" data-role="terms-plan">{{ $terms->get('plan_name') }}</h2>

        @if (! empty($plan['capabilities']))
            <ul class="list-unstyled mb-1" data-role="terms-features">
                @foreach ($plan['capabilities'] as $capability)
                    <li class="mb-25"><x-ds-icon name="check-circle" class="me-50" aria-hidden="true" />{{ $capability }}</li>
                @endforeach
            </ul>
        @endif

        <dl class="row mb-0">
            @if ($isChange)
                <dt class="col-sm-6">{{ __('Your current price') }}</dt>
                <dd class="col-sm-6" data-role="terms-current-price">{{ $money((int) $terms->get('previous_price_minor')) }} {{ $label }}</dd>
            @else
                <dt class="col-sm-6">{{ __('Free trial') }}</dt>
                <dd class="col-sm-6" data-role="terms-trial">
                    @if ($terms->trialDays() !== null)
                        {{ trans_choice('{1} :count day|[2,*] :count days', $terms->trialDays(), ['count' => $terms->trialDays()]) }}
                    @elseif ($terms->get('trial_blocked'))
                        {{ __('Not available — a free trial has already been used on this account.') }}
                    @else
                        {{ __('None') }}
                    @endif
                </dd>

                <dt class="col-sm-6">{{ __('Charged today') }}</dt>
                <dd class="col-sm-6" data-role="terms-today">{{ $money($terms->todayChargeMinor()) }}</dd>
            @endif

            <dt class="col-sm-6">{{ $isChange ? __('Your new price') : __('Recurring price') }}</dt>
            <dd class="col-sm-6" data-role="terms-recurring">
                {{ $money((int) $first['price_minor']) }}
                <span data-role="terms-frequency">{{ $label }}</span>
                @if ($promotion !== null && $hasPromoEnd)
                    <span class="badge bg-light-warning" data-role="terms-promo-badge">{{ __('Temporary promotional price') }}</span>
                @elseif ($negotiated)
                    <span class="badge bg-light-primary" data-role="terms-negotiated-badge">{{ __('Permanent agreed price') }}</span>
                @endif
            </dd>

            <dt class="col-sm-6">{{ $isChange ? __('Starts at your next renewal on') : __('First payment') }}</dt>
            <dd class="col-sm-6" data-role="terms-first-payment">
                @if (! $isChange && $terms->todayChargeMinor() > 0)
                    {{ __('Today') }} ({{ $money($terms->todayChargeMinor()) }})
                @else
                    {{ $date($terms->get('first_payment_at')) }} ({{ $money((int) $terms->get('first_payment_minor')) }})
                @endif
            </dd>

            @if ($hasPromoEnd)
                <dt class="col-sm-6">{{ __('Promotional price ends') }}</dt>
                <dd class="col-sm-6" data-role="terms-promo-end">{{ $date($promotion['ends_at']) }}</dd>

                <dt class="col-sm-6" data-role="terms-after-label">{{ $negotiated ? __('Then your agreed ongoing price') : __('Then the standard plan price') }}</dt>
                <dd class="col-sm-6" data-role="terms-after">{{ $money($terms->standardPriceMinor()) }} {{ $label }}</dd>
            @elseif ($promotion !== null)
                <dt class="col-sm-6">{{ __('Discount') }}</dt>
                <dd class="col-sm-6" data-role="terms-discount">
                    {{ __('This is a permanent agreed price: it continues for as long as you subscribe (the standard plan price would be :price).', ['price' => $money($terms->standardPriceMinor())]) }}
                </dd>
            @endif
        </dl>

        <p class="text-muted small mt-1 mb-0" data-role="terms-footnote">
            @if ($isChange)
                {{ __('Nothing is charged today. Your billing dates stay the same.') }}
            @elseif ($terms->trialDays() !== null)
                {{ __('A payment method is required now so your subscription renews automatically after the free trial. You can cancel any time before then and you will not be charged.') }}
            @else
                {{ __('Your subscription renews automatically :cycle until you cancel.', ['cycle' => $label]) }}
            @endif
            {{ __('Dates are in UTC.') }}
        </p>
    </div>
</div>
