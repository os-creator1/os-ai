<?php

namespace App\Library\Money;

/**
 * Implementation Contract 21 §11 and Lane C §C3.6 — converting a decimal
 * catalog price into the SMALLEST CURRENCY UNIT Stripe charges in.
 *
 * SHARED BY LANE A AND LANE C, DELIBERATELY, AND IT LIVES HERE RATHER THAN IN
 * EITHER LANE. Two lanes holding two copies of Stripe's zero-decimal list and
 * the ISK/UGX whole-unit rule would eventually disagree about what the provider
 * is going to charge, and the first symptom would be a parity check passing
 * that should have failed. This class carries NO commercial identity — no
 * account, no customer, no record, no money — so sharing it breaks none of §2's
 * lane isolation, which is about who is paying whom.
 *
 * It is deliberately NOT `App\Library\Money\CurrencyExponent`: that one answers
 * a different question (ISO exponents, three-decimal currencies, minimum and
 * maximum bounds) for lane D, and throws where this one must answer null.
 *
 * WHY THIS EXISTS. "Multiply by 100" is wrong for roughly a sixth of the
 * currencies Stripe supports, and getting it wrong here would let the catalog
 * say ¥297 while Stripe charges ¥29,700 — or pass a parity check that should
 * have failed. §11's parity invariant is only as good as this conversion.
 *
 * THE LIST IS STRIPE'S OWN, not ISO 4217's, and the two disagree. Verified
 * against docs.stripe.com/currencies at implementation time:
 *
 *   Zero-decimal: BIF CLP DJF GNF JPY KMF KRW MGA PYG RWF VND VUV XAF XOF XPF
 *
 * Two documented SPECIAL CASES are deliberately treated as two-decimal here,
 * because this class converts CHARGE amounts:
 *
 *   - ISK and UGX "transitioned to a zero-decimal currency, but backward
 *     compatibility requires you to represent [them] as a two-decimal value,
 *     where the decimal amount is always 00". UGX therefore appears in
 *     Stripe's zero-decimal list AND in its special cases; for a charge, the
 *     special case wins. ISK is likewise two-decimal for charges.
 *   - HUF and TWD are zero-decimal for PAYOUTS only — "you can charge
 *     two-decimal amounts" — so they are ordinary two-decimal currencies here.
 *     They are not in the list below and need no special handling.
 *
 * Stripe's current currency documentation defines no three-decimal charge
 * currencies, so there is deliberately no three-decimal branch to get wrong.
 */
final class StripeMinorUnits
{
    /**
     * Stripe's zero-decimal charge currencies, MINUS UGX, which its own
     * special-case note requires to be sent as a two-decimal value.
     *
     * @var array<int, string>
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
        'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * ISK and UGX are sent as TWO-DECIMAL values "where the decimal amount is
     * always `00`", and Stripe states plainly that you "can't charge fractions
     * of ISK" / "of UGX".
     *
     * So factor 100 is right for them and a non-zero fraction is NOT: 297.50
     * ISK is not a chargeable amount, and accepting it would let the catalog
     * hold a price Stripe can never match — the exact class of parity failure
     * §11 exists to prevent.
     *
     * HUF and TWD are deliberately absent: they are ordinary two-decimal
     * currencies for CHARGES, and are zero-decimal only for payouts.
     *
     * @var array<int, string>
     */
    private const WHOLE_UNITS_ONLY = ['ISK', 'UGX'];

    /** How many minor units make one major unit of this currency. */
    public static function factor(string $currencyCode): int
    {
        return in_array(mb_strtoupper(trim($currencyCode)), self::ZERO_DECIMAL, true) ? 1 : 100;
    }

    public static function isZeroDecimal(string $currencyCode): bool
    {
        return self::factor($currencyCode) === 1;
    }

    /**
     * Two-decimal at the wire, but only whole units are chargeable — the
     * ISK/UGX rule.
     */
    public static function isWholeUnitsOnly(string $currencyCode): bool
    {
        return in_array(mb_strtoupper(trim($currencyCode)), self::WHOLE_UNITS_ONLY, true);
    }

    /**
     * A decimal catalog price ("297.00") to the integer Stripe charges in.
     *
     * STRING ARITHMETIC, NEVER A FLOAT. `(int) (2.97 * 100)` is 296 on a
     * binary float, and this value is compared for EXACT equality against a
     * provider amount — a rounding error here would silently reject a correct
     * Price, or worse, accept a wrong one.
     *
     * @return int|null null when the input is not a valid decimal amount, or
     *                  carries more precision than the currency can express
     */
    public static function toMinor(string $amount, string $currencyCode): ?int
    {
        $amount = trim($amount);

        if (! preg_match('/\A(\d+)(?:\.(\d+))?\z/', $amount, $parts)) {
            return null;
        }

        $whole = $parts[1];
        $fraction = $parts[2] ?? '';
        $places = self::factor($currencyCode) === 1 ? 0 : 2;

        // More precision than the currency can carry is a mismatch, not
        // something to round away: 297.005 is not a chargeable USD amount.
        if (mb_strlen(rtrim($fraction, '0')) > $places) {
            return null;
        }

        // ISK and UGX carry two decimals for compatibility, but the decimal
        // part must always be 00 — fractions of them cannot be charged at all.
        // 297.00 is fine; 297.50 is not a price that exists.
        if (rtrim($fraction, '0') !== '' && self::isWholeUnitsOnly($currencyCode)) {
            return null;
        }

        if ($places === 0) {
            return (int) $whole;
        }

        return (int) $whole * 100 + (int) str_pad(mb_substr($fraction, 0, 2), 2, '0');
    }
}
