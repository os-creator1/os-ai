<?php

namespace Tests\Feature\Seo;

use App\Library\Seo\SeoConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18A — config/seo.php is read ONLY through SeoConfig,
 * which fails closed toward the documented default: an absent, non-numeric,
 * zero, negative or above-ceiling value never takes effect, so it can never
 * raise a ceiling and can never disable one.
 */
class SeoConfigTest extends TestCase
{
    private function reader(): SeoConfig
    {
        return new SeoConfig();
    }

    public function test_every_accessor_returns_its_contracted_default_when_unset(): void
    {
        config(['seo' => []]);

        $r = $this->reader();

        $this->assertSame(50, $r->keywordsMaxActivePerBusiness());
        $this->assertSame(400, $r->searchConsoleDailyRetentionDays());
        $this->assertSame(12, $r->searchConsoleSnapshotWeeks());
        $this->assertSame(90, $r->searchConsoleStalePurgeDays());
        $this->assertSame(15, $r->searchConsoleManualRefreshMinIntervalMinutes());
        $this->assertSame(30, $r->searchConsoleMaxCallsPerBusinessPerHour());
        $this->assertSame(20, $r->searchConsoleBreakerThreshold());
        $this->assertSame(30, $r->searchConsoleBreakerCooldownMinutes());
        $this->assertSame(90, $r->reviewRequestCooldownDays(), 'Coordinator decision: review-request cooldown default is 90 days.');
        $this->assertSame(5, $r->auditRunsRetained());
    }

    public function test_the_shipped_config_file_yields_only_defaults(): void
    {
        // As shipped every value is env-driven and unset in the test env.
        $this->assertSame(50, $this->reader()->keywordsMaxActivePerBusiness());
        $this->assertSame(400, $this->reader()->searchConsoleDailyRetentionDays());
        $this->assertSame(90, $this->reader()->reviewRequestCooldownDays());
    }

    public function test_a_valid_int_or_digit_string_inside_the_range_is_honoured(): void
    {
        config(['seo.search_console.daily_retention_days' => 200]);
        $this->assertSame(200, $this->reader()->searchConsoleDailyRetentionDays());

        config(['seo.search_console.daily_retention_days' => '300']);
        $this->assertSame(300, $this->reader()->searchConsoleDailyRetentionDays());

        config(['seo.keywords.max_active_per_business' => '10']);
        $this->assertSame(10, $this->reader()->keywordsMaxActivePerBusiness(), 'A ceiling may be lowered.');

        config(['seo.reviews.request_cooldown_days' => 30]);
        $this->assertSame(30, $this->reader()->reviewRequestCooldownDays());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'words' => ['many'],
            'negative string' => ['-5'],
            'negative int' => [-5],
            'zero' => [0],
            'zero string' => ['0'],
            'float' => [12.5],
            'float string' => ['12.5'],
            'array' => [[400]],
            'bool' => [true],
            'padded string' => [' 400'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_an_invalid_value_is_ignored_and_the_default_is_used(mixed $invalid): void
    {
        config([
            'seo.search_console.daily_retention_days' => $invalid,
            'seo.keywords.max_active_per_business' => $invalid,
            'seo.reviews.request_cooldown_days' => $invalid,
        ]);

        $this->assertSame(400, $this->reader()->searchConsoleDailyRetentionDays());
        $this->assertSame(50, $this->reader()->keywordsMaxActivePerBusiness());
        $this->assertSame(90, $this->reader()->reviewRequestCooldownDays());
    }

    public function test_a_hard_ceiling_can_never_be_raised(): void
    {
        config([
            'seo.keywords.max_active_per_business' => 51,
            'seo.search_console.daily_retention_days' => 481,
            'seo.search_console.snapshot_weeks' => 27,
            'seo.search_console.stale_purge_days' => 366,
            'seo.search_console.max_calls_per_business_per_hour' => 121,
            'seo.audit.runs_retained' => 21,
        ]);

        $r = $this->reader();

        // Above-ceiling falls back to the (lower) DEFAULT, not to the ceiling.
        $this->assertSame(50, $r->keywordsMaxActivePerBusiness());
        $this->assertSame(400, $r->searchConsoleDailyRetentionDays());
        $this->assertSame(12, $r->searchConsoleSnapshotWeeks());
        $this->assertSame(90, $r->searchConsoleStalePurgeDays());
        $this->assertSame(30, $r->searchConsoleMaxCallsPerBusinessPerHour());
        $this->assertSame(5, $r->auditRunsRetained());
    }

    public function test_the_ceiling_boundary_values_themselves_are_accepted(): void
    {
        config([
            'seo.search_console.daily_retention_days' => 480,
            'seo.search_console.snapshot_weeks' => 26,
            'seo.keywords.max_active_per_business' => 50,
            'seo.search_console.manual_refresh_min_interval_minutes' => 5,
        ]);

        $r = $this->reader();

        $this->assertSame(480, $r->searchConsoleDailyRetentionDays());
        $this->assertSame(26, $r->searchConsoleSnapshotWeeks());
        $this->assertSame(50, $r->keywordsMaxActivePerBusiness());
        $this->assertSame(5, $r->searchConsoleManualRefreshMinIntervalMinutes());
    }

    public function test_a_manual_refresh_interval_below_the_floor_is_refused(): void
    {
        config(['seo.search_console.manual_refresh_min_interval_minutes' => 1]);

        $this->assertSame(15, $this->reader()->searchConsoleManualRefreshMinIntervalMinutes(), 'A too-small interval must never weaken the refresh guard.');
    }
}
