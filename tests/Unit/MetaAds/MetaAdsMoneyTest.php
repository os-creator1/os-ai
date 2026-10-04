<?php

namespace Tests\Unit\MetaAds;

use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\MetaAdsMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Meta Ads Module V1 contract §5.1 — the one money helper. Pure unit test: no
 * framework, no database. Invariants: spend is parsed from a decimal STRING
 * without floats; more than six decimals is REJECTED, never rounded; NULL IS
 * NOT ZERO.
 */
class MetaAdsMoneyTest extends TestCase
{
    #[DataProvider('validSpend')]
    public function test_spend_strings_parse_to_exact_micros(string|int $input, int $micros): void
    {
        $this->assertSame($micros, MetaAdsMoney::parseToMicros($input));
    }

    /** @return array<string, array{0: string|int, 1: int}> */
    public static function validSpend(): array
    {
        return [
            'whole' => ['250', 250_000_000],
            'cents' => ['12.34', 12_340_000],
            'one decimal' => ['0.1', 100_000],
            'six decimals' => ['0.000001', 1],
            'trailing zeros' => ['19.990000', 19_990_000],
            'zero' => ['0', 0],
            'zero decimal' => ['0.00', 0],
            'integer input' => [7, 7_000_000],
            'padded' => [' 5.5 ', 5_500_000],
            'float trap value' => ['0.29', 290_000],
            'float trap value 2' => ['1.15', 1_150_000],
            'large' => ['9223372036854.775807', PHP_INT_MAX],
        ];
    }

    public function test_absent_spend_is_null_not_zero(): void
    {
        $this->assertNull(MetaAdsMoney::parseToMicros(null));
        $this->assertNull(MetaAdsMoney::parseToMicros(''));
        $this->assertNull(MetaAdsMoney::parseToMicros('   '));
    }

    #[DataProvider('invalidSpend')]
    public function test_malformed_spend_is_an_unexpected_response_never_rounded(string $input): void
    {
        try {
            MetaAdsMoney::parseToMicros($input);
            $this->fail('expected unexpected_response for ' . $input);
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::UNEXPECTED_RESPONSE, $e->classification);
            $this->assertFalse($e->isAmbiguous());
        }

