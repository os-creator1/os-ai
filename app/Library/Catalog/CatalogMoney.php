<?php

namespace App\Library\Catalog;

use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Library\Money\CurrencyExponent;
use App\Library\Money\Exceptions\UnsupportedCurrencyException;

/**
 * Implementation Contract 16 §5.1, §12.E — PRESENTATION-ONLY conversion
 * between what a person types ("49.99") and what the domain stores (a whole
 * count of minor currency units, `4999`).
 *
 * THIS IS NOT A DOMAIN RULE AND DOES NOT DUPLICATE ONE. `CatalogItemManager`
 * and `CatalogItemLocationOverrideManager` remain the only authority on what a
 * valid price is (non-negative, co-nullable with the currency, bounded); this
 * class only translates the text a form submitted into the integer those
 * managers demand, and back. It validates nothing the managers own.
 *
 * EXACT STRING ARITHMETIC, NEVER FLOATS. `CatalogItemManager::parsePriceMinor()`
 * documents "deliberately no floating-point money parsing" and refuses a
 * fractional value outright. `(int) round($amount * 100)` — which the CRM's
 * `CrmMoney` uses for its two-decimal-only deal values — would reintroduce
 * exactly the rounding the domain forbids, and would be flatly wrong for a
 * zero-decimal currency such as JPY. So the amount is split on the decimal
 * point as text and scaled by the currency's real exponent
 * (`CurrencyExponent`), and an amount with more fractional digits than the
 * currency has is REFUSED rather than silently rounded — money is never
 * rounded on the customer's behalf.
 *
 * FAIL CLOSED ON THE CURRENCY. `CurrencyExponent` deliberately supports only a
 * listed set of currencies; an unlisted code is refused here with a clear
 * message rather than guessed at two decimals.
 */
final class CatalogMoney
{
    /** Longest digit string that always fits a signed 64-bit integer. */
    private const MAX_DIGITS = 18;

    private function __construct()
    {
    }

    /**
     * Text the person typed -> whole minor units. Blank means "no price"
     * (`null`); the co-nullable price/currency invariant itself is the
     * managers' to enforce.
     *
     * @throws CatalogRuleException with a customer-facing message.
     */
    public static function toMinor(?string $amount, ?string $currencyCode): ?int
    {
        $amount = $amount === null ? '' : trim($amount);

        if ($amount === '') {
            return null;
        }

        $currencyCode = $currencyCode === null ? '' : trim($currencyCode);

        if ($currencyCode === '') {
            // Without a currency there is no exponent to scale by, and
            // returning null here would silently DROP a price the person
            // typed. Refuse instead, so the domain never sees a lie.
            throw new CatalogRuleException('Set both a price and a currency, or leave both blank for a quote-only item.');
        }

        $exponent = self::exponent($currencyCode);

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $parts) !== 1) {
            throw new CatalogRuleException('Enter the price as a plain number such as 49.99.');
        }

        $whole = $parts[1];
        $fraction = $parts[2] ?? '';

        if (strlen($fraction) > $exponent) {
            throw new CatalogRuleException(
                $exponent === 0
                    ? strtoupper($currencyCode) . ' prices have no decimal places.'
                    : strtoupper($currencyCode) . ' prices use at most ' . $exponent . ' decimal places.'
            );
        }

        $digits = ltrim($whole . str_pad($fraction, $exponent, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;

        if (strlen($digits) > self::MAX_DIGITS) {
            throw new CatalogRuleException('The price is too large to store.');
        }

        return (int) $digits;
    }

    /** Whole minor units -> the plain decimal a form field should show. */
    public static function toInput(?int $minor, ?string $currencyCode): string
    {
        if ($minor === null || $currencyCode === null || $currencyCode === '') {
            return '';
        }

        $exponent = self::exponentOrNull($currencyCode);

        if ($exponent === null || $exponent === 0) {
            return (string) $minor;
        }

        $padded = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);

        return substr($padded, 0, -$exponent) . '.' . substr($padded, -$exponent);
    }

    /**
     * Whole minor units -> display text, e.g. "USD 49.99". A currency the
     * exponent table does not know is shown as its raw minor amount rather
     * than guessed at, so the page never displays a wrong figure.
     */
    public static function format(?int $minor, ?string $currencyCode): string
    {
        if ($minor === null) {
            return '—';
        }

        $currencyCode = $currencyCode === null ? '' : strtoupper(trim($currencyCode));
        $exponent = $currencyCode === '' ? null : self::exponentOrNull($currencyCode);

        if ($exponent === null) {
            return trim($currencyCode . ' ' . $minor . ' (minor units)');
        }

        // intdiv/% on integers, never `/`: a float division silently loses
        // precision for a large minor-unit count.
        $scale = 10 ** $exponent;
        $grouped = number_format(intdiv($minor, $scale));
        $fraction = $exponent === 0 ? '' : '.' . str_pad((string) ($minor % $scale), $exponent, '0', STR_PAD_LEFT);

        return $currencyCode . ' ' . $grouped . $fraction;
    }

    private static function exponent(string $currencyCode): int
    {
        try {
            return CurrencyExponent::for($currencyCode);
        } catch (UnsupportedCurrencyException) {
            throw new CatalogRuleException(
                'Prices in ' . strtoupper($currencyCode) . ' cannot be entered here yet. Use a supported currency such as USD.'
            );
        }
    }

    private static function exponentOrNull(string $currencyCode): ?int
    {
        try {
            return CurrencyExponent::for($currencyCode);
        } catch (UnsupportedCurrencyException) {
            return null;
        }
    }
}
