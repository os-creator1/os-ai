<?php

namespace App\Library\PlatformBilling\Offers;

use App\Library\Money\StripeMinorUnits;

/** Minor-unit money for lane A. Integers only: no float ever touches a price. */
final class PlatformMoney
{
    /**
     * Stripe will not charge below roughly 0.50 in most currencies. A price
     * under this is refused when an offer is built rather than failing at
     * Checkout in front of the customer.
     */
    public const MIN_CHARGE_MINOR = 50;

    public static function toMinor(string|int|float|null $amount, string $currencyCode): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return StripeMinorUnits::toMinor(is_string($amount) ? $amount : (string) $amount, $currencyCode);
    }

    /** "99.00" for 9900 USD; "9900" for a zero-decimal currency. */
    public static function toDecimal(int $minor, string $currencyCode): string
    {
        return StripeMinorUnits::factor($currencyCode) === 1
            ? (string) $minor
            : sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    /**
     * Customer-facing text. `$format` is the Currency row's display pattern
     * (`{PRICE}` placeholder) when it has one; otherwise "99.00 USD".
     */
    public static function display(int $minor, string $currencyCode, ?string $format = null): string
    {
        $amount = self::toDecimal($minor, $currencyCode);

        return $format !== null && str_contains($format, '{PRICE}')
            ? str_replace('{PRICE}', $amount, $format)
            : $amount . ' ' . mb_strtoupper($currencyCode);
    }

    /** Percent (two decimals) off an amount, rounded half up, in integers. */
    public static function applyPercentOff(int $minor, string $percent): int
    {
        $basisPoints = (int) round(((float) $percent) * 100);

        return intdiv($minor * (10000 - $basisPoints) + 5000, 10000);
    }
}
