<?php

namespace Tests\Unit\GoogleAds;

use App\Library\GoogleAds\GoogleAdsMoney;
use PHPUnit\Framework\TestCase;

/**
 * Google Ads Module V1 contract §4 / §9 — the one money helper. Pure unit
 * test: no framework, no database.
 *
 * The invariant these tests exist for: NULL IS NOT ZERO. Absent input is null
 * (displayed "—"), never 0 or "0.00"; a real zero stays a real zero.
 */
class GoogleAdsMoneyTest extends TestCase
{
    // ---------------------------------------------------------------
    // microsToDecimalString
    // ---------------------------------------------------------------

    public function test_micros_to_decimal_string_is_exact_and_null_safe(): void
    {
        $this->assertNull(GoogleAdsMoney::microsToDecimalString(null));
        $this->assertSame('0.000000', GoogleAdsMoney::microsToDecimalString(0));
        $this->assertSame('250.000000', GoogleAdsMoney::microsToDecimalString(250_000_000));
        $this->assertSame('0.000001', GoogleAdsMoney::microsToDecimalString(1));
        $this->assertSame('9223372036854.775807', GoogleAdsMoney::microsToDecimalString(PHP_INT_MAX));
    }

    public function test_micros_to_decimal_string_rounds_half_away_from_zero(): void
    {
        $this->assertSame('1.01', GoogleAdsMoney::microsToDecimalString(1_005_000, 2));
        $this->assertSame('1.00', GoogleAdsMoney::microsToDecimalString(1_004_999, 2));
        $this->assertSame('3', GoogleAdsMoney::microsToDecimalString(2_500_000, 0));
        $this->assertSame('-2', GoogleAdsMoney::microsToDecimalString(-1_500_000, 0));
    }

    public function test_a_negative_that_rounds_to_zero_has_no_minus_sign(): void
    {
        $this->assertSame('0.00', GoogleAdsMoney::microsToDecimalString(-400, 2));
    }

    public function test_scale_is_clamped_to_zero_through_six(): void
    {
        $this->assertSame('1.500000', GoogleAdsMoney::microsToDecimalString(1_500_000, 99));
        $this->assertSame('2', GoogleAdsMoney::microsToDecimalString(1_500_000, -5));
    }

    // ---------------------------------------------------------------
    // toMicros
    // ---------------------------------------------------------------

    public function test_to_micros_parses_valid_amounts_exactly(): void
    {
        $this->assertSame(250_000_000, GoogleAdsMoney::toMicros('250'));
        $this->assertSame(250_500_000, GoogleAdsMoney::toMicros('250.5'));
        $this->assertSame(19_990_000, GoogleAdsMoney::toMicros(19.99), 'a float must not drift by a micro');
        $this->assertSame(5_000_000, GoogleAdsMoney::toMicros(5));
        $this->assertSame(12_000_000, GoogleAdsMoney::toMicros(' 12 '));
        $this->assertSame(1, GoogleAdsMoney::toMicros('0.000001'));
    }

    public function test_zero_is_a_real_zero_but_absence_and_garbage_are_null(): void
    {
        $this->assertSame(0, GoogleAdsMoney::toMicros('0'));
        $this->assertSame(0, GoogleAdsMoney::toMicros(0));

        foreach ([null, '', '   ', 'abc', '-1', '.5', '1,000', '1.1234567', '1e3', INF, NAN] as $bad) {
            $this->assertNull(GoogleAdsMoney::toMicros($bad), var_export($bad, true));
        }
    }

    public function test_to_micros_rejects_a_value_that_overflows_int64(): void
    {
        $this->assertSame(9_223_372_036_854_000_000, GoogleAdsMoney::toMicros('9223372036854'));
        $this->assertNull(GoogleAdsMoney::toMicros('9223372036855'));
    }

    // ---------------------------------------------------------------
    // format
    // ---------------------------------------------------------------

    public function test_format_is_a_dash_for_null_but_a_real_zero_for_zero(): void
    {
        $this->assertSame('—', GoogleAdsMoney::format(null, 'USD'));
        $this->assertSame('USD 0.00', GoogleAdsMoney::format(0, 'USD'));
    }

