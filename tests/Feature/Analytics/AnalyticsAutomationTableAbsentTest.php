<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\Feature\Automations\Concerns\UsesFreshSchema;
use Tests\TestCase;

/**
 * B5 — contract §2.6 / §9: when the B4-owned automation_executions table
 * is absent the Automations panel must be ABSENT — neither zeros nor an
 * error. Dropping a table is DDL (implicit commit in MySQL), so this runs
 * on a fresh schema rather than inside RefreshDatabase's transaction.
 */
class AnalyticsAutomationTableAbsentTest extends TestCase
{
    use CreatesAnalyticsFixtures;
    use UsesFreshSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshSchema();
    }

    public function test_automations_panel_is_absent_when_the_ledger_table_does_not_exist(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->report($business, $business->customer_id);

        Schema::disableForeignKeyConstraints();
        Schema::drop('automation_executions');
        Schema::enableForeignKeyConstraints();

        $overview = app(BusinessAnalyticsPresenter::class)->buildOverview($business, AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone));

        $this->assertNull($overview->automations);
        $this->assertSame(1, $overview->messages->outbound, 'Every other section still renders normally.');

        $this->authenticateAsCustomer($customer);
        $this->overview($workspace, $business)
            ->assertOk()
            ->assertDontSee('data-role="automations-panel"', false)
            ->assertDontSee('Executions in the selected range');
    }
}
