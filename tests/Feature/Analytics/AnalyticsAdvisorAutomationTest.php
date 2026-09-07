<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — contract §8 (O1–O4, recommendations not a pipeline), §9 (A1–A4,
 * read-only over B4's ledger), §2.6 (conditional panels), §21
 * "Opportunities", "Automations (after B4)".
 */
class AnalyticsAdvisorAutomationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private function overviewFor($business, array $custom = [])
    {
        $range = $custom !== []
            ? AnalyticsDateRange::fromInput(array_merge(['range' => 'custom'], $custom), $business->timezone)
            : AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);

        return app(BusinessAnalyticsPresenter::class)->buildOverview($business, $range);
    }

    // ---------------------------------------------------------------
    // Advisor recommendations
    // ---------------------------------------------------------------

    public function test_o1_to_o4_have_recommendation_semantics(): void
    {
        config(['opportunity.enabled' => true]);
        [, $business] = $this->tenant('America/New_York');
        $tz = 'America/New_York';
        $inRange = $this->utcFromLocal('2026-06-05 10:00:00', $tz);
        $outOfRange = $this->utcFromLocal('2026-05-05 10:00:00', $tz);

        $this->opportunity($business, ['status' => 'open', 'freshness' => 'current']);
        $this->opportunity($business, ['status' => 'open', 'freshness' => 'current']);
        $this->opportunity($business, ['status' => 'open', 'freshness' => 'stale']);
        $this->opportunity($business, ['status' => 'snoozed', 'freshness' => 'current']);
        $this->opportunity($business, ['status' => 'completed', 'completed_at' => $inRange]);
        $this->opportunity($business, ['status' => 'completed', 'completed_at' => $outOfRange]);
        $this->opportunity($business, ['status' => 'dismissed', 'dismissed_at' => $inRange]);
        $this->advisorRun($business, 'succeeded', $this->storageFromUtc('2026-06-01 12:00:00'));
        $this->advisorRun($business, 'succeeded', $this->storageFromUtc('2026-06-03 12:00:00'));
        $this->advisorRun($business, 'failed', $this->storageFromUtc('2026-06-04 12:00:00'));
        $this->advisorRun($business, 'running', null);

        [, $other] = $this->tenant();
        $this->opportunity($other, ['status' => 'open', 'freshness' => 'current']);

        $advisor = $this->overviewFor($business, ['start' => '2026-06-01', 'end' => '2026-06-30'])->advisor;

        $this->assertNotNull($advisor);
        $this->assertSame(2, $advisor->openCurrent, 'O1 = open AND current, point in time.');
        $this->assertSame(1, $advisor->completedInRange);
        $this->assertSame(1, $advisor->dismissedInRange);
        $this->assertNotNull($advisor->lastSuccessfulRunAt);
        $this->assertTrue(
            \Carbon\CarbonImmutable::parse('2026-06-03 12:00:00', 'UTC')->equalTo(\Carbon\CarbonImmutable::parse($advisor->lastSuccessfulRunAt)),
            'O4 = MAX(completed_at) of succeeded runs only.'
        );
    }

    public function test_advisor_panel_is_absent_and_unqueried_when_the_engine_is_disabled(): void
    {
        config(['opportunity.enabled' => false]);
        [$customer, $business, $workspace] = $this->tenant();
        $this->opportunity($business);
        $this->authenticateAsCustomer($customer);

        $sql = $this->capturedSql(function () use ($workspace, $business): void {
            $this->overview($workspace, $business)->assertOk()->assertDontSee('data-role="advisor-panel"', false)->assertDontSee('Open recommendations');
        });

        foreach ($sql as $statement) {
            $this->assertStringNotContainsString('opportunit', $statement, 'Opportunities are never queried when the engine is disabled.');
        }

        $this->assertNull($this->overviewFor($business)->advisor);
    }

    public function test_advisor_panel_renders_recommendation_language_only(): void
    {
        config(['opportunity.enabled' => true]);
        [$customer, $business, $workspace] = $this->tenant();
        $this->opportunity($business);
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)
            ->assertOk()
            ->assertSee('data-role="advisor-panel"', false)
            ->assertSee('Open recommendations')
            ->assertSee('AI Business Advisor recommendations')
            ->assertSee('carry no monetary value');
    }

    // ---------------------------------------------------------------
    // Automations (B4 ledger, read-only)
    // ---------------------------------------------------------------

    public function test_a1_to_a4_over_the_b4_ledger_with_pending_and_skipped_outside_the_success_denominator(): void
    {
        [, $business] = $this->tenant();
        $group = $this->group($business);
        $contact = $this->contact($business, $group);
        $automation = $this->automation($business);

        foreach (['succeeded', 'succeeded', 'succeeded', 'failed', 'skipped', 'skipped', 'pending'] as $status) {
            $this->execution($business, $automation, $contact, $status, 'contact_created');
        }
        $this->execution($business, $automation, $contact, 'succeeded', 'contact_date_reached');
        $this->execution($business, $automation, $contact, 'succeeded', 'contact_created', now()->utc()->subDays(60)->format('Y-m-d H:i:s'));

        [, $other] = $this->tenant();
        $otherContact = $this->contact($other, $this->group($other));
        $this->execution($other, $this->automation($other), $otherContact, 'failed');

        $before = DB::table('automation_executions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $a = $this->overviewFor($business)->automations;

        $this->assertNotNull($a);
        $this->assertSame(8, $a->executionsInRange, 'A1 counts the range only, this Business only.');
        $this->assertSame(['failed' => 1, 'pending' => 1, 'skipped' => 2, 'succeeded' => 4], $a->byStatus);
        $this->assertSame(['contact_created' => 7, 'contact_date_reached' => 1], $a->byTrigger);
        $this->assertSame(80.0, $a->successRate(), 'A2 = 4 succeeded / (4 succeeded + 1 failed).');

        $this->assertSame($before, DB::table('automation_executions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'B5 never mutates the B4 ledger.');
    }

    public function test_a2_is_null_safe_when_nothing_succeeded_or_failed(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $contact = $this->contact($business, $this->group($business));
        $automation = $this->automation($business);
        $this->execution($business, $automation, $contact, 'skipped');
        $this->execution($business, $automation, $contact, 'pending');

        $a = $this->overviewFor($business)->automations;

        $this->assertSame(2, $a->executionsInRange);
        $this->assertNull($a->successRate());

        $this->authenticateAsCustomer($customer);
        $this->overview($workspace, $business)->assertOk()->assertSee('data-role="automations-panel"', false)->assertSee('pending and skipped are excluded');
    }
}
