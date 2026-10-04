<?php

namespace Tests\Feature\Seo\Rank;

use App\Library\Seo\SeoConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The rank-tracking accessors on SeoConfig FAIL CLOSED: a malformed, zero,
 * negative, non-numeric or out-of-range value is ignored and the documented
 * default is used; configuration can only LOWER spend limits (and only slow
 * cadence / lengthen cooldown, within a maximum); the master switch defaults
 * OFF. No database is touched.
 */
class SeoRankConfigTest extends TestCase
{
    private function config(): SeoConfig
    {
        return new SeoConfig();
    }

    /** @return array<string, array{0: mixed}> */
    public static function malformedValues(): array
    {
        return [
            'zero' => [0],
            'zero string' => ['0'],
            'negative' => [-5],
            'negative string' => ['-5'],
            'non numeric' => ['abc'],
            'float' => [1.5],
            'float string' => ['1.5'],
            'empty string' => [''],
            'null' => [null],
            'bool true' => [true],
            'bool false' => [false],
            'array' => [[3]],
            'padded' => [' 3 '],
            'exponent' => ['1e3'],
        ];
    }

    // ---------------------------------------------------------------
    // defaults
    // ---------------------------------------------------------------

    public function test_documented_defaults_when_nothing_is_configured(): void
    {
        $c = $this->config();

        $this->assertFalse($c->rankTrackingEnabled(), 'The master switch defaults OFF.');
        $this->assertSame(600, $c->rankCostPerPageMicros());
        $this->assertSame(100, $c->rankOrganicDepth());
        $this->assertSame(10, $c->rankLocalDepth());
        $this->assertSame(['tracked_targets' => 5, 'cadence_days' => 3, 'monthly_cap_micros' => 500_000], $c->rankTier('trial'));
        $this->assertSame(['tracked_targets' => 5, 'cadence_days' => 1, 'monthly_cap_micros' => 1_500_000], $c->rankTier('core'));
        $this->assertSame(['tracked_targets' => 20, 'cadence_days' => 1, 'monthly_cap_micros' => 4_500_000], $c->rankTier('growth'));
        $this->assertSame(24, $c->rankManualCooldownHours());
        $this->assertSame(25_000_000, $c->rankWorkspaceMonthlyCapMicros());
        $this->assertSame(10_000_000, $c->rankGlobalDailyCapMicros());
        $this->assertSame(150_000_000, $c->rankGlobalMonthlyCapMicros());
        $this->assertSame(13, $c->rankRetentionMonths());
        $this->assertSame(3, $c->rankMaxSubmitAttempts());
        $this->assertSame(24, $c->rankMaxPollHours());
    }

    // ---------------------------------------------------------------
    // master switch
    // ---------------------------------------------------------------

    public function test_the_master_switch_is_only_on_for_true_one_or_the_true_string(): void
    {
        foreach ([true, 1, '1', 'true', 'TRUE', 'True'] as $on) {
            config(['seo.rank_tracking.enabled' => $on]);
            $this->assertTrue($this->config()->rankTrackingEnabled(), 'Expected ON for ' . var_export($on, true));
        }
    }

    public function test_the_master_switch_is_off_for_anything_else(): void
    {
        foreach ([false, 0, '0', 'false', 'yes', 'on', 'enabled', '', null, 2, 'tru', ' true', [], [true], 1.0, 'y'] as $off) {
            config(['seo.rank_tracking.enabled' => $off]);
            $this->assertFalse($this->config()->rankTrackingEnabled(), 'Expected OFF for ' . var_export($off, true));
        }
    }

