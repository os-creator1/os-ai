<?php

namespace Tests\Feature\GoogleAds\Http;

use App\Jobs\GoogleAds\SyncGoogleAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\GoogleAds\Http\Concerns\CreatesAdsHttpFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 — Settings (targets stored in MICROS of the account
 * currency, connection and sync status, no secrets) and the manual refresh,
 * which only QUEUES a sync: the request itself never calls Google.
 */
class AdsSettingsAndRefreshTest extends TestCase
{
    use CreatesAdsHttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdsHttp();
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Settings page
    // ---------------------------------------------------------------

    public function test_the_settings_page_shows_status_account_targets_and_sync_state(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business, [
            'customer_id' => '1234567890',
            'descriptive_name' => 'Snap Booth Ads',
            'monthly_budget_target_micros' => 250_000_000,
            'target_cpl_micros' => 25_500_000,
            'last_successful_sync_at' => now()->subHours(3),
            'data_through_date' => '2026-10-03',
        ]);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Connected', $html);
        $this->assertStringContainsString('owner@example.test', $html);
        $this->assertStringContainsString('Snap Booth Ads', $html);
        $this->assertStringContainsString('123-456-7890', $html);
        $this->assertStringContainsString('America/New_York', $html);
        $this->assertStringContainsString('value="250.00"', $html);
        $this->assertStringContainsString('value="25.50"', $html);
        $this->assertStringContainsString('Updated 3 hours ago', $html);
        $this->assertStringContainsString('Data through Oct 3, 2026', $html);
        $this->assertStringContainsString('data-role="refresh-now"', $html);
        $this->assertStringContainsString('data-role="disconnect-google-ads"', $html);
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_the_settings_page_never_renders_a_secret_or_a_provider_payload(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business);
        config([
            'services.google_ads.client_secret' => 'super-secret-client-value',
            'services.google_ads.developer_token' => 'super-secret-developer-token',
        ]);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business, 'settings'))->assertOk()->getContent();

        foreach (['plain-ads-refresh-token', 'super-secret-client-value', 'super-secret-developer-token', 'test-ads-client-id', 'refresh_token', 'oauth_state_nonce'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_a_view_only_user_sees_the_settings_read_only(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business);
        $this->asAdsUser($customer, [self::VIEW]);

        $html = $this->get($this->adsUrl($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="save-targets"', $html);
        $this->assertStringNotContainsString('data-role="refresh-now"', $html);
        $this->assertStringNotContainsString('data-role="disconnect-google-ads"', $html);
        $this->assertStringContainsString('You do not have permission to change these targets.', $html);
    }

    public function test_settings_without_an_account_offers_connect_and_hides_the_target_form(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Not connected', $html);
        $this->assertStringContainsString('data-role="connect-google-ads"', $html);
        $this->assertStringNotContainsString('data-role="targets-form"', $html);
    }

    // ---------------------------------------------------------------
    // Saving targets
    // ---------------------------------------------------------------

    public function test_targets_are_stored_in_micros_of_the_account_currency(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'settings.update'), ['monthly_budget_target' => '250.50', 'target_cpl' => '25'])
            ->assertRedirect($this->adsUrl($workspace, $business, 'settings'));

        $account = $account->fresh();
        $this->assertSame(250_500_000, $account->monthly_budget_target_micros);
        $this->assertSame(25_000_000, $account->target_cpl_micros);
        $this->assertSame('success', session('status'));
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_thousands_separators_and_spaces_are_accepted(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'settings.update'), ['monthly_budget_target' => ' 1,250 ', 'target_cpl' => ''])->assertRedirect();

        $this->assertSame(1_250_000_000, $account->fresh()->monthly_budget_target_micros);
        $this->assertNull($account->fresh()->target_cpl_micros);
    }

    public function test_blank_or_zero_clears_a_target_to_null(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, ['monthly_budget_target_micros' => 250_000_000, 'target_cpl_micros' => 25_000_000]);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'settings.update'), ['monthly_budget_target' => '', 'target_cpl' => '0'])->assertRedirect();

        $account = $account->fresh();
        $this->assertNull($account->monthly_budget_target_micros);
        $this->assertNull($account->target_cpl_micros);

        $this->post($this->adsUrl($workspace, $business, 'settings.update'), [])->assertRedirect();
        $this->assertNull($account->fresh()->monthly_budget_target_micros, 'omitted fields clear, never store 0');
    }

    public function test_invalid_amounts_are_rejected_without_changing_anything(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, ['monthly_budget_target_micros' => 250_000_000, 'target_cpl_micros' => 25_000_000]);
        $this->asAdsUser($customer);

        $bad = [
            ['monthly_budget_target' => '-5'],
            ['monthly_budget_target' => 'abc'],
            ['monthly_budget_target' => '12.345'],
            ['monthly_budget_target' => '1e6'],
            ['monthly_budget_target' => '999999999999'],
            ['monthly_budget_target' => '100000001'],
            ['target_cpl' => '1000001'],
            ['target_cpl' => '-0.5'],
            ['monthly_budget_target' => ['1', '2']],
        ];

        foreach ($bad as $input) {
            $this->post($this->adsUrl($workspace, $business, 'settings.update'), $input)
                ->assertRedirect($this->adsUrl($workspace, $business, 'settings'))
                ->assertSessionHasErrors();

            $account = $account->fresh();
            $this->assertSame(250_000_000, $account->monthly_budget_target_micros, json_encode($input));
            $this->assertSame(25_000_000, $account->target_cpl_micros, json_encode($input));
        }
    }

    public function test_saving_without_an_account_changes_nothing_and_explains(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'settings.update'), ['monthly_budget_target' => '100'])
            ->assertRedirect($this->adsUrl($workspace, $business, 'settings'));

        $this->assertSame('error', session('status'));
        $this->assertDatabaseCount('google_ads_accounts', 0);
    }

    // ---------------------------------------------------------------
    // Manual refresh
    // ---------------------------------------------------------------

    public function test_a_refresh_queues_a_sync_and_never_calls_google_in_the_request(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, ['last_successful_sync_at' => now()->subHours(5)]);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'refresh'))
            ->assertRedirect($this->adsUrl($workspace, $business, 'settings'));

        $this->assertSame('success', session('status'));
        $this->assertStringContainsString('Refresh started', (string) session('message'));
        Queue::assertPushed(SyncGoogleAdsAccount::class);
        $this->assertDatabaseHas('google_ads_sync_runs', ['google_ads_account_id' => $account->id, 'state' => 'queued', 'trigger' => 'manual']);
        $this->assertSame(0, $this->fakeAds->callCount(), 'the sync is queued, not run');

        // A second request while that run is waiting is not queued again.
        $this->post($this->adsUrl($workspace, $business, 'refresh'))->assertRedirect();
        $this->assertSame('info', session('status'));
        $this->assertStringContainsString('already in progress', (string) session('message'));
        Queue::assertPushed(SyncGoogleAdsAccount::class, 1);
    }

    public function test_a_recently_refreshed_account_says_it_is_fresh_enough_and_queues_nothing(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business, ['last_successful_sync_at' => now()->subMinutes(10)]);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'refresh'))->assertRedirect($this->adsUrl($workspace, $business, 'settings'));

        $this->assertSame('info', session('status'));
        $this->assertStringContainsString('refreshed recently', (string) session('message'));
        $this->assertStringContainsString('You can refresh again', (string) session('message'));
        Queue::assertNotPushed(SyncGoogleAdsAccount::class);
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_a_manual_refresh_inside_the_throttle_window_is_throttled(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business, [
            'last_successful_sync_at' => now()->subHours(5),
            'manual_refresh_requested_at' => now()->subMinutes(10),
        ]);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'refresh'))->assertRedirect();

        $this->assertSame('info', session('status'));
        $this->assertStringContainsString('refreshed recently', (string) session('message'));
        Queue::assertNotPushed(SyncGoogleAdsAccount::class);
    }

    public function test_refresh_without_an_account_explains_and_queues_nothing(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'refresh'))->assertRedirect($this->adsUrl($workspace, $business, 'settings'));

        $this->assertSame('error', session('status'));
        Queue::assertNotPushed(SyncGoogleAdsAccount::class);
    }

    public function test_a_failed_sync_is_explained_on_the_settings_page_with_the_last_good_date(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business, ['last_sync_failure_code' => 'rate_limited', 'data_through_date' => '2026-10-02']);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Latest refresh failed: Google is rate limiting requests.', $html);
        $this->assertStringContainsString('Data through Oct 2, 2026', $html);
    }
}
