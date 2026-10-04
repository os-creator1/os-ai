<?php

namespace Tests\Feature\GoogleAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\GoogleAdsOAuthConfig;
use App\Library\GoogleAds\PhotoBoothFixture as P;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\GoogleAds\Http\Concerns\CreatesAdsHttpFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 — connect, the fixed OAuth callback, explicit account
 * selection and disconnect. The provider is the FAKE client throughout; the
 * recurring assertions are that no token or authorization code ever appears in
 * a response, a redirect, a flash or a log, and that nothing is ever selected
 * for the customer.
 */
class AdsConnectionFlowTest extends TestCase
{
    use CreatesAdsHttpFixtures;
    use RefreshDatabase;

    private const CODE = 'AUTH-CODE-SHOULD-NEVER-LEAK-9f8e7d';

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

    private function pendingState(Business $business, int $actorUserId, GoogleConnectionProduct $product = GoogleConnectionProduct::GoogleAds): string
    {
        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->where('product', $product->value)->first()
            ?? BusinessGoogleConnection::create([
                'business_id' => $business->id,
                'product' => $product,
                'state' => GoogleConnectionState::Pending,
                'connected_by_user_id' => $actorUserId,
            ]);

        return app(GoogleOAuthStateSigner::class)->issue($connection);
    }

    private function hitCallback(?string $state, array $extra = [])
    {
        return $this->get(route(GoogleAdsOAuthConfig::CALLBACK_ROUTE, array_filter(['state' => $state, 'code' => self::CODE] + $extra, fn ($v) => $v !== null)));
    }

    // ---------------------------------------------------------------
    // Connect
    // ---------------------------------------------------------------

    public function test_the_callback_route_is_fixed_and_tenant_free(): void
    {
        $url = route(GoogleAdsOAuthConfig::CALLBACK_ROUTE);

        $this->assertStringEndsWith('/ads/oauth/callback', $url);
        $this->assertSame($url, app(GoogleAdsOAuthConfig::class)->expectedCallbackUrl());
    }

    public function test_connect_creates_a_pending_connection_and_redirects_to_google(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $response = $this->post($this->adsUrl($workspace, $business, 'connect'));

        $this->assertStringStartsWith('https://accounts.google.test/o/oauth2/v2/auth', (string) $response->headers->get('Location'));

        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->where('product', 'google_ads')->firstOrFail();
        $this->assertSame(GoogleConnectionState::Pending, $connection->state);
        $this->assertSame((int) $customer->user_id, (int) $connection->connected_by_user_id);
        $this->assertNull($connection->refresh_token_encrypted);
        $this->assertTrue($this->fakeAds->callsTo('authorizationUrl')[0]['args']['force_consent']);
        $this->assertDatabaseHas('business_google_operations', ['business_id' => $business->id, 'operation_type' => 'connect_initiated']);
    }

