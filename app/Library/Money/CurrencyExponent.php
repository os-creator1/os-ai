<?php

declare(strict_types=1);

namespace App\Library\Money;

use App\Library\Money\Exceptions\AmountOutOfBoundsException;
use App\Library\Money\Exceptions\UnsupportedCurrencyException;

/**
 * Implementation Contract 17 §4.6/§12.A — a LANE-NEUTRAL currency exponent
 * value object: how many decimal places a currency's minor unit represents,
 * plus Stripe's charge-amount bounds.
 *
 * WHY THIS EXISTS. The equivalent knowledge lives PRIVATE inside
 * App\Library\Usage\UsageBillingCheckoutManager (lane D, forbidden to lane B —
 * Addendum §12, Contract 17 §4). It is re-derived here rather than reached
 * into or duplicated behind a lane-D dependency: this class lives outside
 * App\Library\Usage, calls nothing in it, and must never gain a dependency on
 * it (the §11.1 source-boundary test enforces that for the whole slice).
 *
 * Lane B money is integer MINOR units (unsignedBigInteger) + a char(3)
 * currency, matching Contract 16's package_snapshots and Stripe's wire format
 * exactly — never micro-units, which are lane D's sub-cent metering convention.
 * This class therefore does no micro conversion; it only answers exponent and
 * bounds questions.
 *
 * FAIL CLOSED. A currency code absent from all three tiers is a refusal, never
 * a silent two-decimal guess. The tiers mirror Stripe's own published
 * currency-decimal documentation as of the lane-D precedent; the list is
 * re-verified against current Stripe documentation when Sub-slice E is
 * implemented (§11.6), and is intentionally not "every currency Stripe
 * supports" — an unlisted code fails closed until someone adds it deliberately.
 *
 * BOUNDS. MINIMUM is the general 50-minor-unit baseline Stripe applies to the
 * large majority of currencies (the equivalent of $0.50 USD); a currency whose
 * exact minimum differs must have that figure re-verified before production.
 * MAXIMUM is Stripe's eight-digit ceiling for a PaymentIntent amount.
 */
final class CurrencyExponent
{
    /** Stripe's documented zero-decimal currencies. */
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Stripe's documented three-decimal currencies. */
    public const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** The two-decimal currencies this codebase has deliberately listed. */
    public const TWO_DECIMAL = ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'CHF', 'NZD', 'SGD', 'HKD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'MXN', 'BRL', 'INR', 'ZAR', 'AED', 'SAR'];

    public const MINIMUM_MINOR_UNITS = 50;

    public const MAXIMUM_MINOR_UNITS = 99_999_999;

    private function __construct()
    {
    }

    /**
     * The number of decimal places in the currency's minor unit (0, 2 or 3).
     *
     * @throws UnsupportedCurrencyException for anything not in the three tiers,
     *         including a malformed (non-3-letter) code.
     */
    public static function for(string $currencyCode): int
    {
        $code = self::normalize($currencyCode);

        return match (true) {
            in_array($code, self::ZERO_DECIMAL, true) => 0,
            in_array($code, self::THREE_DECIMAL, true) => 3,
            in_array($code, self::TWO_DECIMAL, true) => 2,
            default => throw new UnsupportedCurrencyException(
                "Unsupported currency code for minor-unit handling: {$currencyCode}"
            ),
        };
    }

    public static function isSupported(string $currencyCode): bool
    {
        try {
            self::for($currencyCode);

            return true;
        } catch (UnsupportedCurrencyException) {
            return false;
        }
    }

    /**
     * How many minor units make one major unit (10^exponent), as an int.
     */
    public static function minorUnitsPerMajor(string $currencyCode): int
    {
        return 10 ** self::for($currencyCode);
    }

    /**
     * Whether $minorUnits is inside Stripe's charge-amount bounds.
     */
    public static function isWithinChargeBounds(int $minorUnits): bool
    {
        return $minorUnits >= self::MINIMUM_MINOR_UNITS
            && $minorUnits <= self::MAXIMUM_MINOR_UNITS;
    }

    /**
     * Refuses an amount outside Stripe's documented bounds before any provider
     * call, never assuming an amount acceptable.
     *
     * @throws AmountOutOfBoundsException
     */
    public static function assertWithinChargeBounds(int $minorUnits): void
    {
        if ($minorUnits < self::MINIMUM_MINOR_UNITS) {
            throw new AmountOutOfBoundsException(
                "Amount {$minorUnits} is below the minimum charge amount."
            );
        }

        if ($minorUnits > self::MAXIMUM_MINOR_UNITS) {
            throw new AmountOutOfBoundsException(
                "Amount {$minorUnits} exceeds the eight-digit maximum charge amount."
            );
        }
    }

    /**
     * ISO-4217 codes are three letters; anything else is refused rather than
     * coerced. Case-insensitive on input, uppercase for comparison.
     */
    private static function normalize(string $currencyCode): string
    {
        $code = strtoupper($currencyCode);

        if (preg_match('/\A[A-Z]{3}\z/', $code) !== 1) {
            throw new UnsupportedCurrencyException(
                "Unsupported currency code for minor-unit handling: {$currencyCode}"
            );
        }

        return $code;
    }
}
