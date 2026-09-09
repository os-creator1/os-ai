<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConfigurationException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileCallBudget;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use App\Models\BusinessGoogleOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * CORRECTION PASS ITEMS 6 and 7 — the per-Business provider-call budget,
 * and failing safely when OAuth configuration is incomplete or mismatched.
 */
class GoogleBusinessProfileBudgetAndConfigTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
        config(['google_business_profile.mirror.retention_days' => 7]);
    }

    // -----------------------------------------------------------------
    // Item 6 — the per-Business provider-call budget
    // -----------------------------------------------------------------

    /**
     * The budget counts ACTUAL OUTBOUND REQUESTS, not operations. One
     * refresh makes three: a token exchange, a location read and a
     * VoiceOfMerchant read.
     */
    public function test_multiple_requests_inside_one_operation_are_all_counted(): void
    {
        [, $business] = $this->entitledTenant();
        $binding = $this->boundBinding($business);

        app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);

        $this->assertSame(3, app(GoogleBusinessProfileCallBudget::class)->usedThisHour((int) $business->id));

        // ...and they are attributed to the operation that caused them.
        $this->assertSame(
            3,
            (int) DB::table('business_google_operations')
                ->where('business_google_location_id', $binding->id)
                ->where('operation_type', 'mirror_refreshed')
                ->value('provider_call_count'),
        );
    }

    /** PAGINATION — each page is one reservation. */
    public function test_each_pagination_page_consumes_one_unit(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        // Three pages of accounts and three of locations.
        $this->fakeGoogle->pagesPerListCall = 3;

        $this->authenticateAsCustomer($customer);
        $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]))->assertOk();

        // 1 token exchange + 3 account pages + 3 location pages.
        $this->assertSame(7, app(GoogleBusinessProfileCallBudget::class)->usedThisHour((int) $business->id));
    }

    /** EXACT BOUNDARY — the last permitted request still goes through. */
    public function test_the_exact_boundary_is_permitted(): void
    {
        [, $business] = $this->entitledTenant();
        $binding = $this->boundBinding($business);

        // One refresh needs exactly three.
        config(['google_business_profile.sync.max_calls_per_business_per_hour' => 3]);

        app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);

        $this->assertSame(3, app(GoogleBusinessProfileCallBudget::class)->usedThisHour((int) $business->id));
        $this->assertNotNull($binding->fresh()->mirror_fetched_at);
    }

    /**
     * OVER BOUNDARY — the request that would exceed the budget makes ZERO
     * provider calls and is recorded as DEFERRED, not failed.
     */
    public function test_exceeding_the_budget_makes_zero_provider_calls_and_defers(): void
    {
        [, $business] = $this->entitledTenant();
        $binding = $this->boundBinding($business);

        // Two units: the token exchange and the location read fit, the
        // VoiceOfMerchant read does not.
        config(['google_business_profile.sync.max_calls_per_business_per_hour' => 2]);

        try {
            app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);
            $this->fail('The over-budget refresh should have thrown.');
        } catch (\App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException $exception) {
            $this->assertSame(BusinessGoogleOperation::FAILURE_BUDGET_EXHAUSTED, $exception->classification);
            $this->assertTrue($exception->isDeferrable(), 'A budget refusal is deferrable, not a failure.');
        }

        // Exactly the permitted number of calls happened — never more.
        $this->assertSame(2, app(GoogleBusinessProfileCallBudget::class)->usedThisHour((int) $business->id));

        $this->assertDatabaseHas('business_google_operations', [
            'business_google_location_id' => $binding->id,
            'status' => 'deferred',
            'failure_classification' => 'budget_exhausted',
        ]);

        // Contract §24.5 — a deferral leaves last_synced_at alone.
        $this->assertNull($binding->fresh()->last_synced_at);
    }

    /** A fully exhausted budget makes NO provider call at all. */
    public function test_an_exhausted_budget_makes_no_provider_call(): void
    {
        [, $business] = $this->entitledTenant();
        $binding = $this->boundBinding($business);

        config(['google_business_profile.sync.max_calls_per_business_per_hour' => 1]);

        // Burn the single unit on an unrelated recorded operation.
        BusinessGoogleOperation::create([
            'business_id' => $business->id,
            'operation_type' => 'mirror_refreshed',
            'local_operation_key' => 'seed:' . uniqid('', true),
            'status' => 'succeeded',
            'provider_call_count' => 1,
        ]);

        $callsBefore = count($this->fakeGoogle->calls);

        try {
            app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);
            $this->fail('The exhausted refresh should have thrown.');
        } catch (\App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException) {
            // expected
        }

        $this->assertSame($callsBefore, count($this->fakeGoogle->calls), 'Zero provider calls when exhausted.');
    }

    /** The manual path shows a safe, useful message and no raw provider data. */
    public function test_a_manual_refresh_over_budget_shows_a_safe_message(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->boundBinding($business);

        config(['google_business_profile.sync.max_calls_per_business_per_hour' => 1]);

        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('hourly limit', (string) session('message'));
        $this->assertStringNotContainsString('budget_exhausted', (string) session('message'));
    }

    /**
     * TWO CONCURRENT RESERVATIONS cannot both take the last unit. The
     * reservation is a locked, transactional read-modify-write, so the
     * total consumed never exceeds the budget.
     */
    public function test_two_reservations_cannot_both_take_the_last_unit(): void
    {
        [, $business] = $this->entitledTenant();
        $binding = $this->boundBinding($business);

        config(['google_business_profile.sync.max_calls_per_business_per_hour' => 1]);

        $budget = app(GoogleBusinessProfileCallBudget::class);
        $connection = $binding->connection;

        $operation = BusinessGoogleOperation::create([
            'business_id' => $business->id,
            'operation_type' => 'mirror_refreshed',
            'local_operation_key' => 'race:' . uniqid('', true),
            'status' => 'pending',
        ]);

        $granted = 0;
        $refused = 0;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $budget->withinOperation($connection, $operation, function () use ($budget) {
                    $budget->reserve();
                });
                $granted++;
            } catch (\App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException) {
                $refused++;
            }
        }

        $this->assertSame(1, $granted, 'Exactly one reservation may win the last unit.');
        $this->assertSame(1, $refused);
        $this->assertSame(1, $budget->usedThisHour((int) $business->id));
    }

    /** A provider call outside any operation context fails closed. */
    public function test_a_provider_call_outside_an_operation_context_fails_closed(): void
    {
        $this->expectException(\App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException::class);

        app(GoogleBusinessProfileCallBudget::class)->reserve();
    }

    /** Configuration is validated with the house idiom, never trusted raw. */
    public function test_the_budget_configuration_is_validated(): void
    {
        $budget = app(GoogleBusinessProfileCallBudget::class);

        foreach ([null, '', 'abc', 0, -5] as $invalid) {
            config(['google_business_profile.sync.max_calls_per_business_per_hour' => $invalid]);
            $this->assertSame(60, $budget->budgetPerHour(), var_export($invalid, true) . ' must fall back to the default.');
        }

        foreach ([1, 25, '120'] as $valid) {
            config(['google_business_profile.sync.max_calls_per_business_per_hour' => $valid]);
            $this->assertSame((int) $valid, $budget->budgetPerHour());
        }
    }

    // -----------------------------------------------------------------
    // Item 7 — incomplete or mismatched OAuth configuration
    // -----------------------------------------------------------------

    /**
     * Each missing value, and a mismatched redirect, refuses BEFORE any
     * database state change, any provider call, and without exposing a
     * credential.
     *
     * Security Remediation Slice 0 §16.A.4 (D-21) — this test predates the
     * operator/customer message split: it originally asserted the setting
     * fragment appeared in the CUSTOMER-facing session message, which is
     * exactly the leak D-21 fixes. The customer now always sees the same
     * plain, setting-free copy; the exact fragment this test used to
     * expect on the customer response is asserted against the operator
     * log instead, so this test still proves each specific fault is still
     * diagnosable.
     *
     * @dataProvider brokenConfigurations
     */
    public function test_incomplete_or_mismatched_configuration_fails_safely(string $key, mixed $value, string $expectedFragment): void
    {
        Log::spy();

        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        config(['services.google_business_profile.' . $key => $value]);

        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $this->assertSame('error', session('status'));
        $this->assertSame(
            "Google connections aren't available right now. This is something we need to fix on our side — we've been notified.",
            (string) session('message'),
        );
        $this->assertStringNotContainsString($expectedFragment, (string) session('message'));

        // NO state change, NO provider call.
        $this->assertDatabaseCount('business_google_connections', 0);
        $this->assertDatabaseCount('business_google_operations', 0);
        $this->assertSame([], $this->fakeGoogle->calls);

        // NO credential value is disclosed.
        $this->assertStringNotContainsString('test-client-secret', (string) session('message'));
        $this->assertStringNotContainsString('test-client-id', (string) session('message'));

        // The exact fault is still diagnosable — by the operator, in logs.
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => str_contains($context['operator_message'] ?? '', $expectedFragment),
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function brokenConfigurations(): array
    {
        return [
            'missing client id' => ['client_id', null, 'CLIENT_ID'],
            'blank client id' => ['client_id', '   ', 'CLIENT_ID'],
            'missing client secret' => ['client_secret', null, 'CLIENT_SECRET'],
            'missing redirect' => ['redirect', null, 'REDIRECT is not set'],
            'redirect points elsewhere' => ['redirect', 'https://evil.test/gbp/oauth/callback', 'does not match'],
            'redirect has the old tenant path' => ['redirect', 'https://example.test/workspaces/x/businesses/y/gbp/callback', 'does not match'],
            'redirect is not https' => ['redirect', 'http://production.test/gbp/oauth/callback', 'HTTPS'],
        ];
    }

    /** The validator names the exact reason, and localhost http is allowed. */
    public function test_the_configuration_validator_classifies_each_fault(): void
    {
        $config = app(GoogleBusinessProfileOAuthConfig::class);

        config(['services.google_business_profile.client_id' => null]);
        $this->assertSame(
            GoogleBusinessProfileConfigurationException::MISSING_CLIENT_ID,
            $this->reasonFor($config),
        );

        $this->configureValidOAuthCredentials();
        config(['services.google_business_profile.redirect' => 'https://elsewhere.test/gbp/oauth/callback']);
        $this->assertSame(
            GoogleBusinessProfileConfigurationException::REDIRECT_MISMATCH,
            $this->reasonFor($config),
        );

        // A valid configuration passes.
        $this->configureValidOAuthCredentials();
        $this->assertTrue($config->isUsable());

        // A query string on the redirect is a mismatch: Google matches the
        // registered URI exactly.
        config(['services.google_business_profile.redirect' => $config->expectedCallbackUrl() . '?x=1']);
        $this->assertFalse($config->isUsable());
    }

    // -----------------------------------------------------------------

    private function reasonFor(GoogleBusinessProfileOAuthConfig $config): string
    {
        try {
            $config->assertUsable();
        } catch (GoogleBusinessProfileConfigurationException $exception) {
            return $exception->reason;
        }

        return 'usable';
    }

    private function boundBinding($business): BusinessGoogleLocation
    {
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        return BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ])->fresh();
    }
}
