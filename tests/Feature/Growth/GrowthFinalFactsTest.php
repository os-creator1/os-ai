<?php

namespace Tests\Feature\Growth;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\Growth\GrowthEvaluationService;
use App\Library\Growth\GrowthFactSnapshotBuilder;
use App\Models\Business;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\GoogleAdsDailyMetric;
use App\Models\BusinessGoogleConnection;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * Growth final fact integration: Google Ads, rank tracking, website search visibility and forms,
 * consumed from the modules' own stored facts. Two Businesses with opposite health must produce
 * different, stable, tenant-isolated recommendations; missing or unentitled sources stay neutral;
 * nothing calls a provider.
 */
class GrowthFinalFactsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
        config(['opportunity.enabled' => true]);
        Http::preventStrayRequests();
    }

    private function evaluate(Business $business): array
    {
        return app(GrowthEvaluationService::class)->evaluate($business->fresh());
    }

    /** @return list<string> */
    private function types(Business $business): array
    {
        return Opportunity::where('business_id', $business->id)->where('freshness', 'current')->orderBy('id')->pluck('type')->all();
    }

    private function website(Business $business, bool $homeHidden): void
    {
        $websiteId = DB::table('websites')->where('business_id', $business->id)->value('id') ?? DB::table('websites')->insertGetId([
            'uid' => (string) Str::uuid(), 'public_id' => (string) Str::uuid(), 'business_id' => $business->id,
            'name' => 'Site', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $revisionId = DB::table('website_revisions')->insertGetId([
            'uid' => (string) Str::uuid(), 'website_id' => $websiteId, 'version_number' => (int) DB::table('website_revisions')->where('website_id', $websiteId)->max('version_number') + 1, 'schema_version' => 1,
            'created_by' => (int) DB::table('users')->value('id'), 'created_at' => now()->subDay(),
            'snapshot' => json_encode(['pages' => [
                ['uid' => (string) Str::uuid(), 'slug' => 'home', 'is_home' => true, 'title' => 'Photo booth rental', 'seo' => ['seo_title' => 'Photo booth rental', 'meta_description' => 'Rent a booth.', 'noindex' => $homeHidden], 'sections' => []],
                ['uid' => (string) Str::uuid(), 'slug' => 'thanks', 'is_home' => false, 'title' => 'Thanks', 'seo' => ['noindex' => true], 'sections' => []],
            ], 'assets' => []]),
        ]);
        DB::table('websites')->where('id', $websiteId)->update(['status' => 'published', 'published_revision_id' => $revisionId]);
    }

    private function googleAds(Business $business, array $campaigns, int $settledDaysAgo = 10): GoogleAdsAccount
    {
        $connection = BusinessGoogleConnection::create([
            'business_id' => $business->id, 'product' => GoogleConnectionProduct::GoogleAds, 'state' => GoogleConnectionState::Active,
            'refresh_token_encrypted' => 'plain-ads-refresh-token', 'granted_scopes' => GoogleConnectionProduct::GoogleAds->scope(),
            'google_account_email' => 'owner@example.test', 'connected_at' => now(), 'connected_by_user_id' => $business->customer_id,
        ]);
        $account = GoogleAdsAccount::create([
            'business_id' => $business->id, 'business_google_connection_id' => $connection->id, 'customer_id' => '1234567890',
            'currency_code' => 'USD', 'time_zone' => 'America/New_York', 'selected_at' => now()->subDays($settledDaysAgo),
            'last_successful_sync_at' => now()->subHour(), 'data_through_date' => now()->toDateString(), 'target_cpl_micros' => 10_000_000,
        ]);

        foreach ($campaigns as $i => [$name, $cost, $conversions]) {
            $id = (string) ($i + 1);
            GoogleAdsCampaign::create([
                'business_id' => $business->id, 'google_ads_account_id' => $account->id, 'external_campaign_id' => $id, 'name' => $name,
                'status' => GoogleAdsEntityStatus::Enabled, 'channel_type' => 'SEARCH', 'budget_amount_micros' => 4_000_000, 'budget_shared' => false,
            ]);
            GoogleAdsDailyMetric::create([
                'business_id' => $business->id, 'google_ads_account_id' => $account->id, 'level' => GoogleAdsMetricLevel::Campaign,
                'entity_key' => $id, 'metric_date' => now()->subDays(2)->toDateString(), 'impressions' => 100, 'clicks' => 10, 'interactions' => 10,
                'cost_micros' => $cost, 'conversions' => $conversions, 'conversions_value' => null,
            ]);
        }

        return $account;
    }

    /** @param list<array{0: int|null, 1: int|null}> $moves previous => current organic position */
    private function ranks(Business $business, $owner, array $moves): void
    {
        foreach ($moves as $i => [$previous, $current]) {
            $target = $this->track($owner, $business, $this->keyword($owner, $business, 'kw ' . $i));
            $this->obs($target, 'organic', $previous, now()->subDays(7));
            $this->obs($target, 'organic', $current, now()->subDay());
        }
    }

    public function test_two_businesses_with_opposite_health_get_different_stable_isolated_recommendations(): void
    {
        [$ownerA, $a] = $this->rankTenant(WorkspacePlanTier::Growth, 'a-booths.com');
        $this->website($a, homeHidden: false);
        $this->googleAds($a, [['Strong', 100_000_000, '10']]);                 // CPL 10 == target: strong
        $this->ranks($a, $ownerA, [[12, 7], [9, 4], [15, 8]]);                 // improving

        [$ownerB, $b] = $this->rankTenant(WorkspacePlanTier::Growth, 'b-booths.com');
        $this->website($b, homeHidden: true);
        $this->googleAds($b, [['Costly', 130_000_000, '10'], ['Zero', 90_000_000, '0']]);
        $this->ranks($b, $ownerB, [[4, 12], [3, 9], [10, 15]]);                // falling, one just outside the top 10

        $this->evaluate($a);
        $this->evaluate($b);
        $typesA = $this->types($a);
        $typesB = $this->types($b);

        // Healthy Business A: none of the problems B has.
        foreach (['ads.cpl_above_target:v1', 'ads.zero_conversion_spend:v1', 'website.pages_hidden_from_search:v1', 'seo.meaningful_rank_drop:v1'] as $type) {
            $this->assertNotContains($type, $typesA, "A must not get {$type}");
            $this->assertContains($type, $typesB, "B must get {$type}");
        }

        // No duplicated recommendation inside a Business, and no cross-tenant row.
        $this->assertSame($typesB, array_values(array_unique($typesB)));
        $this->assertSame(0, Opportunity::where('business_id', $a->id)->whereIn('type', ['ads.cpl_above_target:v1', 'ads.zero_conversion_spend:v1'])->count());

        // Stable ordering: re-evaluating changes neither the set nor the priority order.
        $orderOf = fn (Business $business) => Opportunity::where('business_id', $business->id)->where('freshness', 'current')
            ->orderByDesc('priority_score')->orderBy('id')->pluck('type')->all();
        $before = $orderOf($b);
        $this->evaluate($b);
        $this->assertSame($before, $orderOf($b));

        // Different priorities: B's top finding is an Ads or rank problem, A has no such problem at all.
        $this->assertNotEmpty($before);
        $this->assertNotSame($orderOf($a), $before);

        // Gains are a positive statement, not a finding.
        $this->assertNotContains('seo.rank_gain:v1', $typesA);

        Http::assertNothingSent();
    }

    public function test_unentitled_or_unconnected_sources_are_neutral_never_negative(): void
    {
        // Core: no full Ads module -> ads NOT ENTITLED; no rank capability -> rank not entitled; the score is not dragged down.
        [, $core] = $this->rankTenant(WorkspacePlanTier::Core, 'core-booths.com');
        $result = $this->evaluate($core);

        $facts = app(GrowthFactSnapshotBuilder::class)->build($core->fresh())->sets();
        $this->assertContains($facts['ads']->status->value, ['not_entitled', 'unavailable']);
        $this->assertSame([], $facts['ads']->data);
        $this->assertSame(0, Opportunity::where('business_id', $core->id)->where('worker_key', 'ads')->count());
        $this->assertNull($result['score']?->category_scores['ads'] ?? null, 'Ads is not scored without data');

        // Growth, Ads module included, nothing connected: the performance rules are insufficient (neutral), and the only
        // Ads finding is the unscored connection prompt.
        [, $growth] = $this->rankTenant(WorkspacePlanTier::Growth, 'growth-booths.com');
        $this->evaluate($growth);
        $adsTypes = Opportunity::where('business_id', $growth->id)->where('worker_key', 'ads')->pluck('type')->all();
        $this->assertSame(['ads.connection_needed:v1'], $adsTypes);
        $score = DB::table('growth_score_snapshots')->where('business_id', $growth->id)->orderByDesc('id')->first();
        $this->assertNull(json_decode((string) ($score->category_scores ?? '{}'), true)['ads'] ?? null);
    }

    public function test_a_freshly_connected_account_or_a_stale_sync_never_scores_or_judges(): void
    {
        [, $fresh] = $this->rankTenant(WorkspacePlanTier::Growth, 'fresh-booths.com');
        $this->googleAds($fresh, [['Zero', 90_000_000, '0']], settledDaysAgo: 1);   // connected yesterday
        $this->evaluate($fresh);
        $this->assertNotContains('ads.zero_conversion_spend:v1', $this->types($fresh), 'not judged until the account has settled');

        [, $stale] = $this->rankTenant(WorkspacePlanTier::Growth, 'stale-booths.com');
        $account = $this->googleAds($stale, [['Zero', 90_000_000, '0']]);
        $account->forceFill(['last_successful_sync_at' => now()->subDays(5)])->save();
        $this->evaluate($stale);
        $types = $this->types($stale);
        $this->assertNotContains('ads.zero_conversion_spend:v1', $types, 'stale data is not judged');
        $this->assertContains('ads.sync_stale:v1', $types);
    }
}