    public function test_format_reuses_the_two_decimal_dashboard_format(): void
    {
        $this->assertSame('USD 1,234.50', GoogleAdsMoney::format(1_234_500_000, 'USD'));
        $this->assertSame('USD 1,234.50', GoogleAdsMoney::format(1_234_500_000, 'usd'));
        $this->assertSame('USD 8.33', GoogleAdsMoney::format(8_333_333, 'USD'));
        $this->assertSame('1,234.50', GoogleAdsMoney::format(1_234_500_000, ''));
        $this->assertSame('1,234.50', GoogleAdsMoney::format(1_234_500_000, null));
    }

    public function test_format_honours_zero_and_three_decimal_currencies(): void
    {
        $this->assertSame('JPY 1,500', GoogleAdsMoney::format(1_500_000_000, 'JPY'));
        $this->assertSame('KWD 1.234', GoogleAdsMoney::format(1_234_000, 'KWD'));
    }

    public function test_format_for_an_unlisted_currency_falls_back_to_two_decimals_and_never_throws(): void
    {
        // CurrencyExponent fails closed (throws) on a code it does not list;
        // display must not take a page down, so it shows a correct 2dp amount.
        $this->assertSame('ISK 1,234.00', GoogleAdsMoney::format(1_234_000_000, 'ISK'));
        $this->assertSame('US 1.00', GoogleAdsMoney::format(1_000_000, 'US'));
    }

    // ---------------------------------------------------------------
    // cpl
    // ---------------------------------------------------------------

    public function test_cpl_divides_spend_by_conversions_and_rounds_half_up(): void
    {
        $this->assertSame(14_500_000, GoogleAdsMoney::cpl(29_000_000, '2.000000'));
        $this->assertSame(33, GoogleAdsMoney::cpl(100, 3));
        $this->assertSame(200, GoogleAdsMoney::cpl(100, '0.5'));
        $this->assertSame(33_333_333, GoogleAdsMoney::cpl(100_000_000, 3.0));
        $this->assertSame(100_000_000, GoogleAdsMoney::cpl(100, 0.000001));
    }

    public function test_cpl_is_null_not_zero_without_conversions_or_spend(): void
    {
        $this->assertNull(GoogleAdsMoney::cpl(29_000_000, 0));
        $this->assertNull(GoogleAdsMoney::cpl(29_000_000, '0.000000'));
        $this->assertNull(GoogleAdsMoney::cpl(29_000_000, 0.0));
        $this->assertNull(GoogleAdsMoney::cpl(29_000_000, -1));
        $this->assertNull(GoogleAdsMoney::cpl(29_000_000, null));
        $this->assertNull(GoogleAdsMoney::cpl(29_000_000, 'abc'));
        $this->assertNull(GoogleAdsMoney::cpl(null, 2));
        $this->assertNull(GoogleAdsMoney::cpl(-5, 1));
    }

    public function test_cpl_of_zero_spend_with_conversions_is_a_real_zero(): void
    {
        $this->assertSame(0, GoogleAdsMoney::cpl(0, 2));
    }

    public function test_cpl_that_would_overflow_int64_is_null(): void
    {
        $this->assertNull(GoogleAdsMoney::cpl(PHP_INT_MAX, '0.000001'));
    }

    // ---------------------------------------------------------------
    // conversionRate
    // ---------------------------------------------------------------

    public function test_conversion_rate_is_conversions_per_click(): void
    {
        $this->assertEqualsWithDelta(0.03, GoogleAdsMoney::conversionRate('3.000000', 100), 1e-9);
        $this->assertEqualsWithDelta(0.5, GoogleAdsMoney::conversionRate(1, 2), 1e-9);
        $this->assertEqualsWithDelta(0.125, GoogleAdsMoney::conversionRate(0.25, 2), 1e-9);
    }

    public function test_conversion_rate_is_null_without_clicks_or_conversion_data(): void
    {
        $this->assertNull(GoogleAdsMoney::conversionRate(2, 0));
        $this->assertNull(GoogleAdsMoney::conversionRate(2, null));
        $this->assertNull(GoogleAdsMoney::conversionRate(null, 100));
        $this->assertNull(GoogleAdsMoney::conversionRate('abc', 100));
        $this->assertNull(GoogleAdsMoney::conversionRate(-1, 10));
    }

    public function test_zero_conversions_on_real_clicks_is_a_true_zero_rate(): void
    {
        $this->assertSame(0.0, GoogleAdsMoney::conversionRate('0.000000', 10));
        $this->assertSame(0.0, GoogleAdsMoney::conversionRate(0, 10));
    }
}
