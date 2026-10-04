<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Jobs\MetaAds\SyncMetaAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 (contract 24 §3/§5.2) — Settings (connection + token
 * status, targets in the account currency, the owner-chosen result type from the
 * allow-list), the queue-only manual refresh, and the read-only view.
 */
class MetaAdsSettingsAndRefreshTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp();
    }

    protected function tearDown(): void
    {
        $this->finishMetaHttp();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // The page
    // ---------------------------------------------------------------

    public function test_the_settings_page_shows_status_account_targets_token_expiry_and_sync_state(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business, [
            'monthly_budget_target_micros' => 250_000_000,
            'target_cost_per_result_micros' => 25_500_000,
            'last_successful_sync_at' => now()->subHours(3),
        ]);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Connected', $html);
        $this->assertStringContainsString('Pat Booth', $html);
        $this->assertStringContainsString('Photo Booth Co - Ads', $html);
        $this->assertStringContainsString('America/New_York', $html);
        $this->assertStringContainsString('value="250.00"', $html);
        $this->assertStringContainsString('value="25.50"', $html);
        $this->assertStringContainsString('Access expires', $html);
        $this->assertStringContainsString(now()->addDays(50)->format('M j, Y'), $html);
        $this->assertStringNotContainsString('Reconnect Meta before', $html, 'a token 50 days from expiry shows no warning');
        $this->assertStringContainsString('Updated 3 hours ago', $html);
        $this->assertStringContainsString('Data through Oct 3, 2026', $html);
        $this->assertStringContainsString('data-role="refresh-now"', $html);
        $this->assertStringContainsString('data-role="disconnect-meta-ads"', $html);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_the_page_never_renders_a_secret_a_token_or_the_ad_account_id(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();

        foreach (['plain-meta-access-token', 'test-meta-app-secret', 'test-meta-app-id', $account->ad_account_id, 'access_token_encrypted'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_a_token_close_to_expiry_shows_a_reconnect_warning_and_button(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business);
        BusinessMetaConnectionHelper::expireIn($business, 3);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Reconnect Meta before', $html);
        $this->assertStringContainsString(now()->addDays(3)->format('M j, Y'), $html);
        $this->assertStringContainsString('3 days left', $html);
        $this->assertStringContainsString('data-role="reconnect-meta-ads"', $html);
    }

    public function test_an_expired_connection_shows_reconnect_and_hides_the_target_form(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business, [
            'state' => \App\Enums\MetaAds\MetaConnectionState::Expired, 'access_token_encrypted' => null, 'token_expires_at' => now()->subDay(),
        ]);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Expired: reconnect to continue', $html);
        $this->assertStringContainsString('data-role="reconnect-meta-ads"', $html);
        $this->assertStringNotContainsString('data-role="settings-form"', $html);
    }

    public function test_a_view_only_user_sees_the_settings_read_only(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business);
        $this->asMetaUser($customer, [self::META_VIEW]);

        $html = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="save-settings"', $html);
        $this->assertStringNotContainsString('data-role="refresh-now"', $html);
        $this->assertStringNotContainsString('data-role="disconnect-meta-ads"', $html);
        $this->assertStringContainsString('You do not have permission to change these settings.', $html);
    }

    public function test_settings_without_a_connection_offers_connect_and_hides_the_form(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Not connected', $html);
        $this->assertStringContainsString('data-role="connect-meta-ads"', $html);
        $this->assertStringNotContainsString('data-role="settings-form"', $html);
    }

    public function test_only_allow_listed_result_types_are_offered(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business, ['result_action_type' => null]);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();

        foreach (config('meta_ads.result_types') as $key => $label) {
            $this->assertStringContainsString('value="' . $key . '"', $html);
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('Not chosen yet', $html);
        $this->assertStringNotContainsString('landing_page_view', $html);
    }

    // ---------------------------------------------------------------
    // Targets
    // ---------------------------------------------------------------

    public function test_targets_are_stored_in_micros_of_the_account_currency(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'settings.update'), ['monthly_budget_target' => '250.50', 'target_cost_per_result' => '25', 'result_action_type' => 'lead'])
            ->assertRedirect($this->metaPage($workspace, $business, 'settings'));

        $account->refresh();
        $this->assertSame(250_500_000, $account->monthly_budget_target_micros);
        $this->assertSame(25_000_000, $account->target_cost_per_result_micros);
        $this->assertSame('lead', $account->result_action_type);
        $this->assertSame('success', session('status'));
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_thousands_separators_and_spaces_are_accepted(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'settings.update'), ['monthly_budget_target' => ' 1,250 ', 'target_cost_per_result' => ''])->assertRedirect();

        $this->assertSame(1_250_000_000, $account->fresh()->monthly_budget_target_micros);
        $this->assertNull($account->fresh()->target_cost_per_result_micros);
    }

    public function test_blank_or_zero_clears_a_target_to_null_and_blank_unsets_the_result_type(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['monthly_budget_target_micros' => 250_000_000, 'target_cost_per_result_micros' => 25_000_000]);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'settings.update'), ['monthly_budget_target' => '', 'target_cost_per_result' => '0', 'result_action_type' => ''])->assertRedirect();

        $account->refresh();
        $this->assertNull($account->monthly_budget_target_micros);
        $this->assertNull($account->target_cost_per_result_micros);
        $this->assertNull($account->result_action_type, 'blank = unset (results become unavailable, not zero)');
    }

    public function test_invalid_amounts_and_result_types_are_rejected_without_changing_anything(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['monthly_budget_target_micros' => 250_000_000, 'target_cost_per_result_micros' => 25_000_000]);
        $this->asMetaUser($customer);

        foreach ([
            ['monthly_budget_target' => 'abc'],
            ['monthly_budget_target' => '-5'],
            ['monthly_budget_target' => '1.234'],
            ['monthly_budget_target' => '9999999999'],
            ['target_cost_per_result' => '1e3'],
            ['target_cost_per_result' => '99999999'],
            ['monthly_budget_target' => ['1']],
            ['result_action_type' => 'landing_page_view'],
            ['result_action_type' => 'lead; DROP TABLE x'],
            ['result_action_type' => ['lead']],
        ] as $input) {
            $this->post($this->metaPage($workspace, $business, 'settings.update'), $input + ['monthly_budget_target' => '250', 'target_cost_per_result' => '25'])
                ->assertRedirect($this->metaPage($workspace, $business, 'settings'))
                ->assertSessionHasErrors();

            $account->refresh();
            $this->assertSame(250_000_000, $account->monthly_budget_target_micros, json_encode($input));
            $this->assertSame(25_000_000, $account->target_cost_per_result_micros, json_encode($input));
            $this->assertSame(self::RESULT_TYPE, $account->result_action_type, json_encode($input));
        }
    }

    public function test_changing_the_result_type_is_only_a_pointer_change_and_never_rewrites_stored_rows(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $campaign = $this->seedMetaCampaign($account, 'Wedding Photo Booth');
        $this->seedMetaDays($account, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', 10_000_000, 4);
        $this->seedMetaResult($account, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-02', 7, null, 'link_click');
        $before = \App\Models\MetaAdsDailyResult::query()->orderBy('id')->get()->map->only(['entity_id', 'metric_date', 'action_type', 'results'])->all();
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'settings.update'), ['result_action_type' => 'link_click'])->assertRedirect();

        $this->assertSame('link_click', $account->fresh()->result_action_type);
        $after = \App\Models\MetaAdsDailyResult::query()->orderBy('id')->get()->map->only(['entity_id', 'metric_date', 'action_type', 'results'])->all();
        $this->assertEquals($before, $after);
    }

    public function test_saving_without_an_account_changes_nothing_and_explains(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'settings.update'), ['monthly_budget_target' => '500'])
            ->assertRedirect($this->metaPage($workspace, $business, 'settings'));

        $this->assertSame('error', session('status'));
        $this->assertDatabaseCount('meta_ads_accounts', 0);
    }

    // ---------------------------------------------------------------
    // Refresh
    // ---------------------------------------------------------------

    public function test_a_refresh_queues_a_sync_and_never_calls_meta_in_the_request(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['last_successful_sync_at' => now()->subHours(5)]);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'refresh'))->assertRedirect($this->metaPage($workspace, $business, 'settings'));

        $this->assertSame('success', session('status'));
        $this->assertStringContainsString('Refresh started', (string) session('message'));
        Queue::assertPushed(SyncMetaAdsAccount::class);
        $this->assertDatabaseHas('meta_ads_sync_runs', ['meta_ads_account_id' => $account->id, 'state' => 'queued', 'trigger' => 'manual']);
        $this->assertSame(0, $this->fakeMeta->callCount(), 'the sync is queued, not run');

        // A second click while that run is queued is "already in progress".
        $this->post($this->metaPage($workspace, $business, 'refresh'))->assertRedirect();
        $this->assertSame('info', session('status'));
        Queue::assertPushed(SyncMetaAdsAccount::class, 1);
    }

    public function test_a_recently_refreshed_account_says_it_is_fresh_enough_and_queues_nothing(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business, ['last_successful_sync_at' => now()->subMinutes(10)]);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'refresh'))->assertRedirect($this->metaPage($workspace, $business, 'settings'));

        $this->assertSame('info', session('status'));
        $this->assertStringContainsString('refreshed recently', (string) session('message'));
        $this->assertStringContainsString('You can refresh again', (string) session('message'));
        Queue::assertNotPushed(SyncMetaAdsAccount::class);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_refresh_without_an_account_explains_and_queues_nothing(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'refresh'))->assertRedirect($this->metaPage($workspace, $business, 'settings'));

        $this->assertSame('error', session('status'));
        Queue::assertNotPushed(SyncMetaAdsAccount::class);
    }

    public function test_a_failed_sync_is_explained_on_the_settings_page_with_the_last_good_date(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business, [
            'last_successful_sync_at' => now()->subDay(),
            'data_through_date' => '2026-10-02',
            'last_sync_failure_code' => 'rate_limited',
        ]);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Latest refresh failed: Meta is rate limiting requests.', $html);
        $this->assertStringContainsString('Data through Oct 2, 2026', $html);
    }
}

/** Test-local helper: set a connection's token expiry without touching the token. */
final class BusinessMetaConnectionHelper
{
    public static function expireIn(\App\Models\Business $business, int $days): void
    {
        \App\Models\BusinessMetaConnection::query()
            ->where('business_id', $business->id)
            ->update(['token_expires_at' => now()->addDays($days)]);
    }
}