    // ---------------------------------------------------------------
    // lower-only values
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int}> key, method, default, a valid lower value */
    public static function lowerOnlyScalars(): array
    {
        return [
            'organic depth' => ['seo.rank_tracking.organic_depth', 'rankOrganicDepth', 100, 50],
            'local depth' => ['seo.rank_tracking.local_depth', 'rankLocalDepth', 10, 10],
            'workspace cap' => ['seo.rank_tracking.workspace_monthly_cap_micros', 'rankWorkspaceMonthlyCapMicros', 25_000_000, 1_000_000],
            'global daily cap' => ['seo.rank_tracking.global_daily_cap_micros', 'rankGlobalDailyCapMicros', 10_000_000, 7_000],
            'global monthly cap' => ['seo.rank_tracking.global_monthly_cap_micros', 'rankGlobalMonthlyCapMicros', 150_000_000, 99],
            'retention months' => ['seo.rank_tracking.retention_months', 'rankRetentionMonths', 13, 3],
            'max submit attempts' => ['seo.rank_tracking.max_submit_attempts', 'rankMaxSubmitAttempts', 3, 1],
            'max poll hours' => ['seo.rank_tracking.max_poll_hours', 'rankMaxPollHours', 24, 6],
        ];
    }

    #[DataProvider('lowerOnlyScalars')]
    public function test_lower_only_values_can_be_lowered_as_int_or_digit_string(string $key, string $method, int $default, int $lower): void
    {
        config([$key => $lower]);
        $this->assertSame($lower, $this->config()->{$method}());

        config([$key => (string) $lower]);
        $this->assertSame($lower, $this->config()->{$method}());
    }

    #[DataProvider('lowerOnlyScalars')]
    public function test_lower_only_values_can_never_be_raised(string $key, string $method, int $default, int $lower): void
    {
        config([$key => $default + 1]);
        $this->assertSame($default, $this->config()->{$method}());

        config([$key => $default * 10]);
        $this->assertSame($default, $this->config()->{$method}());

        config([$key => PHP_INT_MAX]);
        $this->assertSame($default, $this->config()->{$method}());

        config([$key => '99999999999999999999']);
        $this->assertSame($default, $this->config()->{$method}());
    }

    #[DataProvider('lowerOnlyScalars')]
    public function test_lower_only_values_that_are_malformed_fall_back_to_the_default(string $key, string $method, int $default, int $lower): void
    {
        foreach (self::malformedValues() as $label => [$bad]) {
            config([$key => $bad]);
            $this->assertSame($default, $this->config()->{$method}(), $method . ' with ' . $label);
        }
    }

    public function test_depths_cannot_be_set_below_one_page(): void
    {
        config(['seo.rank_tracking.organic_depth' => 9]);
        $this->assertSame(100, $this->config()->rankOrganicDepth());

        config(['seo.rank_tracking.organic_depth' => 10]);
        $this->assertSame(10, $this->config()->rankOrganicDepth());

        config(['seo.rank_tracking.local_depth' => 5]);
        $this->assertSame(10, $this->config()->rankLocalDepth());
    }

    // ---------------------------------------------------------------
    // tiers
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: int, 2: int, 3: int}> */
    public static function tiers(): array
    {
        return [
            'trial' => ['trial', 5, 3, 500_000],
            'core' => ['core', 5, 1, 1_500_000],
            'growth' => ['growth', 20, 1, 4_500_000],
        ];
    }

    #[DataProvider('tiers')]
    public function test_a_tier_can_lower_targets_and_cap_but_never_raise_them(string $tier, int $targets, int $cadence, int $cap): void
    {
        config(["seo.rank_tracking.tiers.{$tier}.tracked_targets" => 2, "seo.rank_tracking.tiers.{$tier}.monthly_cap_micros" => 10_000]);
        $limits = $this->config()->rankTier($tier);
        $this->assertSame(2, $limits['tracked_targets']);
        $this->assertSame(10_000, $limits['monthly_cap_micros']);

        config(["seo.rank_tracking.tiers.{$tier}.tracked_targets" => $targets + 1, "seo.rank_tracking.tiers.{$tier}.monthly_cap_micros" => $cap + 1]);
        $limits = $this->config()->rankTier($tier);
        $this->assertSame($targets, $limits['tracked_targets']);
        $this->assertSame($cap, $limits['monthly_cap_micros']);

        config(["seo.rank_tracking.tiers.{$tier}.tracked_targets" => 1000, "seo.rank_tracking.tiers.{$tier}.monthly_cap_micros" => 999_999_999]);
        $limits = $this->config()->rankTier($tier);
        $this->assertSame($targets, $limits['tracked_targets']);
        $this->assertSame($cap, $limits['monthly_cap_micros']);
    }

    #[DataProvider('tiers')]
    public function test_a_tier_cadence_can_only_be_slowed_within_thirty_days(string $tier, int $targets, int $cadence, int $cap): void
    {
        config(["seo.rank_tracking.tiers.{$tier}.cadence_days" => 7]);
        $this->assertSame(7, $this->config()->rankTier($tier)['cadence_days']);

        config(["seo.rank_tracking.tiers.{$tier}.cadence_days" => '30']);
        $this->assertSame(30, $this->config()->rankTier($tier)['cadence_days']);

        // Beyond the maximum it is ignored, never "paused forever".
        config(["seo.rank_tracking.tiers.{$tier}.cadence_days" => 31]);
        $this->assertSame($cadence, $this->config()->rankTier($tier)['cadence_days']);

        // Faster than the default is refused (default is the minimum).
        if ($cadence > 1) {
            config(["seo.rank_tracking.tiers.{$tier}.cadence_days" => $cadence - 1]);
            $this->assertSame($cadence, $this->config()->rankTier($tier)['cadence_days']);
        }

        config(["seo.rank_tracking.tiers.{$tier}.cadence_days" => 0]);
        $this->assertSame($cadence, $this->config()->rankTier($tier)['cadence_days']);
    }

    #[DataProvider('tiers')]
    public function test_malformed_tier_values_fall_back_to_the_tier_defaults(string $tier, int $targets, int $cadence, int $cap): void
    {
        foreach (self::malformedValues() as $label => [$bad]) {
            config([
                "seo.rank_tracking.tiers.{$tier}.tracked_targets" => $bad,
                "seo.rank_tracking.tiers.{$tier}.cadence_days" => $bad,
                "seo.rank_tracking.tiers.{$tier}.monthly_cap_micros" => $bad,
            ]);

            $limits = $this->config()->rankTier($tier);

            $this->assertSame($targets, $limits['tracked_targets'], "targets with {$label}");
            $this->assertSame($cadence, $limits['cadence_days'], "cadence with {$label}");
            $this->assertSame($cap, $limits['monthly_cap_micros'], "cap with {$label}");
        }
    }

    public function test_an_unknown_tier_gets_the_trial_limits_never_an_open_tier(): void
    {
        $trial = $this->config()->rankTier('trial');

        foreach (['enterprise', 'agency', '', 'GROWTH', 'admin', '../core'] as $unknown) {
            $this->assertSame($trial, $this->config()->rankTier($unknown), 'tier: ' . var_export($unknown, true));
        }
    }

    public function test_an_unknown_tier_reads_the_trial_tier_config_so_it_cannot_escape_a_lowered_trial(): void
    {
        config(['seo.rank_tracking.tiers.trial.monthly_cap_micros' => 100_000]);

        $this->assertSame(100_000, $this->config()->rankTier('whatever')['monthly_cap_micros']);
    }

    // ---------------------------------------------------------------
    // cooldown
    // ---------------------------------------------------------------

    public function test_the_manual_cooldown_can_only_be_lengthened_within_a_week(): void
    {
        config(['seo.rank_tracking.manual_cooldown_hours' => 48]);
        $this->assertSame(48, $this->config()->rankManualCooldownHours());

        config(['seo.rank_tracking.manual_cooldown_hours' => '168']);
        $this->assertSame(168, $this->config()->rankManualCooldownHours());

        foreach ([169, 1000, 23, 1, 0, -24] as $bad) {
            config(['seo.rank_tracking.manual_cooldown_hours' => $bad]);
            $this->assertSame(24, $this->config()->rankManualCooldownHours(), 'cooldown ' . $bad);
        }

        foreach (self::malformedValues() as $label => [$bad]) {
            config(['seo.rank_tracking.manual_cooldown_hours' => $bad]);
            $this->assertSame(24, $this->config()->rankManualCooldownHours(), 'cooldown with ' . $label);
        }
    }

    // ---------------------------------------------------------------
    // cost per page
    // ---------------------------------------------------------------

    public function test_the_cost_per_page_is_bounded_between_100_and_5000_micros(): void
    {
        foreach ([100, 600, 1200, 5000, '800'] as $ok) {
            config(['seo.rank_tracking.cost_per_page_micros' => $ok]);
            $this->assertSame((int) $ok, $this->config()->rankCostPerPageMicros());
        }

        foreach ([99, 5001, 0, -1, 1_000_000, 'abc', '', null, 1.5, '6e2'] as $bad) {
            config(['seo.rank_tracking.cost_per_page_micros' => $bad]);
            $this->assertSame(600, $this->config()->rankCostPerPageMicros(), 'cost ' . var_export($bad, true));
        }
    }
}
