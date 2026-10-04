<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\MetaAds\MetaConnectionState;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Library\MetaAds\Contracts\MetaAuthClient;
use App\Library\MetaAds\HttpMetaAuthClient;
use App\Library\MetaAds\MetaAdsOAuthConfig;
use App\Models\BusinessMetaConnection;
use App\Models\MetaAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 (contract 24 §3) — connect, the fixed OAuth callback,
 * re-authorisation and disconnect, against the FAKE provider. The recurring
 * assertions: the code, the state and every token never reach a response, a
 * redirect, a flash or a log; the nonce is consumed before the exchange; and
 * nothing is ever selected for the customer.
 */
class MetaAdsConnectionFlowTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;

    private const CODE = 'META-AUTH-CODE-SHOULD-NEVER-LEAK-9f8e7d';

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

    private function hitCallback(?string $state, array $extra = [])
    {
        return $this->get(route(MetaAdsOAuthConfig::CALLBACK_ROUTE, array_filter(['state' => $state, 'code' => self::CODE] + $extra, fn ($v) => $v !== null)));
    }

    private function connection(int $businessId): BusinessMetaConnection
    {
        return BusinessMetaConnection::query()->where('business_id', $businessId)->firstOrFail();
    }

    // ---------------------------------------------------------------
    // Connect
    // ---------------------------------------------------------------

    public function test_the_callback_route_is_fixed_and_tenant_free(): void
    {
        $url = route(MetaAdsOAuthConfig::CALLBACK_ROUTE);

        $this->assertStringEndsWith('/ads/meta/oauth/callback', $url);
        $this->assertSame($url, app(MetaAdsOAuthConfig::class)->expectedCallbackUrl());
        $this->assertSame('customer.ads.meta.oauth.callback', MetaAdsOAuthConfig::CALLBACK_ROUTE);
    }

    public function test_connect_redirects_to_the_v26_dialog_with_only_the_two_ads_scopes_and_a_signed_state(): void
    {
        // The real client builds the URL without any network call.
        config(['meta_ads.oauth_dialog_base_url' => 'https://www.facebook.com', 'meta_ads.api_version' => 'v26.0']);
        // The real client refuses a non-HTTPS redirect: serve the test app over https.
        \Illuminate\Support\Facades\URL::forceScheme('https');
        config(['services.meta_ads.redirect' => url(MetaAdsOAuthConfig::CALLBACK_PATH)]);
        $this->app->instance(MetaAuthClient::class, app(HttpMetaAuthClient::class));
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        $response = $this->post($this->metaPage($workspace, $business, 'connect'));

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/', $location, 'redirected to Meta, not back with: ' . (string) session('message'));
        $parts = parse_url($location);
        parse_str((string) ($parts['query'] ?? ''), $query);

        $this->assertSame('www.facebook.com', $parts['host']);
        $this->assertSame('/v26.0/dialog/oauth', $parts['path']);
        $this->assertSame('ads_read,ads_management', $query['scope']);
        $this->assertStringNotContainsString('business_management', $location);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('test-meta-app-id', $query['client_id']);
        $this->assertSame(route(MetaAdsOAuthConfig::CALLBACK_ROUTE), $query['redirect_uri']);
        $this->assertArrayNotHasKey('client_secret', $query);

        $payload = app(\App\Library\MetaAds\MetaOAuthStateSigner::class)->verify($query['state']);
        $this->assertNotNull($payload, 'the state is a valid signed state');
        $this->assertSame((int) $business->id, $payload['b']);
        $this->assertSame((int) $customer->user_id, $payload['u']);
        $this->assertSame('meta_ads', $payload['p']);

        $connection = $this->connection($business->id);
        $this->assertSame(MetaConnectionState::Pending, $connection->state);
        $this->assertSame((int) $customer->user_id, (int) $connection->connected_by_user_id);
        $this->assertNull($connection->access_token_encrypted);
        $this->assertDatabaseHas('business_meta_operations', ['business_id' => $business->id, 'operation_type' => 'connect_initiated']);
        $this->assertSame(0, $this->fakeMeta->callCount(), 'starting a connection makes no provider call');
    }

    public function test_connecting_an_active_connection_that_is_not_due_is_refused_without_a_provider_call(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'connect'))->assertRedirect($this->metaPage($workspace, $business));

        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('already connected', (string) session('message'));
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_re_authorisation_is_offered_when_the_token_is_due_when_expired_and_when_read_only(): void
    {
        foreach ([
            'due soon' => ['token_expires_at' => now()->addDays(3)],
            'read only' => ['granted_scopes' => 'ads_read'],
            'expired' => ['state' => MetaConnectionState::Expired, 'access_token_encrypted' => null, 'token_expires_at' => now()->subDay()],
        ] as $label => $overrides) {
            [$customer, $business, $workspace] = $this->metaHttpTenant(name: 'Reauth ' . $label);
            $this->activeMetaConnection($business, $overrides);
            $this->asMetaUser($customer);

            $response = $this->post($this->metaPage($workspace, $business, 'connect'));

            $this->assertStringStartsWith('https://www.facebook.test/', (string) $response->headers->get('Location'), "[{$label}] starts a re-authorisation");
        }
    }

    public function test_a_misconfigured_provider_shows_a_friendly_message_and_never_the_setting_name(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);
        config(['services.meta_ads.app_secret' => null]);

        $response = $this->post($this->metaPage($workspace, $business, 'connect'));

        $response->assertRedirect($this->metaPage($workspace, $business));
        $message = (string) session('message');
        $this->assertSame('Meta connection unavailable', session('message_title'));
        $this->assertStringNotContainsString('META_ADS', $message);
        $this->assertStringNotContainsString('app_secret', $message);
        $this->assertDatabaseCount('business_meta_connections', 0);
    }

    // ---------------------------------------------------------------
    // Callback
    // ---------------------------------------------------------------

    public function test_a_valid_state_connects_stores_the_token_and_redirects_to_account_selection_without_selecting(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);
        $state = $this->metaPendingState($business, (int) $customer->user_id);
        Log::spy();

        $response = $this->hitCallback($state);

        $response->assertRedirect($this->metaPage($workspace, $business, 'accounts'));
        $this->assertSame('success', session('status'));

        $connection = $this->connection($business->id);
        $this->assertSame(MetaConnectionState::Active, $connection->state);
        $this->assertTrue($connection->hasStoredAuthorization());
        $this->assertSame(1, $this->fakeMeta->callCount('exchangeCode'));

        // No code, token or state anywhere a browser or log can see it.
        $leaks = [self::CODE, 'fake-long-lived-token', $state];
        $surfaces = [(string) $response->headers->get('Location'), $response->getContent(), (string) session('message'), (string) session('status')];
        foreach ($leaks as $leak) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($leak, $surface);
            }
        }
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');

        $this->assertSame(0, MetaAdsAccount::count(), 'connecting never selects an account');
        $this->assertSame(0, \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\MetaAds\SyncMetaAdsAccount::class)->count(), 'no sync before an account is chosen');

        // The following pages never show a token either.
        $accounts = $this->get($this->metaPage($workspace, $business, 'accounts'))->assertOk()->getContent();
        $settings = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();
        foreach ([$accounts, $settings] as $html) {
            $this->assertStringNotContainsString('fake-long-lived-token', $html);
            $this->assertStringNotContainsString('access_token', $html);
        }
    }

    public function test_a_core_business_can_complete_the_callback(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant(WorkspacePlanTier::Core);
        $this->asMetaUser($customer);

        $this->hitCallback($this->metaPendingState($business, (int) $customer->user_id))
            ->assertRedirect($this->metaPage($workspace, $business, 'accounts'));
    }

    public function test_forged_expired_and_replayed_state_are_rejected_without_an_exchange(): void
    {
        [$customer, $business] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        $this->hitCallback(null)->assertNotFound();
        $this->hitCallback('garbage.garbage')->assertNotFound();

        $state = $this->metaPendingState($business, (int) $customer->user_id);
        $tampered = substr($state, 0, -1) . (str_ends_with($state, 'a') ? 'b' : 'a');
        $this->hitCallback($tampered)->assertNotFound();

        $this->travel(11)->minutes();
        $this->hitCallback($state)->assertNotFound();
        $this->travelBack();
        $this->pinMetaClock();

        $this->assertSame(0, $this->fakeMeta->callCount('exchangeCode'));

        $fresh = $this->metaPendingState($business, (int) $customer->user_id);
        $this->hitCallback($fresh)->assertRedirect();
        $this->hitCallback($fresh)->assertNotFound();
        $this->assertSame(1, $this->fakeMeta->callCount('exchangeCode'), 'a replayed nonce never exchanges again');
    }

    public function test_a_state_signed_for_google_is_rejected_before_any_tenant_data_is_touched(): void
    {
        [$customer, $business] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        $googleConnection = $this->activeAdsConnection($business);
        $googleState = app(GoogleOAuthStateSigner::class)->issue($googleConnection);

        $this->hitCallback($googleState)->assertNotFound();

        $this->assertSame(0, $this->fakeMeta->callCount('exchangeCode'));
        $this->assertDatabaseMissing('business_meta_connections', ['business_id' => $business->id]);
    }

    public function test_the_callback_actor_must_be_the_initiator_and_a_foreign_business_is_not_found(): void
    {
        [$customer, $business] = $this->metaHttpTenant();
        $state = $this->metaPendingState($business, (int) $customer->user_id);

        [$stranger] = $this->metaHttpTenant(name: 'Stranger Co');
        $this->asMetaUser($stranger);

        $this->hitCallback($state)->assertNotFound();
        $this->assertSame(0, $this->fakeMeta->callCount('exchangeCode'));

        // A state a stranger initiated against someone else's Business is still a 404:
        // the whole tenancy chain re-runs from the signed state.
        $foreignState = $this->metaPendingState($business, (int) $stranger->user_id);
        $this->hitCallback($foreignState)->assertNotFound();
        $this->assertSame(0, $this->fakeMeta->callCount('exchangeCode'));

        // The rightful actor's still-valid nonce was not burned by the stranger... but the second
        // issue replaced it (a new attempt kills the older one), so start a fresh attempt.
        $this->asMetaUser($customer);
        $this->hitCallback($state)->assertNotFound();
        $fresh = $this->metaPendingState($business, (int) $customer->user_id);
        $this->hitCallback($fresh)->assertRedirect();
    }

    public function test_the_callback_needs_the_manage_permission_and_an_entitlement(): void
    {
        [$customer, $business] = $this->metaHttpTenant();
        $state = $this->metaPendingState($business, (int) $customer->user_id);

        $this->asMetaUser($customer, [self::META_VIEW]);
        $this->hitCallback($state)->assertNotFound();

        [$plainCustomer, $unentitled] = $this->unentitledMetaTenant();
        $unentitledState = $this->metaPendingState($unentitled, (int) $plainCustomer->user_id);
        $this->asMetaUser($plainCustomer);
        $this->hitCallback($unentitledState)->assertNotFound();

        $this->assertSame(0, $this->fakeMeta->callCount('exchangeCode'));
    }

    public function test_a_denied_callback_shows_a_calm_message_connects_nothing_and_exchanges_nothing(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        $this->get(route(MetaAdsOAuthConfig::CALLBACK_ROUTE, [
            'state' => $this->metaPendingState($business, (int) $customer->user_id),
            'error' => 'access_denied',
            'error_reason' => 'user_denied',
            'error_description' => 'Permissions error.',
        ]))->assertRedirect($this->metaPage($workspace, $business));

        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('Meta access was not granted', (string) session('message'));
        $this->assertStringNotContainsString('Permissions error', (string) session('message'));

        // A codeless callback is the same calm outcome.
        $this->get(route(MetaAdsOAuthConfig::CALLBACK_ROUTE, ['state' => $this->metaPendingState($business, (int) $customer->user_id)]))
            ->assertRedirect($this->metaPage($workspace, $business));
        $this->assertStringContainsString('Meta access was not granted', (string) session('message'));

        $this->assertSame(0, $this->fakeMeta->callCount('exchangeCode'));
        $this->assertFalse($this->connection($business->id)->isActive());
    }

    public function test_the_nonce_is_consumed_before_the_exchange_so_a_failed_exchange_cannot_be_replayed(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);
        $this->fakeMeta->failNext('exchangeCode', MetaProviderException::providerUnavailable());
        $state = $this->metaPendingState($business, (int) $customer->user_id);

        $response = $this->hitCallback($state);

        $response->assertRedirect($this->metaPage($workspace, $business));
        $this->assertSame('error', session('status'));
        $this->assertStringNotContainsString(self::CODE, (string) session('message') . (string) $response->headers->get('Location') . $response->getContent());
        $this->assertNull($this->connection($business->id)->oauth_state_nonce, 'consumed even though the exchange failed');
        $this->assertFalse($this->connection($business->id)->isActive());

        // The same state is now dead.
        $this->hitCallback($state)->assertNotFound();
        $this->assertSame(1, $this->fakeMeta->callCount('exchangeCode'));
    }

    public function test_a_grant_without_ads_read_connects_nothing(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);
        $this->fakeMeta->grantNoAdsRead();

        $this->hitCallback($this->metaPendingState($business, (int) $customer->user_id))
            ->assertRedirect($this->metaPage($workspace, $business));

        $this->assertSame('error', session('status'));
        $this->assertFalse($this->connection($business->id)->isActive());
        $this->assertNull($this->connection($business->id)->access_token_encrypted);
    }

    public function test_the_callback_rate_limit_is_in_place(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('customer.ads.meta.oauth.callback');

        $this->assertContains('throttle:20,1', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    // ---------------------------------------------------------------
    // Disconnect
    // ---------------------------------------------------------------

    public function test_disconnect_destroys_the_token_keeps_history_and_shows_the_connect_state(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->seedMetaOctober($account);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'disconnect'))->assertRedirect($this->metaPage($workspace, $business));

        $connection = $this->connection($business->id);
        $this->assertSame(MetaConnectionState::Disconnected, $connection->state);
        $this->assertFalse($connection->hasStoredAuthorization());
        $this->assertNull($account->fresh()->selected_at, 'the account is unselected');
        $this->assertSame(1, MetaAdsAccount::count(), 'the account row and its history are retained');
        $this->assertDatabaseHas('meta_ads_campaigns', ['business_id' => $business->id]);
        $this->assertSame(0, $this->fakeMeta->callCount(), 'disconnect does not call Meta');

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Connect Meta to see where your Facebook and Instagram ad budget is turning into results.', $html);
    }

    public function test_disconnecting_when_nothing_is_connected_is_harmless(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'disconnect'))->assertRedirect($this->metaPage($workspace, $business));
        $this->assertDatabaseCount('business_meta_connections', 0);
    }
}
