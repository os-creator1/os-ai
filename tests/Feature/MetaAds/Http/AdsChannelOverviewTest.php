<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\MetaAds\MetaAdsMoney;
use App\Models\Business;
use App\Models\Customer;
use App\Models\GoogleAdsAccount;
use App\Models\MetaAdsAccount;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 (contract 24 §9) — the cross-channel Ads Overview: each
 * channel on its own terms, total spend only for a single shared currency, NOTHING
 * blended (no combined conversions, no blended cost per result, no summed
 * targets), calm connect CTAs, per-provider permissions, a capped provider-
 * labelled attention list and Business isolation (also under Agency View As).
 */
class AdsChannelOverviewTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp(withGoogle: true);
    }

    protected function tearDown(): void
    {
        $this->finishMetaHttp();
        parent::tearDown();
    }

    private function usd(int $micros): string
    {
        return MetaAdsMoney::format($micros, 'USD');
    }

    private function perms(): array
    {
        return [self::G_VIEW, self::G_MANAGE, self::META_VIEW, self::META_MANAGE];
    }

    private function googleReady(Business $business, array $overrides = [], int $dailyMicros = 26_000_000): GoogleAdsAccount
    {
        $account = $this->adsAccountFor($business, array_merge([
            'descriptive_name' => 'Snap Booth Ads',
            'last_successful_sync_at' => now()->subHour(),
            'data_through_date' => '2026-10-03',
        ], $overrides));
        $this->seedCampaign($account, '111', 'Wedding Photo Booth');
        $this->seedCampaignDays($account, '111', '2026-10-01', '2026-10-03', $dailyMicros, 20, '2');

        return $account;
    }

    private function metaReady(Business $business, array $overrides = [], int $dailyMicros = 20_000_000): MetaAdsAccount
    {
        $account = $this->metaSelected($business, $overrides);
        $this->seedMetaOctober($account, $dailyMicros);

        return $account;
    }

    /** @return array{0: Customer, 1: Business, 2: Workspace} */
    private function tenant3(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, string $name = 'Snap Booth Co'): array
    {
        return $this->metaHttpTenant($tier, $name);
    }

    private function html(Workspace $workspace, Business $business): string
    {
        return $this->get($this->channelsUrl($workspace, $business))->assertOk()->getContent();
    }

    private function block(string $html, string $channel): string
    {
        $start = strpos($html, 'data-channel="' . $channel . '"');
        $this->assertNotFalse($start, "the [{$channel}] block must be present");
        $next = strpos($html, 'data-role="channel-block"', $start + 10);
        $end = strpos($html, 'data-role="attention"', $start);

        return substr($html, $start, ($next !== false ? min($next, $end === false ? PHP_INT_MAX : $end) : ($end === false ? 4000 : $end - $start)) - ($next !== false ? $start : 0));
    }

    private function plain(string $html): string
    {
        return preg_replace('/\s+/', ' ', strip_tags($html)) ?? '';
    }

    // ---------------------------------------------------------------
    // Channel combinations
    // ---------------------------------------------------------------

    public function test_google_only_shows_the_google_block_and_a_calm_connect_cta_for_meta(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->googleReady($business);
        $this->asMetaUser($customer, $this->perms());

        $html = $this->html($workspace, $business);

        $google = $this->block($html, 'google');
        $this->assertStringContainsString($this->usd(78_000_000), $google);
        $this->assertStringContainsString('Google conversions', $google);
        $this->assertStringContainsString('Cost per conversion', $google);
        $this->assertStringContainsString($this->usd(13_000_000), $google);

        $this->assertStringContainsString('data-state="not_connected"', $html);
        $this->assertStringContainsString('Connect Meta Ads', $html);
        $this->assertStringContainsString('href="' . $this->metaPage($workspace, $business) . '"', $html);
        $this->assertStringNotContainsString('data-role="total-spend"', $html, 'one channel is not a total');
        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_meta_only_shows_the_meta_block_with_its_own_result_label_and_a_google_cta(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->metaReady($business);
        $this->asMetaUser($customer, $this->perms());

        $html = $this->html($workspace, $business);

        $meta = $this->block($html, 'meta');
        $this->assertStringContainsString($this->usd(60_000_000), $meta);
        $this->assertStringContainsString('Meta results (Leads (on-Facebook forms))', $meta);
        $this->assertStringContainsString($this->usd(10_000_000), $meta, 'cost per result: $60 / 6');
        $this->assertStringContainsString('Cost per result', $meta);

        $this->assertStringContainsString('Connect Google Ads', $html);
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.ads.index', [$workspace->uid, $business->uid]) . '"', $html);
        $this->assertStringNotContainsString('data-role="total-spend"', $html);
    }

    public function test_both_channels_in_one_currency_show_the_total_spend_and_nothing_else_blended(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->googleReady($business);
        $this->metaReady($business, ['monthly_budget_target_micros' => 300_000_000, 'target_cost_per_result_micros' => 20_000_000]);
        $this->asMetaUser($customer, $this->perms());

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString('data-role="total-spend"', $html);
        $this->assertStringContainsString($this->usd(138_000_000), substr($html, (int) strpos($html, 'data-role="total-spend-value"'), 200));
        $this->assertStringNotContainsString('data-role="mixed-currencies"', $html);

        $google = $this->block($html, 'google');
        $meta = $this->block($html, 'meta');
        $this->assertStringContainsString($this->usd(78_000_000), $google);
        $this->assertStringContainsString($this->usd(60_000_000), $meta);
        $this->assertStringNotContainsString($this->usd(60_000_000), $google);
        $this->assertStringNotContainsString($this->usd(78_000_000), $meta);

        $plain = $this->plain($html);
        foreach (['Total conversions', 'Total results', 'Combined', 'Blended', 'Total cost per', 'Total target', 'Average cost per'] as $blended) {
            $this->assertStringNotContainsString($blended, $plain, "[{$blended}] must not exist");
        }
        // 12 = the blended result count (6 + 6) and 11.5 / $11.50 = the blended cost per result must not appear.
        $this->assertStringNotContainsString($this->usd(11_500_000), $html);
        $this->assertStringNotContainsString($this->usd(300_000_000 + 0), $google, 'a Meta target never appears in the Google block');
    }

    public function test_different_currencies_are_shown_separately_with_no_total(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->googleReady($business);
        $this->metaReady($business, ['currency_code' => 'EUR']);
        $this->asMetaUser($customer, $this->perms());

        $html = $this->html($workspace, $business);

        $this->assertStringNotContainsString('data-role="total-spend"', $html);
        $this->assertStringContainsString('data-role="mixed-currencies"', $html);
        $this->assertStringContainsString('Different currencies are shown separately.', $html);
        $this->assertStringContainsString($this->usd(78_000_000), $this->block($html, 'google'));
        $this->assertStringContainsString(MetaAdsMoney::format(60_000_000, 'EUR'), $this->block($html, 'meta'));
        $this->assertStringNotContainsString(MetaAdsMoney::format(138_000_000, 'EUR'), $html);
        $this->assertStringNotContainsString($this->usd(138_000_000), $html);
    }

    public function test_a_meta_account_without_a_result_type_shows_a_call_to_action_in_its_block(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->metaReady($business, ['result_action_type' => null]);
        $this->asMetaUser($customer, $this->perms());

        $meta = $this->block($this->html($workspace, $business), 'meta');

        $this->assertStringContainsString('Choose a result type', $meta);
        $this->assertStringContainsString($this->metaPage($workspace, $business, 'settings'), $meta);
        $this->assertStringContainsString($this->usd(60_000_000), $meta, 'spend is still shown');
    }

    public function test_an_expired_meta_connection_shows_a_reconnect_cta_and_no_meta_figures(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->googleReady($business);
        $this->activeMetaConnection($business, [
            'state' => \App\Enums\MetaAds\MetaConnectionState::Expired, 'access_token_encrypted' => null, 'token_expires_at' => now()->subDay(),
        ]);
        $this->asMetaUser($customer, $this->perms());

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString('Reconnect Meta', $html);
        $this->assertStringContainsString('This connection has expired, so figures are not updating.', $html);
        $this->assertStringNotContainsString('data-role="total-spend"', $html);
    }

    public function test_the_freshness_of_each_channel_is_shown_on_its_own(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->googleReady($business, ['last_successful_sync_at' => now()->subHours(2)]);
        $this->metaReady($business, ['last_successful_sync_at' => now()->subHours(5)]);
        $this->asMetaUser($customer, $this->perms());

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString('Updated 2 hours ago', $this->block($html, 'google'));
        $this->assertStringContainsString('Updated 5 hours ago', $this->block($html, 'meta'));
        $this->assertStringContainsString('Data through Oct 3, 2026', $this->block($html, 'meta'));
    }

    // ---------------------------------------------------------------
    // Permissions and entitlement
    // ---------------------------------------------------------------

    public function test_each_provider_block_needs_that_providers_read_permission(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->googleReady($business);
        $this->metaReady($business);

        $this->asMetaUser($customer, [self::META_VIEW]);
        $metaOnly = $this->html($workspace, $business);
        $this->assertStringContainsString('data-channel="meta"', $metaOnly);
        $this->assertStringNotContainsString('data-channel="google"', $metaOnly, 'no Google block, not even a CTA');
        $this->assertStringNotContainsString($this->usd(78_000_000), $metaOnly);

        $this->asMetaUser($customer, [self::G_VIEW]);
        $googleOnly = $this->html($workspace, $business);
        $this->assertStringContainsString('data-channel="google"', $googleOnly);
        $this->assertStringNotContainsString('data-channel="meta"', $googleOnly);
        $this->assertStringNotContainsString($this->usd(60_000_000), $googleOnly);

        $this->asMetaUser($customer, [self::META_MANAGE, self::G_MANAGE]);
        $this->get($this->channelsUrl($workspace, $business))->assertStatus(401);
    }

    public function test_core_sees_the_channels_but_google_issue_counts_need_the_full_module(): void
    {
        $counts = [];

        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->tenant3($tier, 'Tier ' . $tier->value);
            $google = $this->adsAccountFor($business, ['last_successful_sync_at' => now()->subHour(), 'data_through_date' => '2026-10-03']);
            $this->seedCampaign($google, '222', 'Burning Money Search');
            $this->seedCampaignDays($google, '222', '2026-10-01', '2026-10-03', 100_000_000, 20, '0');
            $this->metaReady($business);
            $this->asMetaUser($customer, $this->perms());

            $html = $this->html($workspace, $business);
            $counts[$tier->value] = $html;
        }

        $core = $counts[WorkspacePlanTier::Core->value];
        $growth = $counts[WorkspacePlanTier::Growth->value];

        $this->assertStringNotContainsString('Burning Money Search', $core, 'Core has no Google facts');
        $this->assertStringNotContainsString('data-role="attention-item" data-provider="google"', $core);
        $this->assertStringContainsString('Burning Money Search', $growth);
        $this->assertStringContainsString('data-role="attention-item" data-provider="google"', $growth);
        $this->assertStringNotContainsString('data-role="channel-issues"', $this->block($core, 'google'));
        $this->assertStringContainsString('data-role="channel-issues"', $this->block($growth, 'google'));
    }

    public function test_a_business_without_any_ads_entitlement_is_a_404(): void
    {
        [$customer, $business, $workspace] = $this->unentitledMetaTenant();
        $this->asMetaUser($customer, $this->perms());

        $this->get($this->channelsUrl($workspace, $business))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Attention list
    // ---------------------------------------------------------------

    public function test_the_attention_list_merges_both_providers_labelled_capped_at_five_and_escaped(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();

        $google = $this->adsAccountFor($business, ['last_successful_sync_at' => now()->subHour(), 'data_through_date' => '2026-10-03']);
        foreach (['A', 'B', 'C', 'D'] as $i => $letter) {
            $this->seedCampaign($google, 'g' . $i, 'Google Waste ' . $letter);
            $this->seedCampaignDays($google, 'g' . $i, '2026-10-01', '2026-10-03', 100_000_000 + $i, 20, '0');
        }

        $meta = $this->metaSelected($business);
        foreach (['A', 'B', 'C', 'D'] as $i => $letter) {
            $name = $i === 3 ? 'Meta <b>Waste</b> D' : 'Meta Waste ' . $letter; // D spends most: it ranks first
            $campaign = $this->seedMetaCampaign($meta, $name);
            $this->seedMetaDays($meta, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', 30_000_000 + $i, 0);
        }
        $this->asMetaUser($customer, $this->perms());

        $html = $this->html($workspace, $business);

        $attention = substr($html, (int) strpos($html, 'data-role="attention"'));
        preg_match_all('/data-role="attention-item" data-provider="(google|meta)"/', $attention, $items);
        $this->assertCount(5, $items[1], 'capped at five');
        $this->assertContains('google', $items[1]);
        $this->assertContains('meta', $items[1], 'neither provider crowds out the other');
        $this->assertMatchesRegularExpression('/attention-provider">\s*Google\s*</', $attention);
        $this->assertMatchesRegularExpression('/attention-provider">\s*Meta\s*</', $attention);
        $this->assertStringNotContainsString('<b>Waste</b>', $html, 'customer strings are escaped');
        $this->assertStringContainsString('Meta &lt;b&gt;Waste&lt;/b&gt; D', $html);
        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_the_attention_list_costs_a_constant_number_of_queries(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->googleReady($business);
        $meta = $this->metaSelected($business);
        $this->asMetaUser($customer, $this->perms());

        $count = function () use ($workspace, $business): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($this->channelsUrl($workspace, $business))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        foreach (['One', 'Two'] as $i => $name) {
            $campaign = $this->seedMetaCampaign($meta, 'Meta ' . $name);
            $this->seedMetaDays($meta, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', 30_000_000 + $i, 0);
        }
        $count(); // warm-up: the first request of a process fills per-request caches
        $few = $count();

        foreach (['Three', 'Four', 'Five', 'Six', 'Seven'] as $i => $name) {
            $campaign = $this->seedMetaCampaign($meta, 'Meta ' . $name);
            $this->seedMetaDays($meta, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', 30_000_000 + $i, 0);
        }
        $many = $count();

        $this->assertSame($few, $many, 'no N+1: the same queries for two and for seven campaigns');
        $this->assertLessThanOrEqual(120, $many);
    }

    // ---------------------------------------------------------------
    // Isolation, View As, entry
    // ---------------------------------------------------------------

    public function test_a_business_never_sees_another_businesss_figures(): void
    {
        [$customer, $business, $workspace] = $this->tenant3(name: 'Mine Co');
        $this->metaReady($business, [], 20_000_000);

        [, $other] = $this->tenant3(name: 'Theirs Co');
        $this->metaReady($other, ['name' => 'Their Secret Ad Account'], 99_000_000);
        $this->googleReady($other, ['descriptive_name' => 'Their Google'], 77_000_000);
        $this->asMetaUser($customer, $this->perms());

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString($this->usd(60_000_000), $html);
        $this->assertStringNotContainsString($this->usd(297_000_000), $html);
        $this->assertStringNotContainsString($this->usd(231_000_000), $html);
        $this->assertStringNotContainsString('Their Secret', $html);
        $this->assertStringContainsString('Connect Google Ads', $html, 'my Google is not connected, theirs is invisible');
    }

    public function test_an_agency_viewing_a_client_sees_only_that_clients_data(): void
    {
        [$agency, $client, $clientWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $this->metaReady($client, [], 20_000_000);

        [, $stranger] = $this->tenant3(name: 'Unrelated Co');
        $this->metaReady($stranger, [], 99_000_000);

        $this->authenticateAs($agency);
        $this->startViewAs($clientWorkspace, $client)->assertRedirect(route('user.home'));

        $html = $this->html($clientWorkspace, $client);

        $this->assertStringContainsString($this->usd(60_000_000), $html);
        $this->assertStringNotContainsString($this->usd(297_000_000), $html);
        $this->assertStringContainsString('data-role="view-as-banner"', $html);

        // The unrelated Business is out of reach even while viewing.
        $strangerWorkspace = Workspace::query()->findOrFail($stranger->workspace_id);
        $this->get($this->channelsUrl($strangerWorkspace, $stranger))->assertNotFound();
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_the_bare_entry_redirects_a_single_business_to_the_cross_channel_overview(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->asMetaUser($customer, $this->perms());

        $this->get(route('customer.ads.index'))->assertRedirect($this->channelsUrl($workspace, $business));
    }

    public function test_a_meta_only_user_can_use_the_bare_entry(): void
    {
        [$customer, $business, $workspace] = $this->tenant3();
        $this->asMetaUser($customer, [self::META_VIEW]);

        $this->get(route('customer.ads.index'))->assertRedirect($this->channelsUrl($workspace, $business));

        $this->asMetaUser($customer, []);
        $this->get(route('customer.ads.index'))->assertStatus(401);
    }
}
