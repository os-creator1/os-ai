{{--
    The EXACT terms of a Private Offer, as the customer would be bound by them,
    in the premium offer-summary card of the Private Offer shell.

    $terms is an OfferTerms; every figure comes from it (and so from the same
    calculation the server re-runs when the customer accepts) — nothing
    commercial is written here. Money is rendered from integer minor units.

    Nothing in this card is fabricated: the crossed-out price appears only when
    a real promotion makes the standard price higher than the price charged, the
    trial pill only when a trial is part of the terms, the permanent badge only
    for a negotiated price with no end date.
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
    $showWas = $promotion !== null && $terms->standardPriceMinor() > (int) $first['price_minor'];
@endphp

<div class="pof-card" data-role="offer-terms">
    <div class="pof-card-body">
        <div class="pof-card-head">
            <h2 class="pof-plan" data-role="terms-plan">{{ $terms->get('plan_name') }}</h2>
            @if (! $isChange && $terms->trialDays() !== null)
                <span class="pof-pill pof-pill-trial" data-role="terms-trial">{{ trans_choice('{1} :count day free|[2,*] :count days free', $terms->trialDays(), ['count' => $terms->trialDays()]) }}</span>
            @endif
        </div>

        <div class="pof-price-row" data-role="terms-recurring">
            <span class="pof-price">{{ $money((int) $first['price_minor']) }}</span>
            @if ($showWas)
                <span class="pof-was" data-role="terms-standard-price"><span class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">{{ __('Standard price') }} </span>{{ $money($terms->standardPriceMinor()) }}</span>
            @endif
            <span class="pof-cycle" data-role="terms-frequency">{{ $label }}</span>
        </div>

        @if ($promotion !== null && $hasPromoEnd)
            <span class="pof-pill pof-pill-warn" data-role="terms-promo-badge">{{ __('Temporary promotional price') }}</span>
        @elseif ($negotiated)
            <span class="pof-pill pof-pill-lock" data-role="terms-negotiated-badge">
                <svg class="pof-ic" viewBox="0 0 24 24" aria-hidden="true" style="width:14px;height:14px"><rect x="5" y="10.5" width="14" height="9.5" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/></svg>
                {{ __('Permanent agreed price') }}
            </span>
        @endif

        @if (! empty($plan['capabilities']))
            <ul class="pof-features" data-role="terms-features">
                @foreach ($plan['capabilities'] as $capability)
                    <li>
                        <span class="pof-tick" aria-hidden="true"><svg class="pof-ic" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
                        <span>{{ $capability }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="pof-facts">
        @if ($isChange)
            <div class="pof-fact">
                <div class="pof-fact-label">{{ __('Your current price') }}</div>
                <b class="pof-fact-value" data-role="terms-current-price">{{ $money((int) $terms->get('previous_price_minor')) }} {{ $label }}</b>
            </div>
        @else
            <div class="pof-fact">
                <div class="pof-fact-label">{{ __('Charged today') }}</div>
                <b class="pof-fact-value" data-role="terms-today">{{ $money($terms->todayChargeMinor()) }}</b>
            </div>
        @endif

        <div class="pof-fact">
            <div class="pof-fact-label">{{ $isChange ? __('Starts at your next renewal on') : __('First payment') }}</div>
            <b class="pof-fact-value" data-role="terms-first-payment">
                @if (! $isChange && $terms->todayChargeMinor() > 0)
                    {{ __('Today') }} ({{ $money($terms->todayChargeMinor()) }})
                @else
                    {{ $date($terms->get('first_payment_at')) }} ({{ $money((int) $terms->get('first_payment_minor')) }})
                @endif
            </b>
        </div>
    </div>

    @if ((! $isChange && $terms->trialDays() === null) || $hasPromoEnd || $promotion !== null)
        <dl class="pof-notes">
            @if (! $isChange && $terms->trialDays() === null)
                <dt>{{ __('Free trial') }}</dt>
                <dd data-role="terms-trial">
                    @if ($terms->get('trial_blocked'))
                        {{ __('Not available — a free trial has already been used on this account.') }}
                    @else
                        {{ __('None') }}
                    @endif
                </dd>
            @endif

            @if ($hasPromoEnd)
                <dt>{{ __('Promotional price ends') }}</dt>
                <dd data-role="terms-promo-end">{{ $date($promotion['ends_at']) }}</dd>

                <dt data-role="terms-after-label">{{ $negotiated ? __('Then your agreed ongoing price') : __('Then the standard plan price') }}</dt>
                <dd data-role="terms-after">{{ $money($terms->standardPriceMinor()) }} {{ $label }}</dd>
            @elseif ($promotion !== null)
                <dt>{{ __('Discount') }}</dt>
                <dd data-role="terms-discount">
                    {{ __('This is a permanent agreed price: it continues for as long as you subscribe (the standard plan price would be :price).', ['price' => $money($terms->standardPriceMinor())]) }}
                </dd>
            @endif
        </dl>
    @endif

    <div class="pof-footnote">
        <p data-role="terms-footnote">
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
