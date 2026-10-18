<?php

namespace App\Library\GoogleAds;

use App\Library\Dashboard\DashboardMoney;
use App\Library\Money\CurrencyExponent;

/**
 * Google Ads Module V1 contract §4 — the ONE owner of micros <-> decimal <->
 * display for Ads money. Stored money is BIGINT micros in the Ads ACCOUNT
 * currency; nothing else in the module may do its own micro arithmetic.
 *
 * All arithmetic is exact string math (bcmath): never a float for money.
 *
 * NULL IS NOT ZERO. Absent input yields null (shown "—"), never 0 or $0.00.
 * cpl() is null when there are no conversions; conversionRate() is null when
 * there are no clicks. A legitimate zero spend is "0.00", not "—".
 *
 * Display reuses DashboardMoney (two-decimal grouped format, identical to the
 * Usage & Billing page) and CurrencyExponent (zero / three-decimal currencies).
 * CurrencyExponent FAILS CLOSED on an unlisted currency code; for DISPLAY
 * only, format() falls back to two decimals with the code in front (still a
 * correct amount, just not exponent-trimmed) rather than breaking a page.
 * Anything that would feed minor-unit math must call CurrencyExponent::for()
 * itself and let it throw.
 */
final class GoogleAdsMoney
{
    public const MICROS_PER_UNIT = 1_000_000;

    private const NOT_AVAILABLE = '—';

    private const FALLBACK_EXPONENT = 2;

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
     * Parses a non-negative decimal amount ("250", "250.5", 19.99) into
     * micros. Returns null for null, an empty string, a negative, anything
     * with more than 6 decimals, non-numeric text, or a value that does not
     * fit a signed 64-bit integer — never a guess.
     */
    public static function toMicros(string|int|float|null $decimal): ?int
    {
        if ($decimal === null) {
            return null;
        }

        if (is_float($decimal)) {
            if (is_nan($decimal) || is_infinite($decimal)) {
                return null;
            }

            $decimal = number_format($decimal, 6, '.', '');
        }

        $text = trim((string) $decimal);

        if (preg_match('/\A\d+(\.\d{1,6})?\z/', $text) !== 1) {
            return null;
        }

        $micros = bcmul($text, (string) self::MICROS_PER_UNIT, 0);

        return bccomp($micros, (string) PHP_INT_MAX, 0) > 0 ? null : (int) $micros;
    }

    /**
     * Customer-facing amount in the account currency, e.g. "USD 1,234.50".
     * "—" for null.
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
            // Already rounded to cents, so DashboardMoney's truncation is exact.
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
     * Cost per conversion in micros (rounded half up), or null when there
     * is nothing to divide by: null or non-positive conversions, null or
     * negative cost, a non-numeric input, or a result that overflows int64.
     * Never 0 as a stand-in for "unknown".
     */
    public static function cpl(?int $costMicros, int|float|string|null $conversions): ?int
    {
        $conv = self::decimal($conversions);

        if ($costMicros === null || $costMicros < 0 || $conv === null || bccomp($conv, '0', 6) <= 0) {
            return null;
        }

        $quotient = bcadd(bcdiv((string) $costMicros, $conv, 6), '0.5', 0);

        return bccomp($quotient, (string) PHP_INT_MAX, 0) > 0 ? null : (int) $quotient;
    }

    /**
     * Conversions per click, or null when conversions are absent or there
     * were no clicks. Zero conversions on real clicks is a true 0.0.
     */
    public static function conversionRate(int|float|string|null $conversions, ?int $clicks): ?float
    {
        $conv = self::decimal($conversions);

        if ($conv === null || bccomp($conv, '0', 6) < 0 || $clicks === null || $clicks <= 0) {
            return null;
        }

        return (float) bcdiv($conv, (string) $clicks, 10);
    }

    /** Normalises a conversions input to a 6-place numeric string, or null. */
    private static function decimal(int|float|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            $value = number_format($value, 6, '.', '');
        }

        $text = trim((string) $value);

        return preg_match('/\A-?\d+(\.\d+)?\z/', $text) === 1 ? bcadd($text, '0', 6) : null;
    }
}
