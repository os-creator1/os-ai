<?php

namespace Tests\Feature\MetaAds\Http;

use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\MetaPhotoBoothFixture as P;
use App\Models\BusinessMetaConnection;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 (contract 24 §4) — explicit, server-derived account
 * selection over HTTP: candidates are derived from the provider on every render
 * and again on the POST, only the account id is trusted from the request,
 * nothing is ever auto-selected (not even a single candidate), non-active
 * accounts are shown but refused, and another Business's account id is a 404.
 */
class MetaAdsAccountSelectionHttpTest extends TestCase
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

    public function test_the_page_lists_candidates_with_status_and_never_selects_anything(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'accounts'))->assertOk()->getContent();

        $this->assertStringContainsString('Photo Booth Co - Ads', $html);
        $this->assertStringContainsString('Photo Booth Co - Europe', $html);
        $this->assertStringContainsString('EUR', $html);
        $this->assertStringContainsString('Photo Booth Co - Old Account', $html);
        $this->assertStringContainsString('Disabled', $html, 'a non-active account shows its status');
        $this->assertStringContainsString('name="account_id" value="' . P::AD_ACCOUNT_ID . '"', $html);
        $this->assertStringContainsString('name="account_id" value="' . P::EUR_AD_ACCOUNT_ID . '"', $html);
        $this->assertStringNotContainsString('name="account_id" value="' . P::DISABLED_AD_ACCOUNT_ID . '"', $html, 'a disabled account has no choose control');
        $this->assertStringContainsString('This account is not active in Meta, so it cannot be chosen.', $html);
        $this->assertSame(0, MetaAdsAccount::count(), 'listing never selects');
        $this->assertSame(1, $this->fakeMeta->callCount('listAdAccounts'));
    }

    public function test_even_a_single_candidate_is_never_preselected(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);
        $this->fakeMeta->withAdAccounts([new \App\DTO\MetaAds\MetaAdsAccountCandidate(P::AD_ACCOUNT_ID, 'Only Account', 'USD', 'America/New_York', 1)]);

        $html = $this->get($this->metaPage($workspace, $business, 'accounts'))->assertOk()->getContent();

        $this->assertStringContainsString('Only Account', $html);
        $this->assertStringContainsString('Use this account', $html, 'an explicit button, never a silent choice');
        $this->assertStringNotContainsString('Currently selected', $html);
        $this->assertSame(0, MetaAdsAccount::count());
    }

    public function test_a_provider_failure_while_listing_is_a_friendly_state_with_a_retry_link(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);
        $this->fakeMeta->failNext('listAdAccounts', MetaProviderException::rateLimited());

        $response = $this->get($this->metaPage($workspace, $business, 'accounts'));

        $response->assertOk()->assertSee('We could not load your Meta ad accounts')->assertSee('Try again');
        $this->assertStringNotContainsString('Exception', $response->getContent());
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
    }

    public function test_the_account_page_without_an_active_connection_redirects_with_a_message(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        $this->get($this->metaPage($workspace, $business, 'accounts'))->assertRedirect($this->metaPage($workspace, $business));
        $this->assertSame('error', session('status'));
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_explicit_selection_creates_the_account_and_queues_the_first_sync(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $connection = $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'accounts.select'), ['account_id' => P::AD_ACCOUNT_ID])
            ->assertRedirect($this->metaPage($workspace, $business));

        $account = MetaAdsAccount::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(P::AD_ACCOUNT_ID, $account->ad_account_id);
        $this->assertSame('USD', $account->currency_code);
        $this->assertSame((int) $connection->id, (int) $account->business_meta_connection_id);
        $this->assertSame((int) $customer->user_id, (int) $account->selected_by_user_id);
        $this->assertNull($account->result_action_type, 'results stay unavailable until the owner chooses a type');

        $run = MetaAdsSyncRun::query()->where('meta_ads_account_id', $account->id)->firstOrFail();
        $this->assertSame('queued', $run->state->value);
        $this->assertSame('connect', $run->trigger->value);
        $this->assertSame(0, $this->fakeMeta->callCount('campaigns'), 'the first sync is queued, never run inside the request');
    }

    public function test_the_act_prefix_is_tolerated_and_selection_trusts_only_the_account_id(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'accounts.select'), [
            'account_id' => 'act_' . P::AD_ACCOUNT_ID,
            'currency_code' => 'JPY',
            'time_zone' => 'Pacific/Auckland',
            'business_id' => 999999,
            'monthly_budget_target_micros' => 1,
            'result_action_type' => 'link_click',
        ])->assertRedirect();

        $account = MetaAdsAccount::query()->firstOrFail();
        $this->assertSame((int) $business->id, (int) $account->business_id);
        $this->assertSame(P::AD_ACCOUNT_ID, $account->ad_account_id);
        $this->assertSame('USD', $account->currency_code);
        $this->assertSame('America/New_York', $account->time_zone);
        $this->assertNull($account->monthly_budget_target_micros);
        $this->assertNull($account->result_action_type);
    }

    public function test_an_account_that_is_not_a_candidate_or_is_malformed_is_a_404_and_selects_nothing(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        foreach ([P::FOREIGN_AD_ACCOUNT_ID, 'abc', 'act_', '12 34'] as $foreign) {
            $this->post($this->metaPage($workspace, $business, 'accounts.select'), ['account_id' => $foreign])->assertNotFound();
        }

        $this->post($this->metaPage($workspace, $business, 'accounts.select'), [])->assertSessionHasErrors('account_id');

        $this->assertSame(0, MetaAdsAccount::count());
    }

    public function test_a_disabled_account_is_refused_with_a_friendly_message(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'accounts.select'), ['account_id' => P::DISABLED_AD_ACCOUNT_ID])
            ->assertRedirect($this->metaPage($workspace, $business, 'accounts'));

        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('not active in Meta', (string) session('message'));
        $this->assertSame(0, MetaAdsAccount::count());
    }

    public function test_one_business_cannot_select_for_or_through_another_businesss_connection(): void
    {
        [, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);

        [$otherCustomer, $otherBusiness, $otherWorkspace] = $this->metaHttpTenant(name: 'Other Co');
        $this->activeMetaConnection($otherBusiness);
        $this->asMetaUser($otherCustomer);

        // Addressing the first Business is a 404 for the second Business's owner.
        $this->post($this->metaPage($workspace, $business, 'accounts.select'), ['account_id' => P::AD_ACCOUNT_ID])->assertNotFound();
        $this->assertSame(0, MetaAdsAccount::query()->where('business_id', $business->id)->count());

        // Selecting in their own Business binds only their own connection.
        $this->post($this->metaPage($otherWorkspace, $otherBusiness, 'accounts.select'), ['account_id' => P::AD_ACCOUNT_ID])->assertRedirect();

        $account = MetaAdsAccount::query()->firstOrFail();
        $this->assertSame((int) $otherBusiness->id, (int) $account->business_id);
        $this->assertSame(
            (int) BusinessMetaConnection::query()->where('business_id', $otherBusiness->id)->value('id'),
            (int) $account->business_meta_connection_id,
        );
    }

    public function test_a_provider_failure_while_selecting_is_a_friendly_message_and_selects_nothing(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);
        $this->fakeMeta->failNext('listAdAccounts', MetaProviderException::providerUnavailable());

        $this->post($this->metaPage($workspace, $business, 'accounts.select'), ['account_id' => P::AD_ACCOUNT_ID])
            ->assertRedirect($this->metaPage($workspace, $business, 'accounts'));

        $this->assertSame('error', session('status'));
        $this->assertSame(0, MetaAdsAccount::count());
    }

    public function test_selecting_while_an_update_is_running_redirects_back_with_a_friendly_message(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        MetaAdsSyncRun::create(['business_id' => $business->id, 'meta_ads_account_id' => $account->id, 'state' => 'running', 'trigger' => 'manual', 'started_at' => now()]);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'accounts.select'), ['account_id' => P::EUR_AD_ACCOUNT_ID])
            ->assertRedirect($this->metaPage($workspace, $business, 'accounts'));

        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('An update is running; try again in a minute.', (string) session('message'));
        $this->assertSame(P::AD_ACCOUNT_ID, $account->fresh()->ad_account_id);
    }

    public function test_changing_the_account_clears_the_targets_and_result_type_and_the_page_warns_about_it(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['monthly_budget_target_micros' => 250_000_000, 'target_cost_per_result_micros' => 20_000_000]);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'accounts'))->assertOk()->getContent();
        $this->assertStringContainsString('Choosing a different account replaces the figures', $html);
        $this->assertStringContainsString('Currently selected', $html);

        $this->post($this->metaPage($workspace, $business, 'accounts.select'), ['account_id' => P::EUR_AD_ACCOUNT_ID])->assertRedirect();

        $fresh = $account->fresh();
        $this->assertSame('EUR', $fresh->currency_code);
        $this->assertNull($fresh->monthly_budget_target_micros);
        $this->assertNull($fresh->target_cost_per_result_micros);
        $this->assertNull($fresh->result_action_type);
    }
}
