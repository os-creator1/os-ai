<?php

namespace App\Library\PlatformBilling\Offers;

use App\Library\PlatformBilling\BillingCycle;
use App\Models\PlatformPrivateOffer;
use App\Models\WorkspacePlanCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Turns a Private Offer plus a plan into the EXACT terms a customer would be
 * on. Pure: no database, no Stripe, no clock of its own — which is what makes
 * every number on the redemption page reproducible and testable.
 *
 * THE MODEL, stated once:
 *
 *   standard price  the permanent NEGOTIATED recurring price when the owner set
 *                   one, otherwise the plan's own catalog price. This is the
 *                   explicitly agreed ongoing price.
 *   discount        a fixed amount or a percentage off the standard price.
 *   duration        forever | one billing period | N billing periods. A
 *                   TEMPORARY promotion is distinct from a permanent negotiated
 *                   price: when it ends the price AUTOMATICALLY reverts to the
 *                   negotiated price if there is one, otherwise the catalog
 *                   price. A "forever" discount never ends.
 *   trial           free days before the first payment (new subscriptions only).
 *
 * EVERY PHASE IS A PLAIN PRICE. A temporary promotion is "N periods at the
 * discounted price, then the standard price" — never a coupon, whose
 * repeating duration is counted in calendar months and so cannot express an
 * exact number of 28-day periods.
 */
