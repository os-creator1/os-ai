<?php

declare(strict_types=1);

namespace App\Library\Money;

use App\Library\Money\Exceptions\UnsupportedCurrencyException;

/**
 * A provider-neutral, lane-neutral conversion from RFC-005's internal
 * "micro-units" money representation (1 micro-unit = 1/1,000,000 of a
 * currency's major unit — RFC-005 §12's own definition) into ISO minor
 * units, sized by {@see CurrencyExponent}'s own exponent.
 *
 * WHY THIS EXISTS, RATHER THAN REUSING
 * `App\Library\Usage\UsageBillingCheckoutManager::microToMinorUnits()`. That
 * method is private, lives in lane D, and floors — correct for that
 * caller's own purpose (the exact amount a provider will actually charge),
 * wrong for a caller that must display an UPPER BOUND before it is
 * approved: flooring a fractional minor unit would understate the ceiling
 * a customer is asked to confirm. This class is deliberately small, lives
 * outside `App\Library\Usage` (no lane-D dependency, mirroring
 * {@see CurrencyExponent}'s own isolation), and holds no currency list of
 * its own — it defers entirely to `CurrencyExponent::for()` for the
 * exponent, so there is exactly one place a currency's decimal precision is
 * decided anywhere in this codebase's non-lane-D code.
 *
 * CEILING, NEVER FLOOR, NEVER A FLOAT. Every amount is exact bcmath integer
 * arithmetic. A micro amount that is not an exact multiple of one minor
 * unit rounds UP: 1,230,000 micro → 123 minor (USD, exact); 1,230,001
 * micro → 124 minor (USD, ceiled) — the ceiling this class exists to
 * compute is a promise to the customer that the true cost is AT MOST the
 * displayed/approved figure, never a cent under it.
 *
 * FAIL CLOSED. An unsupported or malformed currency code is refused by
 * `CurrencyExponent::for()` itself (`UnsupportedCurrencyException`); this
 * class adds no fallback and invents no default exponent.
 */
final class MicroAmountConverter
{
    /** RFC-005 §12 — 1 micro-unit = 1/1,000,000 of a currency's major unit. */
    public const MICRO_PER_MAJOR = 1_000_000;

    private function __construct()
    {
    }

    /**
     * @throws UnsupportedCurrencyException
     */
    public static function ceilToMinorUnits(int $amountMicro, string $currencyCode): int
    {
        $divisor = self::microPerMinorUnit($currencyCode);

        // Ceiling division for a non-negative numerator via exact integer
        // arithmetic: ceil(a / b) == floor((a + b - 1) / b). bcdiv with
        // scale 0 truncates toward zero, which is floor for a non-negative
        // dividend — exactly what this identity needs.
        return (int) bcdiv(bcadd((string) $amountMicro, bcsub($divisor, '1')), $divisor, 0);
    }

    /**
     * How many micro-units make one minor unit of this currency —
     * `1_000_000 / 10^exponent`, expressed as an exact bcmath string so the
     * division above never touches a float.
     *
     * @throws UnsupportedCurrencyException
     */
    private static function microPerMinorUnit(string $currencyCode): string
    {
        return bcdiv((string) self::MICRO_PER_MAJOR, (string) CurrencyExponent::minorUnitsPerMajor($currencyCode), 0);
    }
}
