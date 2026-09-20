<?php

namespace Tests\Feature\Catalog;

use App\Library\Catalog\CatalogMoney;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use PHPUnit\Framework\TestCase;

/**
 * Implementation Contract 16 §5.1, §12.E — CatalogMoney is PRESENTATION ONLY:
 * exact, string-based conversion between what a person types and the whole
 * minor units the domain stores. It never rounds, and never guesses.
 *
 * A plain PHPUnit TestCase on purpose: it touches no framework and no
 * database, and CatalogMoney must stay that way.
 */
class CatalogMoneyTest extends TestCase
{
    public function test_typed_amounts_become_exact_minor_units_by_the_currencys_own_exponent(): void
    {
        $this->assertSame(4999, CatalogMoney::toMinor('49.99', 'USD'));
        $this->assertSame(4900, CatalogMoney::toMinor('49', 'USD'));
        $this->assertSame(4990, CatalogMoney::toMinor('49.9', 'USD'));
        $this->assertSame(5, CatalogMoney::toMinor('0.05', 'USD'));
        $this->assertSame(0, CatalogMoney::toMinor('0', 'USD'));
        $this->assertSame(5000, CatalogMoney::toMinor('5000', 'JPY'), 'JPY has no minor unit.');
        $this->assertSame(12345, CatalogMoney::toMinor('12.345', 'KWD'), 'KWD has three decimals.');
        $this->assertSame(4999, CatalogMoney::toMinor('  49.99  ', 'usd'), 'Whitespace and currency case are tolerated.');
    }

    /**
     * The whole reason this is string arithmetic: a float multiplies 19.99 by
     * 100 and gets 1998.9999999999998. Every one of these must be exact.
     */
    public function test_values_a_float_would_get_wrong_are_exact(): void
    {
        foreach (['19.99' => 1999, '0.29' => 29, '1.15' => 115, '4.35' => 435, '8.20' => 820, '33.33' => 3333] as $typed => $minor) {
            $this->assertSame($minor, CatalogMoney::toMinor($typed, 'USD'), $typed);
        }
    }

    public function test_a_blank_price_means_no_price(): void
    {
        $this->assertNull(CatalogMoney::toMinor('', 'USD'));
        $this->assertNull(CatalogMoney::toMinor('   ', 'USD'));
        $this->assertNull(CatalogMoney::toMinor(null, null));
        $this->assertNull(CatalogMoney::toMinor('', null));
    }

    public function test_it_refuses_rather_than_rounds_or_guesses(): void
    {
        foreach ([
            ['10.999', 'USD', 'at most 2 decimal places'],
            ['10.5', 'JPY', 'no decimal places'],
            ['10.1234', 'KWD', 'at most 3 decimal places'],
            ['ten', 'USD', 'plain number'],
            ['-5', 'USD', 'plain number'],
            ['1e3', 'USD', 'plain number'],
            ['1,000', 'USD', 'plain number'],
            ['10.', 'USD', 'plain number'],
            ['.5', 'USD', 'plain number'],
            ['10', 'XYZ', 'cannot be entered here yet'],
            ['1' . str_repeat('0', 20), 'USD', 'too large'],
        ] as [$typed, $currency, $expected]) {
            try {
                CatalogMoney::toMinor($typed, $currency);
                $this->fail("[{$typed} {$currency}] must be refused.");
            } catch (CatalogRuleException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), "[{$typed} {$currency}]");
            }
        }
    }

    /**
     * Returning null for a typed price with no currency would silently DROP
     * the price the person entered; it must be refused instead.
     */
    public function test_a_price_with_no_currency_is_refused_not_dropped(): void
    {
        $this->expectException(CatalogRuleException::class);
        $this->expectExceptionMessage('Set both a price and a currency');

        CatalogMoney::toMinor('10.00', '');
    }

    public function test_minor_units_round_trip_to_the_form_field_exactly(): void
    {
        $this->assertSame('49.99', CatalogMoney::toInput(4999, 'USD'));
        $this->assertSame('0.05', CatalogMoney::toInput(5, 'USD'));
        $this->assertSame('0.00', CatalogMoney::toInput(0, 'USD'));
        $this->assertSame('5000', CatalogMoney::toInput(5000, 'JPY'));
        $this->assertSame('12.345', CatalogMoney::toInput(12345, 'KWD'));
        $this->assertSame('', CatalogMoney::toInput(null, 'USD'));
        $this->assertSame('', CatalogMoney::toInput(4999, null));

        foreach ([1, 99, 100, 4999, 100000, 999999999999] as $minor) {
            $this->assertSame($minor, CatalogMoney::toMinor(CatalogMoney::toInput($minor, 'USD'), 'USD'), "round trip {$minor}");
        }
    }

    public function test_display_formatting_uses_the_real_exponent(): void
    {
        $this->assertSame('USD 49.99', CatalogMoney::format(4999, 'USD'));
        $this->assertSame('USD 1,000.00', CatalogMoney::format(100000, 'USD'));
        $this->assertSame('USD 0.05', CatalogMoney::format(5, 'USD'));
        $this->assertSame('JPY 5,000', CatalogMoney::format(5000, 'JPY'));
        $this->assertSame('KWD 12.345', CatalogMoney::format(12345, 'KWD'));
        $this->assertSame('—', CatalogMoney::format(null, 'USD'));
    }

    /** A currency the exponent table does not know is shown raw, never guessed. */
    public function test_an_unknown_currency_is_displayed_as_raw_minor_units_never_guessed(): void
    {
        $this->assertSame('XYZ 4999 (minor units)', CatalogMoney::format(4999, 'XYZ'));
    }

    public function test_large_amounts_keep_full_integer_precision(): void
    {
        // 9,999,999,999,999.99 — beyond a float's exact-integer range for cents.
        $this->assertSame(999999999999999, CatalogMoney::toMinor('9999999999999.99', 'USD'));
        $this->assertSame('USD 9,999,999,999,999.99', CatalogMoney::format(999999999999999, 'USD'));
    }
}
