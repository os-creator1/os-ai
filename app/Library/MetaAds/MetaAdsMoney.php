<?php

namespace App\Library\MetaAds;

use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\Dashboard\DashboardMoney;
use App\Library\Money\CurrencyExponent;

/**
 * Meta Ads Module V1 contract §5.1 — the ONE owner of decimal-string <->
 * micros <-> minor-unit <-> display for Meta Ads money (a deliberate copy of
 * the GoogleAdsMoney philosophy under a Meta name).
 *
 * Stored spend is BIGINT micros in the ad account's currency. Meta sends spend
 * as a decimal STRING in MAJOR units ("12.34"); it is parsed with exact string
 * math (bcmath) and NEVER through a float. More than six fractional digits,
 * a negative, or non-numeric text is an unexpected provider response — never
 * rounded silently. Budgets arrive as integer MINOR units and are stored as
 * `*_minor`; the helpers below are CurrencyExponent-aware and fail closed on
 * an unlisted currency.
 *
 * NULL IS NOT ZERO: absent input yields null, never 0.
 */
final class MetaAdsMoney
{
    public const MICROS_PER_UNIT = 1_000_000;

    private const NOT_AVAILABLE = '—';

    private const FALLBACK_EXPONENT = 2;

    /**
     * Parses a Meta decimal string ("250", "250.5", "0.000001") into micros.
     * null / "" -> null. Floats are refused (Meta never sends one for spend;
     * accepting one would invite float money).
     *
     * @throws MetaProviderException unexpected_response for a negative, a
     *                               non-numeric value, more than 6 fractional digits or int64 overflow
     */
    public static function parseToMicros(string|int|null $decimal): ?int
    {
        if ($decimal === null) {
            return null;
        }

        $text = trim((string) $decimal);

        if ($text === '') {
            return null;
        }

        if (preg_match('/\A\d+(\.\d{1,6})?\z/', $text) !== 1) {
            throw MetaProviderException::unexpectedResponse();
        }

        $micros = bcmul($text, (string) self::MICROS_PER_UNIT, 0);

        if (bccomp($micros, (string) PHP_INT_MAX, 0) > 0) {
            throw MetaProviderException::unexpectedResponse();
        }

        return (int) $micros;
    }

    /**
     * Non-throwing variant: null for anything parseToMicros() would reject.
     */
    public static function tryParseToMicros(string|int|null $decimal): ?int
    {
        try {
            return self::parseToMicros($decimal);
        } catch (MetaProviderException) {
            return null;
        }
    }

    /**
     * Exact decimal string for a micro amount, rounded half away from zero
     * to $scale places (0..6). null in, null out.
     */
    public static function microsToDecimalString(?int $micros, int $scale = 6): ?string
    {
        if ($micros === null) {
            return null;
        }

        $scale = max(0, min(6, $scale));
        $negative = $micros < 0;
        $abs = ltrim((string) $micros, '-');

        $exact = bcdiv($abs, (string) self::MICROS_PER_UNIT, 6);
        $rounded = $scale === 6
            ? $exact
            : bcadd($exact, '0.' . str_repeat('0', $scale) . '5', $scale);

        $isZero = bccomp($rounded, '0', $scale) === 0;

        return ($negative && ! $isZero ? '-' : '') . $rounded;
    }

    /**
     * Parses a Meta budget (integer MINOR units, sent as a digit string such
     * as "5000") to int. null / "" -> null.
     *
     * @throws MetaProviderException unexpected_response for anything but non-negative digits
     */
    public static function parseMinor(string|int|null $minor): ?int
    {
        if ($minor === null) {
            return null;
        }

        $text = trim((string) $minor);

        if ($text === '') {
            return null;
        }

        if (preg_match('/\A\d{1,18}\z/', $text) !== 1) {
            throw MetaProviderException::unexpectedResponse();
        }

        return (int) $text;
    }

    /**
     * Minor units (cents / whole units for zero-decimal currencies) -> micros.
     *
     * @throws \App\Library\Money\Exceptions\UnsupportedCurrencyException for an unlisted currency
     */
    public static function minorToMicros(int $minor, string $currencyCode): int
    {
        $exponent = CurrencyExponent::for(strtoupper(trim($currencyCode)));

        return $minor * (10 ** (6 - $exponent));
    }

    /**
     * Micros -> minor units, rounded half up (away from zero for negatives).
     *
     * @throws \App\Library\Money\Exceptions\UnsupportedCurrencyException for an unlisted currency
     */
    public static function microsToMinor(int $micros, string $currencyCode): int
    {
        $exponent = CurrencyExponent::for(strtoupper(trim($currencyCode)));
        $divisor = 10 ** (6 - $exponent);
        $abs = abs($micros);
        $minor = intdiv($abs, $divisor) + (($abs % $divisor) * 2 >= $divisor ? 1 : 0);

        return $micros < 0 ? -$minor : $minor;
    }

    /**
     * Customer-facing amount in the account currency, e.g. "USD 1,234.50".
     * "—" for null. An unlisted currency still displays (two decimals).
     */
    public static function format(?int $micros, ?string $currencyCode): string
    {
        if ($micros === null) {
            return self::NOT_AVAILABLE;
        }

        $currency = strtoupper(trim((string) $currencyCode));
        $exponent = $currency !== '' && CurrencyExponent::isSupported($currency)
            ? CurrencyExponent::for($currency)
            : self::FALLBACK_EXPONENT;

        $decimal = (string) self::microsToDecimalString($micros, $exponent);

        if ($exponent === 2) {
            return DashboardMoney::format(bcmul($decimal, (string) self::MICROS_PER_UNIT, 0), $currency);
        }

        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '');
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '')
            . ($currency !== '' ? $currency . ' ' : '')
            . $grouped
            . ($fraction !== '' ? '.' . $fraction : '');
    }

    /**
     * Cost per result in micros (rounded half up), or null when there is
     * nothing to divide by (null / non-positive results, null / negative
     * cost) or the quotient overflows int64. Never 0 for "unknown".
     */
    public static function costPerResult(?int $costMicros, ?int $results): ?int
    {
        if ($costMicros === null || $costMicros < 0 || $results === null || $results <= 0) {
            return null;
        }

        $quotient = bcadd(bcdiv((string) $costMicros, (string) $results, 6), '0.5', 0);

        return bccomp($quotient, (string) PHP_INT_MAX, 0) > 0 ? null : (int) $quotient;
    }
}
