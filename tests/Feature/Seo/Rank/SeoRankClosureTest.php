<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankTrigger;
use App\Library\Seo\Rank\Provider\DataForSeoRankProvider;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\SeoRankBudgetDecision;
use App\Library\Seo\Rank\SeoRankTrackingBudget;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankProviderLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * Closure pass: deployment state (switch + credentials), history readable while
 * the provider is off, and regression fixtures of the DOCUMENTED provider payload
 * shapes (sanitized minimal structures, no credentials, no real result data).
 */
class SeoRankClosureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    public function test_the_provider_state_is_enabled_disabled_or_not_configured(): void
    {
        $budget = app(SeoRankTrackingBudget::class);

        $this->assertSame('enabled', $budget->providerState());
        $this->assertTrue($budget->enabled());

        config(['seo.rank_tracking.enabled' => false]);
        $this->assertSame('disabled', $budget->providerState());

        config(['seo.rank_tracking.enabled' => true]);
        FakeSeoRankProvider::$configured = false;
        $this->assertSame('not_configured', $budget->providerState());
        $this->assertFalse($budget->enabled());
    }

    public function test_the_switch_on_without_credentials_reserves_nothing_and_calls_nothing(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        FakeSeoRankProvider::$configured = false;

        $decision = app(SeoRankTrackingBudget::class)->reserveRun($target, SeoRankCheckType::Organic, SeoRankTrigger::Manual, 'x:1');

        $this->assertFalse($decision->allowed);
        $this->assertSame(SeoRankBudgetDecision::DISABLED, $decision->reason);
        $this->assertTrue($decision->isUnavailable());
        $this->assertFalse($decision->isBudgetPause(), 'Unavailable is not a spend pause.');
        $this->assertSame(0, SeoRankCheckRun::query()->count());
        $this->assertSame(0, SeoRankProviderLedger::query()->count());
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    public function test_history_stays_readable_when_the_provider_is_off(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Core);
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        $this->obs($target, 'organic', 6);
        config(['seo.rank_tracking.enabled' => false]);
        $this->authenticateAsSeoCustomer($owner);

        $html = (string) $this->get(route('customer.workspaces.businesses.seo.keywords.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('data-position="6"', $html);
        $this->assertStringContainsString('Rank checks are unavailable', $html);
        $this->assertStringNotContainsString('usage period resets', $html);

        $this->get(route('customer.workspaces.businesses.seo.rank-targets.show', [$workspace->uid, $business->uid, $target->uid]))
            ->assertOk()
            ->assertSee('Rank checks are not available right now');
    }

    // ------------------------------------------------------------------
    // Documented payload shapes (sanitized, minimal)
    // ------------------------------------------------------------------

    private function liveProvider(): DataForSeoRankProvider
    {
        config([
            'seo.rank_tracking.dataforseo.login' => 'login@example.test',
            'seo.rank_tracking.dataforseo.password' => 'not-a-real-password',
            'seo.rank_tracking.dataforseo.base_url' => 'https://api.dataforseo.com',
        ]);
        Http::swap(new Factory());

        return new DataForSeoRankProvider();
    }

    public function test_tasks_ready_reads_the_tag_from_the_top_level_of_each_row_for_both_endpoints(): void
    {
        $provider = $this->liveProvider();

        foreach (['organic' => 'organic', 'local' => 'local_finder'] as $type => $segment) {
            Http::swap(new Factory());
            Http::fake([
                '*' => Http::response(['status_code' => 20000, 'tasks' => [[
                    'status_code' => 20000,
                    'result_count' => 2,
                    'result' => [
                        [
                            'id' => '11081554-0696-0066-0000-27e68ec15871', 'se' => 'google', 'se_type' => $segment,
                            'date_posted' => '2019-11-08 13:54:43 +00:00', 'tag' => 'run-uid-1',
                            'endpoint_regular' => "/v3/serp/google/{$segment}/task_get/regular/11081554-0696-0066-0000-27e68ec15871",
                            'endpoint_advanced' => "/v3/serp/google/{$segment}/task_get/advanced/11081554-0696-0066-0000-27e68ec15871",
                        ],
                        ['id' => '22222222-0696-0066-0000-27e68ec15871', 'se' => 'google', 'se_type' => $segment, 'tag' => 'run-uid-2'],
                    ],
                ]]], 200),
            ]);

            $this->assertSame(
                ['run-uid-1' => '11081554-0696-0066-0000-27e68ec15871', 'run-uid-2' => '22222222-0696-0066-0000-27e68ec15871'],
                $provider->readyTasksByTag($type),
                "[{$type}] tasks_ready tag is a top-level row field."
            );
        }
    }

    public function test_local_finder_task_get_advanced_parses_the_documented_local_pack_shape(): void
    {
        $provider = $this->liveProvider();

        Http::fake([
            '*' => Http::response(['status_code' => 20000, 'cost' => 0.0006, 'tasks' => [[
                'status_code' => 20000,
                'cost' => 0.0006,
                'result' => [[
                    'keyword' => 'photo booth rental', 'type' => 'local_finder', 'se_domain' => 'google.com',
                    'check_url' => 'https://www.google.com/search?q=example', 'items_count' => 2,
                    'items' => [
                        [
                            'type' => 'local_pack', 'rank_group' => 1, 'rank_absolute' => 1, 'position' => 'left',
                            'title' => 'Example Booths', 'domain' => 'example-booths.test', 'phone' => '+1 312 555 0100',
                            'url' => 'https://example-booths.test/', 'cid' => '1234567890123456789',
                            'rating' => ['rating_type' => 'Max5', 'value' => 4.8, 'votes_count' => 120, 'rating_max' => 5],
                        ],
                        [
                            'type' => 'local_pack', 'rank_group' => 2, 'rank_absolute' => 2, 'position' => 'left',
                            'title' => 'Other Booths', 'domain' => null, 'phone' => '(312) 555-0199', 'url' => null, 'cid' => '9876543210987654321',
                        ],
                    ],
                ]],
            ]]], 200),
        ]);

        $result = $provider->fetch('local', '11081554-0696-0066-0000-27e68ec15871');

        $this->assertSame('completed', $result->state);
        $this->assertSame(600, $result->costMicros);
        $this->assertCount(2, $result->items);
        $this->assertSame(1, $result->items[0]->position);
        $this->assertSame('example-booths.test', $result->items[0]->domain);
        $this->assertSame('+1 312 555 0100', $result->items[0]->phone);
        $this->assertSame('1234567890123456789', $result->items[0]->cid);
        $this->assertNull($result->items[1]->domain);
        $this->assertSame('(312) 555-0199', $result->items[1]->phone);
    }

    public function test_organic_task_get_regular_parses_rank_group_and_ignores_other_serp_items(): void
    {
        $provider = $this->liveProvider();

        Http::fake([
            '*' => Http::response(['status_code' => 20000, 'tasks' => [[
                'status_code' => 20000,
                'cost' => 0.006,
                'result' => [[
                    'items_count' => 4,
                    'items' => [
                        ['type' => 'local_pack', 'rank_group' => 1, 'rank_absolute' => 1, 'title' => 'Maps block'],
                        ['type' => 'organic', 'rank_group' => 1, 'rank_absolute' => 3, 'domain' => 'a.test', 'url' => 'https://a.test/x'],
                        ['type' => 'paid', 'rank_group' => 1, 'rank_absolute' => 2, 'domain' => 'ads.test', 'url' => 'https://ads.test/'],
                        ['type' => 'organic', 'rank_group' => 2, 'rank_absolute' => 5, 'domain' => 'photoboothco.com', 'url' => 'https://photoboothco.com/rentals'],
                    ],
                ]],
            ]]], 200),
        ]);

        $result = $provider->fetch('organic', '11081554-0696-0066-0000-27e68ec15871');

        $this->assertSame(6000, $result->costMicros);
        $this->assertSame([1, 2], array_map(fn ($i) => $i->position, $result->items), 'Only organic rows, positioned by rank_group.');
        $this->assertSame('photoboothco.com', $result->items[1]->domain);
    }

    public function test_the_provider_reports_configuration_without_revealing_secrets(): void
    {
        config(['seo.rank_tracking.dataforseo.login' => '', 'seo.rank_tracking.dataforseo.password' => '', 'seo.rank_tracking.dataforseo.base_url' => 'https://api.dataforseo.com']);
        $this->assertFalse((new DataForSeoRankProvider())->isConfigured());

        config(['seo.rank_tracking.dataforseo.login' => 'a@example.test', 'seo.rank_tracking.dataforseo.password' => 'p']);
        $this->assertTrue((new DataForSeoRankProvider())->isConfigured());

        config(['seo.rank_tracking.dataforseo.base_url' => 'http://insecure.example.test']);
        $this->assertFalse((new DataForSeoRankProvider())->isConfigured(), 'Only https endpoints are accepted.');
    }
}
