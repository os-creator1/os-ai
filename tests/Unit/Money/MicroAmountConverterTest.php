<?php

namespace Tests\Unit\Money;

use App\Library\Money\Exceptions\UnsupportedCurrencyException;
use App\Library\Money\MicroAmountConverter;
use Tests\TestCase;

/**
 * Contract 19 §5.3 review correction — RFC-005 micro-units (1 micro =
 * 1/1,000,000 of a currency's major unit) to real ISO minor units, via
 * {@see \App\Library\Money\CurrencyExponent}'s own exponent. CEILING, never
 * floor, never a float: this value is an UPPER BOUND a customer approves,
 * and understating it would be exactly the kind of invented/misleading
 * figure Contract 19 forbids.
 */
class MicroAmountConverterTest extends TestCase
{
    public function test_an_exact_two_decimal_conversion(): void
    {
        // 1,000,000 micro = 1.00 USD = 100 cents, exactly.
        $this->assertSame(100, MicroAmountConverter::ceilToMinorUnits(1_000_000, 'USD'));
    }

    public function test_a_two_decimal_amount_that_divides_evenly(): void
    {
        // 1,230,000 micro = 123 cents, exactly — no rounding involved.
        $this->assertSame(123, MicroAmountConverter::ceilToMinorUnits(1_230_000, 'USD'));
    }

    public function test_a_fractional_minor_unit_ceils_rather_than_floors(): void
    {
        // 1,230,001 micro is NOT an exact multiple of 10,000 (the
        // micro-per-cent divisor for a two-decimal currency): the true
        // value is 123.0001 cents, which must ceil to 124, never floor to
        // 123 — flooring would understate the ceiling being approved.
        $this->assertSame(124, MicroAmountConverter::ceilToMinorUnits(1_230_001, 'USD'));
    }

    public function test_the_smallest_possible_fraction_still_ceils_up_a_whole_unit(): void
    {
        // One micro-unit over an exact cent boundary still rounds up to
        // the next cent — there is no "close enough" for an upper bound.
        $this->assertSame(101, MicroAmountConverter::ceilToMinorUnits(1_000_001, 'USD'));
    }

    public function test_zero_micro_converts_to_zero_minor_units(): void
    {
        $this->assertSame(0, MicroAmountConverter::ceilToMinorUnits(0, 'USD'));
    }

    public function test_a_zero_decimal_currency_has_no_minor_unit_scaling(): void
    {
        // JPY's minor unit IS its major unit: 1,000,000 micro = 1 JPY, and
        // a fractional micro amount still ceils to the next whole yen.
        $this->assertSame(1, MicroAmountConverter::ceilToMinorUnits(1_000_000, 'JPY'));
        $this->assertSame(2, MicroAmountConverter::ceilToMinorUnits(1_000_001, 'JPY'));
        $this->assertSame(5, MicroAmountConverter::ceilToMinorUnits(5_000_000, 'JPY'));
    }

    public function test_a_three_decimal_currency_scales_by_a_thousand(): void
    {
        // KWD: 1,000 micro-per-fils divisor (1,000,000 / 10^3).
        $this->assertSame(1_000, MicroAmountConverter::ceilToMinorUnits(1_000_000, 'KWD'));
        $this->assertSame(1_001, MicroAmountConverter::ceilToMinorUnits(1_000_001, 'KWD'));
    }

    public function test_an_unsupported_currency_fails_closed_rather_than_guessing_an_exponent(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        MicroAmountConverter::ceilToMinorUnits(1_000_000, 'XXX');
    }

    public function test_a_malformed_currency_code_fails_closed(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        MicroAmountConverter::ceilToMinorUnits(1_000_000, 'not-a-code');
    }
}
