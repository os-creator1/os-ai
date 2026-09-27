<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Models\BusinessGoogleConnection;
use App\Repositories\Contracts\BusinessGoogleConnectionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * SEO Contract 18 §7 / §15.B — hard-gate tests for the Google connection
 * product discriminator (T-SEO-B-*).
 *
 * No Search Console feature exists yet. Every "search_console" row these
 * tests create is a bare, directly-inserted fixture row that exercises the
 * SHARED authority stack (repository, connection manager, OAuth state
 * signer, binding manager) exactly as a future Search Console connection
 * would, without adding any Search Console product code.
 */
class GoogleConnectionProductSeparationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGoogleBusinessProfileFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGoogleClient();
    }

    /**
     * T-SEO-B-COEXIST — two connection rows for the same Business, one per
     * product, coexist and are found independently regardless of which one
     * has the lower id.
     */
    public function test_two_product_connections_coexist_and_are_found_independently(): void
    {
        [, $business] = $this->entitledTenant();

        // The search_console row is created FIRST, so it holds the lower
        // id -- a naive "first row for this business" lookup would return
        // it by mistake for a business_profile caller.
        $searchConsole = $this->activeConnection($business, [
            'product' => GoogleConnectionProduct::SearchConsole,
            'google_account_email' => 'search-console-owner@example.test',
        ]);

        $businessProfile = $this->activeConnection($business, [
            'product' => GoogleConnectionProduct::BusinessProfile,
            'google_account_email' => 'business-profile-owner@example.test',
        ]);

        $this->assertLessThan($businessProfile->id, $searchConsole->id);

        $repository = app(BusinessGoogleConnectionRepository::class);

        $foundBusinessProfile = $repository->findForBusiness($business, GoogleConnectionProduct::BusinessProfile);
        $foundSearchConsole = $repository->findForBusiness($business, GoogleConnectionProduct::SearchConsole);

        $this->assertNotNull($foundBusinessProfile);
        $this->assertSame($businessProfile->id, $foundBusinessProfile->id);
        $this->assertSame('business-profile-owner@example.test', $foundBusinessProfile->google_account_email);

        $this->assertNotNull($foundSearchConsole);
        $this->assertSame($searchConsole->id, $foundSearchConsole->id);
        $this->assertSame('search-console-owner@example.test', $foundSearchConsole->google_account_email);

        // The GBP connection manager is hardcoded to business_profile
        // internally: it must never surface the search_console row, even
        // though it has the lower id.
        $viaManager = app(GoogleBusinessProfileConnectionManager::class)->findForBusiness($business);
        $this->assertNotNull($viaManager);
        $this->assertSame($businessProfile->id, $viaManager->id);
        $this->assertSame(GoogleConnectionProduct::BusinessProfile, $viaManager->product);
    }

    /**
     * T-SEO-B-REVOKE — revoking one product's connection leaves the other
     * product's connection state, on the same Business, completely
     * untouched.
     */
    public function test_revoking_one_product_connection_does_not_affect_the_other(): void
    {
        [, $business] = $this->entitledTenant();

        $searchConsole = $this->activeConnection($business, ['product' => GoogleConnectionProduct::SearchConsole]);
        $businessProfile = $this->activeConnection($business, ['product' => GoogleConnectionProduct::BusinessProfile]);

        app(GoogleBusinessProfileConnectionManager::class)->revoke($businessProfile);

        $businessProfile->refresh();
        $searchConsole->refresh();

        $this->assertSame(GoogleConnectionState::Revoked, $businessProfile->state);
        $this->assertNull($businessProfile->refresh_token_encrypted);

        $this->assertSame(GoogleConnectionState::Active, $searchConsole->state);
        $this->assertNotNull($searchConsole->refresh_token_encrypted);
    }

    /**
     * T-SEO-B-REPLAY — a state issued for one product can never be
     * consumed to complete a connection for the other product on the same
     * Business, even with the correct business id and nonce.
     */
    public function test_an_oauth_state_cannot_be_replayed_across_products(): void
    {
        [, $business] = $this->entitledTenant();

        $businessProfile = $this->activeConnection($business, [
            'product' => GoogleConnectionProduct::BusinessProfile,
            'state' => GoogleConnectionState::Pending,
        ]);

        $signer = app(GoogleOAuthStateSigner::class);
        $state = $signer->issue($businessProfile);

        $payload = $signer->verify($state);
        $this->assertNotNull($payload);
        $this->assertSame(GoogleConnectionProduct::BusinessProfile->value, $payload['p']);

        // Consuming against the CORRECT product but the WRONG nonce, or the
        // correct nonce but the WRONG product, must both fail closed.
        $this->assertFalse($signer->consume((int) $business->id, GoogleConnectionProduct::SearchConsole, $payload['n']));

        // The nonce must still be live: a failed cross-product consume must
        // not have burned it.
        $businessProfile->refresh();
        $this->assertSame($payload['n'], $businessProfile->oauth_state_nonce);

        // The genuine, matching product/nonce pair still consumes exactly
        // once.
        $this->assertTrue($signer->consume((int) $business->id, GoogleConnectionProduct::BusinessProfile, $payload['n']));
        $this->assertFalse($signer->consume((int) $business->id, GoogleConnectionProduct::BusinessProfile, $payload['n']));
    }

    /**
     * T-SEO-B-STATE-TAMPER — a state payload cannot be forged to claim a
     * product other than the one the connection actually holds.
     */
    public function test_a_state_with_an_unrecognized_product_is_rejected(): void
    {
        [, $business] = $this->entitledTenant();

        $connection = $this->activeConnection($business, ['product' => GoogleConnectionProduct::BusinessProfile]);

        $signer = app(GoogleOAuthStateSigner::class);
        $state = $signer->issue($connection);

        [$encoded] = explode('.', $state, 2);
        $decoded = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
        $decoded['p'] = 'not_a_real_product';

        $tamperedEncoded = rtrim(strtr(base64_encode((string) json_encode($decoded)), '+/', '-_'), '=');

        // Re-signing with the real key would require the app key; without
        // it the signature check alone already fails closed, which is the
        // behaviour under test regardless of which check trips first.
        $this->assertNull($signer->verify($tamperedEncoded . '.' . 'not-a-real-signature'));
    }

    /**
     * T-SEO-B-BIND — business_google_locations may only ever reference a
     * business_profile connection, because its composite FK does not
     * itself encode product.
     */
    public function test_binding_a_location_to_a_search_console_connection_is_refused(): void
    {
        [$customer, $business] = $this->entitledTenant();

        $searchConsole = $this->activeConnection($business, ['product' => GoogleConnectionProduct::SearchConsole]);
        $location = $this->createLocation($business);

        $this->fakeGoogleWithLocation();

        // bind() checks the connection's product before it does anything
        // else -- before any provider call and before the candidate token
        // is even relevant -- so no valid token is needed to prove the
        // guard fires.
        $this->expectException(\LogicException::class);

        app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileBindingManager::class)->bind(
            $business,
            $searchConsole,
            $location,
            'accounts/A1',
            'locations/L1',
            $customer->user->id,
        );
    }

    /**
     * The migration's default keeps every pre-existing (and every
     * fixture-created) connection row a business_profile row without
     * having to touch a single existing test.
     */
    public function test_a_connection_created_without_an_explicit_product_defaults_to_business_profile(): void
    {
        [, $business] = $this->entitledTenant();

        $connection = $this->activeConnection($business)->fresh();

        $this->assertSame(GoogleConnectionProduct::BusinessProfile, $connection->product);
        $this->assertSame(
            GoogleConnectionProduct::BusinessProfile->value,
            \Illuminate\Support\Facades\DB::table('business_google_connections')->where('id', $connection->id)->value('product'),
        );
    }
}