final class OfferTermsCalculator
{
    /**
     * @param  array{amount_minor: int, effective_at: CarbonInterface}|null  $existing  for a billing change: the price the
     *         subscription is on now and the instant the new terms begin (next renewal)
     *
     * @throws InvalidArgumentException when the offer cannot be expressed as valid terms
     */
    public function calculate(
        PlatformPrivateOffer $offer,
        WorkspacePlanCatalog $catalog,
        string $currencyCode,
        bool $trialEligible,
        CarbonInterface $now,
        ?array $existing = null,
    ): OfferTerms {
        $now = CarbonImmutable::instance($now);
        $cycle = (string) $catalog->billing_cycle;
        $recurrence = BillingCycle::stripeRecurrence($cycle);

        if ($recurrence === null || $catalog->price === null) {
            throw new InvalidArgumentException(__('That plan does not have a price and billing cycle yet.'));
        }

        $planMinor = PlatformMoney::toMinor((string) $catalog->price, $currencyCode);

        if ($planMinor === null) {
            throw new InvalidArgumentException(__('The plan price cannot be expressed exactly in this currency.'));
        }

        $isChange = $offer->isBillingChange();

        $standardMinor = $planMinor;
        $standardSource = 'catalog';

        if ($offer->custom_price !== null) {
            $custom = PlatformMoney::toMinor((string) $offer->custom_price, $currencyCode);

            if ($custom === null) {
                throw new InvalidArgumentException(__('The custom price cannot be expressed exactly in this currency.'));
            }

            $standardMinor = $custom;
            $standardSource = 'negotiated';
        }

        $this->assertChargeable($standardMinor, __('The recurring price'));

        // ---- discount -----------------------------------------------------
        $promotion = null;
        $promoMinor = null;
        $promoPeriods = null;

        if ($offer->discount_type !== null) {
            $promoMinor = match ($offer->discount_type) {
                PlatformPrivateOffer::DISCOUNT_FIXED => $standardMinor - (int) (PlatformMoney::toMinor((string) $offer->discount_value, $currencyCode) ?? throw new InvalidArgumentException(__('The discount cannot be expressed exactly in this currency.'))),
                PlatformPrivateOffer::DISCOUNT_PERCENT => PlatformMoney::applyPercentOff($standardMinor, (string) $offer->discount_value),
                default => throw new InvalidArgumentException(__('Unknown discount type.')),
            };

            if ($promoMinor >= $standardMinor) {
                throw new InvalidArgumentException(__('The discount must reduce the price.'));
            }

            $this->assertChargeable($promoMinor, __('The discounted price'));

            $duration = (string) $offer->discount_duration;
            $promoPeriods = match ($duration) {
                PlatformPrivateOffer::DURATION_FOREVER => null,
                PlatformPrivateOffer::DURATION_ONCE => 1,
                PlatformPrivateOffer::DURATION_PERIODS => max(1, (int) $offer->discount_periods),
                default => throw new InvalidArgumentException(__('Say how long the discount lasts.')),
            };

            if ($duration === PlatformPrivateOffer::DURATION_PERIODS && (int) $offer->discount_periods < 2) {
                throw new InvalidArgumentException(__('A discount lasting several billing periods needs at least 2 periods; use "one billing period" for 1.'));
            }

            if ($promoPeriods !== null && $promoPeriods > 120) {
                throw new InvalidArgumentException(__('A discount can last at most 120 billing periods; use "forever" for longer.'));
            }

            $promotion = [
                'discount_type' => $offer->discount_type,
                'discount_value' => (string) $offer->discount_value,
                'duration' => $duration,
                'periods' => $promoPeriods,
                'price_minor' => $promoMinor,
            ];
        }

        // ---- trial --------------------------------------------------------
        $requestedTrial = $offer->trial_days === null ? null : (int) $offer->trial_days;

        if ($isChange && $requestedTrial !== null) {
            throw new InvalidArgumentException(__('A free trial cannot be added to an existing subscription.'));
        }

        $trialDays = (! $isChange && $requestedTrial !== null && $trialEligible) ? $requestedTrial : null;

        // ---- timeline -----------------------------------------------------
        $phases = [];

        if ($isChange) {
            if ($existing === null) {
                throw new InvalidArgumentException(__('A billing change needs the subscription it applies to.'));
            }

            $cursor = CarbonImmutable::instance($existing['effective_at']);
        } else {
            $cursor = $now;

            if ($trialDays !== null) {
                $trialEnd = $cursor->addDays($trialDays);
                $phases[] = [
                    'kind' => 'trial', 'price_minor' => 0, 'periods' => null,
                    'starts_at' => $cursor->toIso8601String(), 'ends_at' => $trialEnd->toIso8601String(),
                ];
                $cursor = $trialEnd;
            }
        }

        $firstPayment = $cursor;

        if ($promoMinor !== null && $promoPeriods !== null) {
            $promoEnd = BillingCycle::addPeriods($cursor, $cycle, $promoPeriods);
            $phases[] = [
                'kind' => 'promotional', 'price_minor' => $promoMinor, 'periods' => $promoPeriods,
                'starts_at' => $cursor->toIso8601String(), 'ends_at' => $promoEnd->toIso8601String(),
            ];
            $phases[] = [
                'kind' => 'standard', 'price_minor' => $standardMinor, 'periods' => null,
                'starts_at' => $promoEnd->toIso8601String(), 'ends_at' => null,
            ];
            $promotion['ends_at'] = $promoEnd->toIso8601String();
            $recurringAfter = $standardMinor;
        } elseif ($promoMinor !== null) {
            // Forever: the discounted price simply IS the recurring price.
            $phases[] = [
                'kind' => 'standard', 'price_minor' => $promoMinor, 'periods' => null,
                'starts_at' => $cursor->toIso8601String(), 'ends_at' => null, 'discounted' => true,
            ];
            $promotion['ends_at'] = null;
            $recurringAfter = $promoMinor;
        } else {
            $phases[] = [
                'kind' => 'standard', 'price_minor' => $standardMinor, 'periods' => null,
                'starts_at' => $cursor->toIso8601String(), 'ends_at' => null,
            ];
            $recurringAfter = $standardMinor;
        }

        foreach ($phases as $index => $phase) {
            $phases[$index]['price_id'] = null;
        }

        $firstPaidMinor = (int) (array_values(array_filter($phases, static fn (array $p): bool => $p['kind'] !== 'trial'))[0]['price_minor']);

        return OfferTerms::fromArray([
            'version' => 1,
            'kind' => $offer->kind,
            'catalog_id' => (int) $catalog->id,
            'tier' => $catalog->tier->value,
            'plan_name' => (string) $catalog->display_name,
            'currency_code' => mb_strtoupper($currencyCode),
            'currency_id' => (int) $offer->currency_id,
            'cycle' => $cycle,
            'cycle_label' => BillingCycle::label($cycle),
            'interval' => $recurrence[0],
            'interval_count' => $recurrence[1],
            'plan_price_minor' => $planMinor,
            'standard_price_minor' => $standardMinor,
            // WHERE THE ONGOING PRICE COMES FROM — the one fact the customer's
            // agreement must make unmistakable:
            //   negotiated  a permanent price agreed for this customer; a
            //               temporary promotion reverts to THIS price
            //   catalog     the plan's normal price; a temporary promotion
            //               reverts to THIS price
            'standard_source' => $standardSource,
            'ongoing_label' => $standardSource === 'negotiated' ? 'Your agreed ongoing price' : 'The standard plan price',
            'trial_days_requested' => $requestedTrial,
            'trial_days' => $trialDays,
            'trial_blocked' => $requestedTrial !== null && $trialDays === null && ! $isChange,
            'today_charge_minor' => $isChange ? 0 : ($trialDays !== null ? 0 : $firstPaidMinor),
            'first_payment_at' => $firstPayment->toIso8601String(),
            'first_payment_minor' => $firstPaidMinor,
            'promotion' => $promotion,
            'recurring_after_promotion_minor' => $recurringAfter,
            'previous_price_minor' => $isChange ? (int) $existing['amount_minor'] : null,
            'phases' => $phases,
        ]);
    }

    /**
     * For a billing change: whether the customer must accept it. Anything that
     * could cost them MORE than they pay today, at any point, needs their yes.
     */
    public function requiresAcceptance(OfferTerms $terms): bool
    {
        $previous = $terms->get('previous_price_minor');

        if ($previous === null) {
            return true;
        }

        foreach ($terms->paidPhases() as $phase) {
            if ((int) $phase['price_minor'] > (int) $previous) {
                return true;
            }
        }

        return false;
    }

    private function assertChargeable(int $minor, string $what): void
    {
        if ($minor < PlatformMoney::MIN_CHARGE_MINOR) {
            throw new InvalidArgumentException(sprintf('%s is below the minimum the payment provider can charge.', $what));
        }
    }
}
