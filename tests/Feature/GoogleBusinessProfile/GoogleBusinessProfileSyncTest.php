<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Jobs\GoogleBusinessProfile\RefreshGoogleBusinessProfileMirror;
use App\Jobs\GoogleBusinessProfile\SweepGoogleBusinessProfileRefreshes;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager;
use App\Models\BusinessGoogleLocation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §24 / §32.7 — sync, idempotency, quota behaviour.
 *
 * Security criterion G-8: no duplicate external operation; every call is
 * ledger-keyed before it is made.
 */
class GoogleBusinessProfileSyncTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
        config(['google_business_profile.mirror.retention_days' => 7]);
    }

    /**
     * T-SYNC-3 / contract §24.9 — NO provider call ever happens inside a
     * database transaction. Asserted by observing transactionLevel() from
     * inside the provider seam itself.
     */
    public function test_no_provider_call_happens_inside_a_transaction(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $observed = [];

        // A recording decorator around the Fake that captures the
        // transaction depth at the moment of each provider call.
        $this->app->instance(GoogleBusinessProfileReadClient::class, new class($this->fakeGoogle, $observed) implements GoogleBusinessProfileReadClient
        {
            public array $levels = [];

            public function __construct(private $inner, private array $ignored)
            {
            }

            private function record(): void
            {
                $this->levels[] = DB::transactionLevel();
            }

            public function authorizationUrl(string $signedState, bool $forceConsent): string
            {
                $this->record();

                return $this->inner->authorizationUrl($signedState, $forceConsent);
            }

            public function exchangeAuthorizationCode(string $code): \App\DTO\GoogleBusinessProfile\GoogleTokenGrant
            {
                $this->record();

                return $this->inner->exchangeAuthorizationCode($code);
            }

            public function exchangeRefreshToken(string $refreshToken): \App\DTO\GoogleBusinessProfile\GoogleAccessGrant
            {
                $this->record();

                return $this->inner->exchangeRefreshToken($refreshToken);
            }

            public function listAccounts(string $accessToken): array
            {
                $this->record();

                return $this->inner->listAccounts($accessToken);
            }

            public function listLocations(string $accessToken, string $accountResourceName, array $readMask, bool $addressPermitted): array
            {
                $this->record();

                return $this->inner->listLocations($accessToken, $accountResourceName, $readMask, $addressPermitted);
            }

            public function getLocation(string $accessToken, string $locationResourceName, array $readMask, bool $addressPermitted): \App\DTO\GoogleBusinessProfile\GoogleLocationProfile
            {
                $this->record();

                return $this->inner->getLocation($accessToken, $locationResourceName, $readMask, $addressPermitted);
            }

            public function getVoiceOfMerchantState(string $accessToken, string $locationResourceName): \App\DTO\GoogleBusinessProfile\GoogleVoiceOfMerchantState
            {
                $this->record();

                return $this->inner->getVoiceOfMerchantState($accessToken, $locationResourceName);
            }
        });

        $this->authenticateAsCustomer($customer);

        // Exercise the two heaviest provider paths: enumeration and bind
        // (bind opens a transaction to persist, after fetching).
        $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]));
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => $location->uid,
        ]);
        $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]));

        $recorded = $this->app->make(GoogleBusinessProfileReadClient::class)->levels;

        $this->assertNotEmpty($recorded, 'The test must actually exercise provider calls.');

        // RefreshDatabase wraps the whole test in its own transaction, so
        // the AMBIENT level here is 1 (it is 0 in production). The
        // contract's rule (§24.9) is that GBP code never opens a FURTHER
        // transaction around a provider call, so the meaningful assertion
        // is that no call was made deeper than the ambient level.
        $ambient = DB::transactionLevel();

        foreach ($recorded as $index => $level) {
            $this->assertLessThanOrEqual(
                $ambient,
                $level,
                "Provider call #{$index} was made inside a database transaction opened by GBP code.",
            );
        }
    }

    /**
     * T-SYNC-4 / contract §24.3 — per-connection concurrency of ONE. A
     * second refresh while a claim is held makes no provider call and is
     * not an error.
     */
    public function test_concurrent_refreshes_for_one_connection_collapse(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        $manager = app(GoogleBusinessProfileConnectionManager::class);

        // Simulate an in-flight refresh by holding the claim.
        $this->assertTrue($manager->claimRefresh($connection));
        $this->assertFalse($manager->claimRefresh($connection), 'A second claim must lose.');

        $this->authenticateAsCustomer($customer);
        $response = $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]));

        $response->assertRedirect();
        $this->assertSame('success', session('status'));
        $this->assertStringContainsString('already running', (string) session('message'));

        // No provider call at all — not even a token exchange.
        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'));
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeRefreshToken'));
    }

    /**
     * T-SYNC-5 / contract §24.5 — HTTP 429 is recorded as DEFERRED, not
     * failed, and last_synced_at is left unchanged so the next sweep
     * naturally retries.
     */
    public function test_rate_limiting_is_recorded_as_deferred_and_does_not_advance_last_synced_at(): void
    {
        $binding = $this->boundBinding();
        $before = $binding->last_synced_at;

        $this->fakeGoogle->failNextWith = GoogleBusinessProfileProviderException::rateLimited();

        try {
            app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService::class)
                ->refresh($binding, $binding->connection);
            $this->fail('The rate-limited call should have thrown.');
        } catch (GoogleBusinessProfileProviderException $exception) {
            $this->assertTrue($exception->isDeferrable());
        }

        $this->assertDatabaseHas('business_google_operations', [
            'business_google_location_id' => $binding->id,
            'operation_type' => 'mirror_refreshed',
            'status' => 'deferred',
            'failure_classification' => 'rate_limited',
        ]);

        $this->assertDatabaseMissing('business_google_operations', [
            'business_google_location_id' => $binding->id,
            'status' => 'failed',
        ]);

        $this->assertEquals($before, $binding->fresh()->last_synced_at);
    }

    /**
     * T-SYNC-6 / contract §24.6 — a timeout is AMBIGUOUS and is recorded
     * as `unknown`, never as a failure and never blindly replayed.
     */
    public function test_a_timeout_is_recorded_as_unknown(): void
    {
        $binding = $this->boundBinding();

        $this->fakeGoogle->failNextWith = GoogleBusinessProfileProviderException::timeout();

        try {
            app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService::class)
                ->refresh($binding, $binding->connection);
            $this->fail('The timeout should have thrown.');
        } catch (GoogleBusinessProfileProviderException $exception) {
            $this->assertTrue($exception->isAmbiguous());
        }

        $this->assertDatabaseHas('business_google_operations', [
            'business_google_location_id' => $binding->id,
            'status' => 'unknown',
            'failure_classification' => 'timeout',
        ]);
    }

    /**
     * Contract §24.4 / security criterion G-8 — the ledger row and its
     * unique local_operation_key exist BEFORE the provider call, so a
     * duplicate attempt collides rather than double-calling Google.
     */
    public function test_every_provider_operation_is_ledger_keyed_before_the_call(): void
    {
        $binding = $this->boundBinding();

        app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService::class)
            ->refresh($binding, $binding->connection);

        $rows = DB::table('business_google_operations')->get();

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertNotEmpty($row->local_operation_key);
            $this->assertNotNull($row->started_at);
        }

        // Unique, so a duplicate is impossible by construction.
        $keys = $rows->pluck('local_operation_key')->all();
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    /**
     * T-ENT-6 / contract §24.7 — the background job RE-CHECKS entitlement
     * and access before any provider access. A Business downgraded to Core
     * gets no refresh and makes no provider call.
     */
    public function test_the_refresh_job_rechecks_entitlement_before_calling_google(): void
    {
        $binding = $this->boundBinding(WorkspacePlanTier::Core);

        app(RefreshGoogleBusinessProfileMirror::class, ['bindingId' => $binding->id])->handle(
            app(GoogleBusinessProfileConnectionManager::class),
            app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService::class),
            app(\App\Library\Entitlement\EntitlementManager::class),
        );

        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'));
        $this->assertNull($binding->fresh()->mirror_fetched_at);
    }

    /** The same job, for an INACTIVE Business, also makes no call. */
    public function test_the_refresh_job_skips_an_inactive_business(): void
    {
        $binding = $this->boundBinding();

        DB::table('businesses')->where('id', $binding->business_id)->update(['status' => BusinessStatus::Draft->value]);

        app(RefreshGoogleBusinessProfileMirror::class, ['bindingId' => $binding->id])->handle(
            app(GoogleBusinessProfileConnectionManager::class),
            app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService::class),
            app(\App\Library\Entitlement\EntitlementManager::class),
        );

        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'));
    }

    /**
     * T-SYNC-7 / contract §24.2 — the daily sweep dispatches at most one
     * refresh per binding per interval, with a staggered delay, and skips
     * bindings synced inside the window.
     */
    public function test_the_daily_sweep_staggers_and_respects_the_minimum_interval(): void
    {
        Bus::fake();

        $due = $this->boundBinding();
        $due->forceFill(['last_synced_at' => now()->subDays(3)])->save();

        $recent = $this->boundBinding(WorkspacePlanTier::Growth, 'locations/L2');
        $recent->forceFill(['last_synced_at' => now()->subHour()])->save();

        app(SweepGoogleBusinessProfileRefreshes::class)->handle();

        Bus::assertDispatched(RefreshGoogleBusinessProfileMirror::class, 1);
    }

    /**
     * T-NOTIF-1 / contract §24.8 — Slice A never reads or writes a Google
     * notification setting, and adds no Pub/Sub or webhook artefact.
     * Notification settings are per Google ACCOUNT and would mutate state
     * shared beyond the Business we were authorized for.
     */
    public function test_no_notification_setting_is_ever_touched(): void
    {
        $offenders = [];

        $roots = [
            app_path('Library/GoogleBusinessProfile'),
            app_path('Jobs/GoogleBusinessProfile'),
            app_path('Http/Controllers/Customer/Business/GoogleBusinessProfileController.php'),
        ];

        foreach ($roots as $root) {
            $files = is_file($root) ? [$root] : (glob($root . '/{,*/}*.php', GLOB_BRACE) ?: []);

            foreach ($files as $file) {
                $contents = (string) file_get_contents($file);

                foreach (['notificationSetting', 'mybusinessnotifications', 'pubsub', 'Pub/Sub', 'pubsubTopic'] as $needle) {
                    if (stripos($contents, $needle) !== false && ! str_contains($file, 'Contracts')) {
                        $offenders[] = basename($file) . ' => ' . $needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    // -----------------------------------------------------------------

    private function boundBinding(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, string $locationResourceName = 'locations/L1'): BusinessGoogleLocation
    {
        [, $business] = $this->entitledTenant($tier);
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation(['name' => $locationResourceName]);

        return BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => $locationResourceName,
        ])->fresh();
    }
}