        $this->assertNull(MetaAdsMoney::tryParseToMicros($input));
    }

    /** @return array<string, array{0: string}> */
    public static function invalidSpend(): array
    {
        return [
            'seven decimals' => ['1.0000001'],
            'negative' => ['-1'],
            'negative zero' => ['-0.00'],
            'text' => ['abc'],
            'exponent' => ['1e3'],
            'comma decimal' => ['1,5'],
            'thousands separator' => ['1,000.00'],
            'double dot' => ['1.2.3'],
            'trailing dot' => ['1.'],
            'leading dot' => ['.5'],
            'plus sign' => ['+1'],
            'int64 overflow' => ['9223372036854.775808'],
            'currency symbol' => ['$5'],
        ];
    }

    public function test_micros_to_decimal_string_is_exact_and_null_safe(): void
    {
        $this->assertNull(MetaAdsMoney::microsToDecimalString(null));
        $this->assertSame('0.000000', MetaAdsMoney::microsToDecimalString(0));
        $this->assertSame('12.340000', MetaAdsMoney::microsToDecimalString(12_340_000));
        $this->assertSame('0.000001', MetaAdsMoney::microsToDecimalString(1));
        $this->assertSame('12.34', MetaAdsMoney::microsToDecimalString(12_340_000, 2));
        $this->assertSame('12.35', MetaAdsMoney::microsToDecimalString(12_345_000, 2), 'half away from zero');
        $this->assertSame('-12.35', MetaAdsMoney::microsToDecimalString(-12_345_000, 2));
        $this->assertSame('0.00', MetaAdsMoney::microsToDecimalString(-1, 2), 'never "-0.00"');
        $this->assertSame('9223372036854.775807', MetaAdsMoney::microsToDecimalString(PHP_INT_MAX));
    }

    public function test_parse_and_render_round_trip(): void
    {
        foreach (['0.000001', '1.500000', '123456.789012', '0.000000'] as $text) {
            $this->assertSame($text, MetaAdsMoney::microsToDecimalString(MetaAdsMoney::parseToMicros($text)));
        }
    }

    public function test_budget_minor_units_are_digits_only(): void
    {
        $this->assertNull(MetaAdsMoney::parseMinor(null));
        $this->assertNull(MetaAdsMoney::parseMinor(''));
        $this->assertSame(1000, MetaAdsMoney::parseMinor('1000'));
        $this->assertSame(0, MetaAdsMoney::parseMinor('0'));
        $this->assertSame(5, MetaAdsMoney::parseMinor(5));

        foreach (['10.50', '-1', 'ten', '1e3', '1234567890123456789'] as $bad) {
            try {
                MetaAdsMoney::parseMinor($bad);
                $this->fail('expected unexpected_response for ' . $bad);
            } catch (MetaProviderException $e) {
                $this->assertSame(MetaProviderException::UNEXPECTED_RESPONSE, $e->classification);
            }
        }
    }

    public function test_minor_units_convert_with_the_currency_exponent(): void
    {
        // Two-decimal currency: 1000 cents = $10.00 = 10,000,000 micros.
        $this->assertSame(10_000_000, MetaAdsMoney::minorToMicros(1000, 'USD'));
        $this->assertSame(10_000_000, MetaAdsMoney::minorToMicros(1000, 'eur'));
        // Zero-decimal currency: 1000 yen = 1000 whole units.
        $this->assertSame(1_000_000_000, MetaAdsMoney::minorToMicros(1000, 'JPY'));
        // Three-decimal currency: 1000 fils = 1 dinar.
        $this->assertSame(1_000_000, MetaAdsMoney::minorToMicros(1000, 'KWD'));

        $this->assertSame(1000, MetaAdsMoney::microsToMinor(10_000_000, 'USD'));
        $this->assertSame(1000, MetaAdsMoney::microsToMinor(1_000_000_000, 'JPY'));
        $this->assertSame(1000, MetaAdsMoney::microsToMinor(1_000_000, 'KWD'));
        $this->assertSame(1, MetaAdsMoney::microsToMinor(5_000, 'USD'), 'half rounds up');
        $this->assertSame(0, MetaAdsMoney::microsToMinor(4_999, 'USD'));
        $this->assertSame(-1, MetaAdsMoney::microsToMinor(-5_000, 'USD'));
    }

    public function test_minor_unit_conversion_fails_closed_for_an_unlisted_currency(): void
    {
        $this->expectException(\Throwable::class);
        MetaAdsMoney::minorToMicros(100, 'XXX');
    }

    public function test_format_displays_the_account_currency_and_dashes_for_null(): void
    {
        $this->assertSame('—', MetaAdsMoney::format(null, 'USD'));
        $this->assertSame('USD 1,234.50', MetaAdsMoney::format(1_234_500_000, 'USD'));
        $this->assertSame('USD 0.00', MetaAdsMoney::format(0, 'USD'), 'a real zero is not a dash');
        $this->assertSame('EUR 12.35', MetaAdsMoney::format(12_345_000, 'EUR'));
        $this->assertSame('JPY 1,500', MetaAdsMoney::format(1_500_000_000, 'JPY'));
        $this->assertSame('KWD 1.500', MetaAdsMoney::format(1_500_000, 'KWD'));
        $this->assertSame('ZZZ 5.00', MetaAdsMoney::format(5_000_000, 'ZZZ'), 'unlisted currency still displays');
    }

    public function test_cost_per_result_is_null_without_a_divisor(): void
    {
        $this->assertNull(MetaAdsMoney::costPerResult(100_000_000, null));
        $this->assertNull(MetaAdsMoney::costPerResult(100_000_000, 0));
        $this->assertNull(MetaAdsMoney::costPerResult(null, 5));
        $this->assertNull(MetaAdsMoney::costPerResult(-1, 5));
        $this->assertSame(20_000_000, MetaAdsMoney::costPerResult(100_000_000, 5));
        $this->assertSame(33_333_333, MetaAdsMoney::costPerResult(100_000_000, 3));
        $this->assertSame(0, MetaAdsMoney::costPerResult(0, 5), 'zero spend with results is a real zero');
    }
}
