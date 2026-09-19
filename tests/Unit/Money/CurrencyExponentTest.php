<?php

namespace Tests\Unit\Money;

use App\Library\Money\CurrencyExponent;
use App\Library\Money\Exceptions\AmountOutOfBoundsException;
use App\Library\Money\Exceptions\UnsupportedCurrencyException;
use PHPUnit\Framework\TestCase;

/**
 * Implementation Contract 17 §4.6/§12.A — the lane-neutral currency exponent
 * value object. Pure unit test: no framework, no database.
 */
class CurrencyExponentTest extends TestCase
{
    public function test_two_decimal_currencies(): void
    {
        foreach (['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'INR', 'SAR'] as $code) {
            $this->assertSame(2, CurrencyExponent::for($code), $code);
        }
    }

    public function test_zero_decimal_currencies(): void
    {
        foreach (['JPY', 'KRW', 'VND', 'CLP', 'XOF'] as $code) {
            $this->assertSame(0, CurrencyExponent::for($code), $code);
        }
    }

    public function test_three_decimal_currencies(): void
    {
        foreach (['BHD', 'JOD', 'KWD', 'OMR', 'TND'] as $code) {
            $this->assertSame(3, CurrencyExponent::for($code), $code);
        }
    }

    public function test_the_three_tiers_are_disjoint_and_well_formed(): void
    {
        $all = array_merge(CurrencyExponent::ZERO_DECIMAL, CurrencyExponent::THREE_DECIMAL, CurrencyExponent::TWO_DECIMAL);

        $this->assertSame(count($all), count(array_unique($all)), 'A currency must appear in exactly one tier.');

        foreach ($all as $code) {
            $this->assertMatchesRegularExpression('/\A[A-Z]{3}\z/', $code);
        }
    }

    public function test_lookup_is_case_insensitive(): void
    {
        $this->assertSame(2, CurrencyExponent::for('usd'));
        $this->assertSame(0, CurrencyExponent::for('jpy'));
        $this->assertSame(3, CurrencyExponent::for('Kwd'));
    }

    public function test_an_unlisted_currency_fails_closed_never_a_two_decimal_guess(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        CurrencyExponent::for('XYZ');
    }

    public function test_malformed_codes_fail_closed(): void
    {
        foreach (['', 'US', 'USDD', 'U$D', '123', ' USD', 'USD '] as $bad) {
            $threw = false;

            try {
                CurrencyExponent::for($bad);
            } catch (UnsupportedCurrencyException) {
                $threw = true;
            }

            $this->assertTrue($threw, "[{$bad}] must be refused.");
        }
    }

    public function test_is_supported(): void
    {
        $this->assertTrue(CurrencyExponent::isSupported('USD'));
        $this->assertTrue(CurrencyExponent::isSupported('jpy'));
        $this->assertFalse(CurrencyExponent::isSupported('XYZ'));
        $this->assertFalse(CurrencyExponent::isSupported('not a code'));
    }

    public function test_minor_units_per_major(): void
    {
        $this->assertSame(100, CurrencyExponent::minorUnitsPerMajor('USD'));
        $this->assertSame(1, CurrencyExponent::minorUnitsPerMajor('JPY'));
        $this->assertSame(1000, CurrencyExponent::minorUnitsPerMajor('KWD'));
    }

    public function test_charge_bounds(): void
    {
        $this->assertSame(50, CurrencyExponent::MINIMUM_MINOR_UNITS);
        $this->assertSame(99_999_999, CurrencyExponent::MAXIMUM_MINOR_UNITS);

        $this->assertFalse(CurrencyExponent::isWithinChargeBounds(49));
        $this->assertTrue(CurrencyExponent::isWithinChargeBounds(50));
        $this->assertTrue(CurrencyExponent::isWithinChargeBounds(99_999_999));
        $this->assertFalse(CurrencyExponent::isWithinChargeBounds(100_000_000));
    }

    public function test_amount_below_the_minimum_is_refused_before_any_provider_call(): void
    {
        $this->expectException(AmountOutOfBoundsException::class);
        $this->expectExceptionMessage('below the minimum');

        CurrencyExponent::assertWithinChargeBounds(49);
    }

    public function test_amount_above_the_maximum_is_refused_before_any_provider_call(): void
    {
        $this->expectException(AmountOutOfBoundsException::class);
        $this->expectExceptionMessage('exceeds the eight-digit maximum');

        CurrencyExponent::assertWithinChargeBounds(100_000_000);
    }

    public function test_boundary_amounts_are_accepted(): void
    {
        CurrencyExponent::assertWithinChargeBounds(50);
        CurrencyExponent::assertWithinChargeBounds(99_999_999);

        $this->assertTrue(true);
    }

    public function test_it_is_a_pure_value_object_that_cannot_be_instantiated(): void
    {
        $constructor = (new \ReflectionClass(CurrencyExponent::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
        $this->assertTrue((new \ReflectionClass(CurrencyExponent::class))->isFinal());
    }

    /**
     * Lane B must never depend on lane D (Contract 17 §4). Asserted against
     * the class's own source with comments stripped, so the explanatory
     * docblock naming the forbidden class does not trip it.
     */
    public function test_it_has_no_dependency_on_the_usage_lane(): void
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents((new \ReflectionClass(CurrencyExponent::class))->getFileName())) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        $this->assertStringNotContainsString('App\\Library\\Usage', $code);
        $this->assertStringNotContainsString('UsageBillingCheckoutManager', $code);
        $this->assertStringNotContainsString('_micro', $code);
    }
}