    public function test_connecting_an_already_active_connection_is_refused_without_a_provider_call(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'connect'))->assertRedirect($this->adsUrl($workspace, $business));

        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('already connected', (string) session('message'));
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_a_misconfigured_provider_shows_a_friendly_message_and_never_the_setting_name(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);
        config(['services.google_ads.client_secret' => null]);

        $response = $this->post($this->adsUrl($workspace, $business, 'connect'));

        $response->assertRedirect($this->adsUrl($workspace, $business));
        $message = (string) session('message');
        $this->assertSame('Google Ads connection unavailable', session('message_title'));
        $this->assertStringNotContainsString('GOOGLE_ADS', $message);
        $this->assertStringNotContainsString('client_secret', $message);
        $this->assertDatabaseCount('business_google_connections', 0);
    }

    // ---------------------------------------------------------------
    // Callback
    // ---------------------------------------------------------------

    public function test_a_valid_state_connects_stores_the_token_and_redirects_to_account_selection(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);
        $state = $this->pendingState($business, (int) $customer->user_id);
        Log::spy();

        $response = $this->hitCallback($state);

        $response->assertRedirect($this->adsUrl($workspace, $business, 'accounts'));
        $this->assertSame('success', session('status'));

        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->where('product', 'google_ads')->firstOrFail();
        $this->assertSame(GoogleConnectionState::Active, $connection->state);
        $this->assertTrue($connection->hasStoredAuthorization());
        $this->assertSame(1, $this->fakeAds->callCount('exchangeAuthorizationCode'));

        // No code, token or state anywhere a browser or log can see it.
        $leaks = [self::CODE, 'fake-refresh-token', 'fake-access-token', $state];
        $surfaces = [(string) $response->headers->get('Location'), $response->getContent(), (string) session('message'), (string) session('status')];
        foreach ($leaks as $leak) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($leak, $surface);
            }
        }
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');

        $this->assertSame(0, GoogleAdsAccount::count(), 'connecting never selects an account');
    }

    public function test_a_core_business_can_complete_the_callback(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant(WorkspacePlanTier::Core);
        $this->asAdsUser($customer);

        $this->hitCallback($this->pendingState($business, (int) $customer->user_id))
            ->assertRedirect($this->adsUrl($workspace, $business, 'accounts'));
    }

    public function test_forged_expired_and_replayed_state_are_rejected_without_an_exchange(): void
    {
        [$customer, $business] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $this->hitCallback(null)->assertNotFound();
        $this->hitCallback('garbage.garbage')->assertNotFound();

        $state = $this->pendingState($business, (int) $customer->user_id);
        $tampered = substr($state, 0, -1) . (str_ends_with($state, 'a') ? 'b' : 'a');
        $this->hitCallback($tampered)->assertNotFound();

        $this->travel(11)->minutes();
        $this->hitCallback($state)->assertNotFound();
        $this->travelBack();

        $this->assertSame(0, $this->fakeAds->callCount('exchangeAuthorizationCode'));

        $fresh = $this->pendingState($business, (int) $customer->user_id);
        $this->hitCallback($fresh)->assertRedirect();
        $this->hitCallback($fresh)->assertNotFound();
        $this->assertSame(1, $this->fakeAds->callCount('exchangeAuthorizationCode'), 'a replayed nonce never exchanges again');
    }

    public function test_a_state_signed_for_another_product_is_rejected_before_any_tenant_data_is_touched(): void
    {
        [$customer, $business] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $gbpState = $this->pendingState($business, (int) $customer->user_id, GoogleConnectionProduct::BusinessProfile);

        $this->hitCallback($gbpState)->assertNotFound();

        $this->assertSame(0, $this->fakeAds->callCount('exchangeAuthorizationCode'));
        $this->assertDatabaseMissing('business_google_connections', ['business_id' => $business->id, 'product' => 'google_ads']);
    }

    public function test_the_callback_actor_must_be_the_one_who_started_the_attempt_and_a_foreign_business_is_not_found(): void
    {
        [$customer, $business] = $this->adsHttpTenant();
        $state = $this->pendingState($business, (int) $customer->user_id);

        [$stranger] = $this->adsHttpTenant();
        $this->asAdsUser($stranger);

        $this->hitCallback($state)->assertNotFound();
        $this->assertSame(0, $this->fakeAds->callCount('exchangeAuthorizationCode'));

        // The rightful actor's still-valid nonce was not burned by the stranger.
        $this->asAdsUser($customer);
        $this->hitCallback($state)->assertRedirect();
    }

    public function test_the_callback_needs_the_manage_permission_and_an_entitlement(): void
    {
        [$customer, $business] = $this->adsHttpTenant();
        $state = $this->pendingState($business, (int) $customer->user_id);

        $this->asAdsUser($customer, [self::VIEW]);
        $this->hitCallback($state)->assertNotFound();

        [$plainCustomer, $unentitled] = $this->unentitledAdsTenant();
        $unentitledState = $this->pendingState($unentitled, (int) $plainCustomer->user_id);
        $this->asAdsUser($plainCustomer);
        $this->hitCallback($unentitledState)->assertNotFound();

        $this->assertSame(0, $this->fakeAds->callCount('exchangeAuthorizationCode'));
    }

    public function test_a_denied_or_codeless_callback_connects_nothing_and_exchanges_nothing(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $this->hitCallback($this->pendingState($business, (int) $customer->user_id), ['error' => 'access_denied'])
            ->assertRedirect($this->adsUrl($workspace, $business));
        $this->assertSame('error', session('status'));

        $this->get(route(GoogleAdsOAuthConfig::CALLBACK_ROUTE, ['state' => $this->pendingState($business, (int) $customer->user_id)]))
            ->assertRedirect($this->adsUrl($workspace, $business));

        $this->assertSame(0, $this->fakeAds->callCount('exchangeAuthorizationCode'));
        $this->assertFalse(BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail()->isActive());
    }

    public function test_a_provider_failure_during_the_exchange_is_a_friendly_message_with_no_secret(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);
        $this->fakeAds->failNext('exchangeAuthorizationCode', GoogleAdsProviderException::providerUnavailable());

        $response = $this->hitCallback($this->pendingState($business, (int) $customer->user_id));

        $response->assertRedirect($this->adsUrl($workspace, $business));
        $this->assertSame('error', session('status'));
        $this->assertStringNotContainsString(self::CODE, (string) session('message') . (string) $response->headers->get('Location') . $response->getContent());
        $this->assertFalse(BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail()->isActive());
    }

    // ---------------------------------------------------------------
    // Account selection
    // ---------------------------------------------------------------

    public function test_the_account_page_lists_candidates_readably_and_never_selects_anything(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business, 'accounts'))->assertOk()->getContent();

        $this->assertStringContainsString('123-456-7890', $html);
        $this->assertStringContainsString('555-000-1111', $html);
        $this->assertStringContainsString('USD', $html);
        $this->assertStringContainsString('Manager account', $html);
        $this->assertStringContainsString('Test account', $html);
        $this->assertStringContainsString('name="customer_id" value="' . P::CUSTOMER_ID . '"', $html);
        $this->assertStringNotContainsString('name="customer_id" value="' . P::MANAGER_ID . '"', $html, 'managers have no choose control');
        $this->assertStringContainsString('Manager accounts cannot be chosen', $html);
        $this->assertSame(0, GoogleAdsAccount::count(), 'listing never selects');
        $this->assertSame(1, $this->fakeAds->callCount('listAccessibleCustomers'));
    }

    public function test_a_provider_failure_while_listing_is_a_friendly_state_with_a_retry_link(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);
        $this->fakeAds->failNext('listAccessibleCustomers', GoogleAdsProviderException::rateLimited());

        $response = $this->get($this->adsUrl($workspace, $business, 'accounts'));

        $response->assertOk()->assertSee('We could not load your Google Ads accounts')->assertSee('Try again');
        $this->assertStringNotContainsString('Exception', $response->getContent());
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
    }

    public function test_the_account_page_without_a_connection_redirects_with_a_message(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $this->get($this->adsUrl($workspace, $business, 'accounts'))->assertRedirect($this->adsUrl($workspace, $business));
        $this->assertSame('error', session('status'));
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_explicit_selection_creates_the_account_and_queues_the_first_sync(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $connection = $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'accounts.select'), ['customer_id' => P::CUSTOMER_ID])
            ->assertRedirect($this->adsUrl($workspace, $business));

        $account = GoogleAdsAccount::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(P::CUSTOMER_ID, $account->customer_id);
        $this->assertSame(P::MANAGER_ID, $account->login_customer_id);
        $this->assertSame('USD', $account->currency_code);
        $this->assertSame((int) $connection->id, (int) $account->business_google_connection_id);
        $this->assertSame((int) $customer->user_id, (int) $account->selected_by_user_id);

        $run = GoogleAdsSyncRun::query()->where('google_ads_account_id', $account->id)->firstOrFail();
        $this->assertSame('queued', $run->state->value);
        $this->assertSame('connect', $run->trigger->value);
        $this->assertSame(0, $this->fakeAds->callCount('campaigns'), 'the first sync is queued, never run inside the request');
    }

    public function test_selection_trusts_only_the_customer_id_and_derives_everything_else_server_side(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'accounts.select'), [
            'customer_id' => P::CUSTOMER_ID,
            'login_customer_id' => '0000000001',
            'currency_code' => 'EUR',
            'time_zone' => 'Pacific/Auckland',
            'business_id' => 999999,
            'monthly_budget_target_micros' => 1,
        ])->assertRedirect();

        $account = GoogleAdsAccount::query()->firstOrFail();
        $this->assertSame((int) $business->id, (int) $account->business_id);
        $this->assertSame(P::MANAGER_ID, $account->login_customer_id);
        $this->assertSame('USD', $account->currency_code);
        $this->assertSame('America/New_York', $account->time_zone);
        $this->assertNull($account->monthly_budget_target_micros);
    }

    public function test_a_customer_id_that_is_not_a_candidate_or_is_malformed_is_a_404_and_selects_nothing(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        foreach (['9999999999', 'abc', '12345'] as $foreign) {
            $this->post($this->adsUrl($workspace, $business, 'accounts.select'), ['customer_id' => $foreign])->assertNotFound();
        }

        $this->post($this->adsUrl($workspace, $business, 'accounts.select'), [])->assertSessionHasErrors('customer_id');

        $this->assertSame(0, GoogleAdsAccount::count());
    }

    public function test_a_manager_account_is_not_selectable(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'accounts.select'), ['customer_id' => P::MANAGER_ID])
            ->assertRedirect($this->adsUrl($workspace, $business, 'accounts'));

        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('manager account', (string) session('message'));
        $this->assertSame(0, GoogleAdsAccount::count());
    }

    public function test_one_business_cannot_select_for_or_through_another_businesss_connection(): void
    {
        [, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);

        [$otherCustomer, $otherBusiness] = $this->adsHttpTenant();
        $this->activeAdsConnection($otherBusiness);
        $this->asAdsUser($otherCustomer);

        // Addressing the first Business is a 404 for the second Business's owner.
        $this->post($this->adsUrl($workspace, $business, 'accounts.select'), ['customer_id' => P::CUSTOMER_ID])->assertNotFound();
        $this->assertSame(0, GoogleAdsAccount::query()->where('business_id', $business->id)->count());

        // And selecting in their own Business binds only their own connection.
        $otherWorkspace = \App\Models\Workspace::query()->findOrFail($otherBusiness->workspace_id);
        $this->post($this->adsUrl($otherWorkspace, $otherBusiness, 'accounts.select'), ['customer_id' => P::CUSTOMER_ID])->assertRedirect();

        $account = GoogleAdsAccount::query()->firstOrFail();
        $this->assertSame((int) $otherBusiness->id, (int) $account->business_id);
        $this->assertSame(
            (int) BusinessGoogleConnection::query()->where('business_id', $otherBusiness->id)->value('id'),
            (int) $account->business_google_connection_id,
        );
    }

    // ---------------------------------------------------------------
    // Disconnect
    // ---------------------------------------------------------------

    public function test_disconnect_destroys_the_token_keeps_history_and_shows_the_disconnected_state(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->seedOctoberData($account);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'disconnect'))->assertRedirect($this->adsUrl($workspace, $business));

        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(GoogleConnectionState::Disconnected, $connection->state);
        $this->assertFalse($connection->hasStoredAuthorization());
        $this->assertNull($connection->fresh()->refresh_token_encrypted);
        $this->assertSame(1, GoogleAdsAccount::count(), 'the account row and its history are retained');
        $this->assertDatabaseHas('google_ads_campaigns', ['business_id' => $business->id]);
        $this->assertSame(0, $this->fakeAds->callCount(), 'disconnect does not call Google');

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Connect Google Ads to see where your ad budget is generating results.', $html);
    }

    public function test_disconnecting_when_nothing_is_connected_is_harmless(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'disconnect'))->assertRedirect($this->adsUrl($workspace, $business));
        $this->assertDatabaseCount('business_google_connections', 0);
    }
}
