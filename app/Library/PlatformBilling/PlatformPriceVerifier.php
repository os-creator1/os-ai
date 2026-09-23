<?php

namespace App\Library\PlatformBilling;

use App\Exceptions\PlatformBilling\PlatformBillingException;

/**
 * Implementation Contract 21 §11 — THE PARITY CHECK.
 *
 * > The local price / currency / cycle and the Stripe Price MUST represent the
 * > same commercial terms.
 *
 * A format check on `price_[A-Za-z0-9]{6,}` proves only that the operator
 * typed something Price-shaped. It cannot stop the catalog saying
 * **€297 / yearly** while Stripe actually charges **$99 / monthly** — a
 * silently wrong charge on every subscriber, which is the worst class of
 * defect this lane can have.
 *
 * So before ANY catalog mutation, the Price is retrieved from the PLATFORM
 * OWNER's own Stripe account and every commercial fact is compared:
 *
 *   1. retrievable at all — a Price on a connected account (lane B's Business
 *      or lane C's Agency) is not reachable with the platform key, so this
 *      step is also the cross-lane boundary;
 *   2. `active` — "whether the price can be used for new purchases";
 *   3. recurring, not one-time;
 *   4. currency equals the selected V1 currency;
 *   5. amount equals the submitted catalog amount EXACTLY, in the smallest
 *      currency unit, using Stripe's own zero-decimal rules;
 *   6. monthly maps to interval `month`, yearly to `year`;
 *   7. `interval_count` is exactly 1 — an `interval=month, interval_count=3`
 *      Price bills quarterly, which is not what a "monthly" catalog row says;
 *   8. livemode matches the platform's configured mode, so a test Price can
 *      never be put on sale against a live key or the reverse.
 *
 * FAIL CLOSED, AND BEFORE ANY WRITE. This performs the network call and
 * raises; the caller has not touched the catalog or the pricing history yet,
 * so a failed verification leaves zero rows changed. It holds no lock and
 * opens no transaction — §5's "no provider call inside a transaction".
 */
final class PlatformPriceVerifier
{
    public function __construct(private readonly PlatformStripeGateway $gateway)
    {
    }

    /**
     * @param  string  $amount        the submitted catalog price, as a decimal string
     * @param  string  $currencyCode  the selected V1 currency's ISO code
     * @param  string  $billingCycle  `monthly` or `yearly`
     * @return array<int, string>     the reasons it does NOT match; empty means it does
     *
     * @throws PlatformBillingException when the Price cannot be retrieved at all
     */
    public function mismatches(
        string $providerPriceId,
        string $amount,
        string $currencyCode,
        string $billingCycle,
    ): array {
        // Raises PRICE_NOT_RETRIEVABLE, which is also what a connected-account
        // Price produces: it is simply not visible to the platform key.
        $price = $this->gateway->retrievePrice($providerPriceId);

        $reasons = [];

        if (! $price->active) {
            $reasons[] = 'That Stripe Price is not active, so it cannot be used for new purchases.';
        }

        if (! $price->recurring) {
            $reasons[] = 'That Stripe Price is a one-time price, not a recurring subscription price.';
        }

        if (mb_strtoupper($price->currency) !== mb_strtoupper(trim($currencyCode))) {
            $reasons[] = sprintf(
                'That Stripe Price is in %s, but this plan is priced in %s.',
                mb_strtoupper($price->currency),
                mb_strtoupper(trim($currencyCode)),
            );
        }

        $expectedMinor = CurrencyMinorUnits::toMinor($amount, $currencyCode);

        if ($expectedMinor === null) {
            $reasons[] = 'That amount cannot be expressed exactly in this currency.';
        } elseif ($price->unitAmount !== $expectedMinor) {
            $reasons[] = sprintf(
                'That Stripe Price charges %s, but this plan says %s.',
                $this->describe($price->unitAmount, $price->currency),
                $this->describe($expectedMinor, $currencyCode),
            );
        }

        $expectedInterval = $this->intervalFor($billingCycle);

        if ($expectedInterval === null) {
            $reasons[] = 'That billing cycle is not supported.';
        } elseif ($price->recurring && $price->interval !== $expectedInterval) {
            $reasons[] = sprintf(
                'That Stripe Price bills every %s, but this plan is %s.',
                (string) $price->interval,
                $billingCycle,
            );
        }

        if ($price->recurring && $price->intervalCount !== null && $price->intervalCount !== 1) {
            $reasons[] = sprintf(
                'That Stripe Price bills every %d intervals, which is not a plain %s plan.',
                $price->intervalCount,
                $billingCycle,
            );
        }

        $mode = $this->gateway->configurationStatus()['mode'];

        if ($mode === null) {
            $reasons[] = 'This platform\'s Stripe API key is not configured, so a Price cannot be verified.';
        } elseif ($price->livemode !== ($mode === 'live')) {
            $reasons[] = sprintf(
                'That Stripe Price is a %s-mode Price, but this platform is configured in %s mode.',
                $price->livemode ? 'live' : 'test',
                $mode,
            );
        }

        return $reasons;
    }

    private function intervalFor(string $billingCycle): ?string
    {
        return match (mb_strtolower(trim($billingCycle))) {
            'monthly' => 'month',
            'yearly' => 'year',
            default => null,
        };
    }

    /** Operator-facing, and deliberately never a raw provider string. */
    private function describe(?int $minor, string $currencyCode): string
    {
        if ($minor === null) {
            return 'no amount';
        }

        $factor = CurrencyMinorUnits::factor($currencyCode);
        $major = $factor === 1 ? (string) $minor : number_format($minor / 100, 2, '.', '');

        return $major . ' ' . mb_strtoupper($currencyCode);
    }
}
