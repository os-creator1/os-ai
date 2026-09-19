<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileStatusReader;
use App\Models\BusinessGoogleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Contract 18 §9.2 — GoogleBusinessProfileStatusReader is the read-only,
 * Business-scoped query service GBP §37.2 promised SEO. It is gated, ACL-
 * filtered BEFORE reading, treats an expired mirror as absent, makes no
 * provider call, writes nothing, and costs a constant number of queries.
 */
class GoogleBusinessProfileStatusReaderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    private function reader(): GoogleBusinessProfileStatusReader
    {
        return app(GoogleBusinessProfileStatusReader::class);
    }

    /** Fails the test the moment anything resolves the real/fake Google client. */
    private function forbidProviderClient(): void
    {
        $this->app->bind(GoogleBusinessProfileReadClient::class, function () {
            throw new RuntimeException('The status reader must never resolve a Google provider client.');
        });
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_it_is_null_without_the_view_google_business_profile_capability(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business);
        $this->authenticateAsSeoCustomer($customer, ['view_seo', 'manage_seo']);

        $this->assertNull($this->reader()->forBusiness($workspace, $business, $customer->user));
    }

    public function test_it_is_null_for_a_business_that_is_not_entitled_to_gbp(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->createLocation($business);
        $this->authenticateAsSeoCustomer($customer);

        $this->assertNull(
            $this->reader()->forBusiness($workspace, $business, $customer->user),
            'Core is excluded from GBP; the reader must answer null, never an empty list that confirms the module exists.',
        );
    }

    public function test_it_is_null_when_the_workspace_and_business_do_not_belong_together(): void
    {
        [$customer, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        [, , $foreignWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($customer);

        $this->assertNull($this->reader()->forBusiness($foreignWorkspace, $business, $customer->user));
    }

    public function test_it_reports_one_status_per_accessible_location(): void
    {
        [$customer, $business, $workspace, $bound] = $this->growthTenantWithLocation();
        $unbound = $this->extraLocation($business, 'Unbound Site');
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $bound, $connection, true, [], GoogleLocationHealth::Verified);
        $this->authenticateAsSeoCustomer($customer);

        $statuses = $this->reader()->forBusiness($workspace, $business, $customer->user);

        $this->assertCount(2, $statuses);
        $byId = collect($statuses)->keyBy('locationId');

        $this->assertTrue($byId[$bound->id]->bound);
        $this->assertSame('active', $byId[$bound->id]->connectionState);
        $this->assertSame(GoogleLocationHealth::Verified, $byId[$bound->id]->health);
        $this->assertSame($bound->uid, $byId[$bound->id]->locationUid);
        $this->assertSame($bound->name, $byId[$bound->id]->locationName);

        $this->assertFalse($byId[$unbound->id]->bound);
        $this->assertNull($byId[$unbound->id]->health);
        $this->assertFalse($byId[$unbound->id]->mirrorIsFresh);
        $this->assertNull($byId[$unbound->id]->newReviewUri);
        $this->assertNull($byId[$unbound->id]->napMismatchCount);
    }

    public function test_a_fresh_mirror_supplies_the_review_link_and_a_mismatch_count(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $location, $connection, true, ['title' => 'A Completely Different Name']);
        $this->authenticateAsSeoCustomer($customer);

        [$status] = $this->reader()->forBusiness($workspace, $business, $customer->user);

        $this->assertTrue($status->mirrorIsFresh);
        $this->assertSame('https://search.google.test/review', $status->newReviewUri);
        $this->assertSame(1, $status->napMismatchCount, 'Only the business name differs; phone and website match.');
    }

    public function test_a_matching_fresh_mirror_reports_zero_mismatches(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $location, $connection, true, ['title' => $business->name]);
        $this->authenticateAsSeoCustomer($customer);

        [$status] = $this->reader()->forBusiness($workspace, $business, $customer->user);

        $this->assertSame(0, $status->napMismatchCount);
    }

    public function test_an_expired_mirror_is_absent_but_operational_state_is_not(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $location, $connection, false, ['title' => 'Stale Title'], GoogleLocationHealth::Suspended);
        $this->authenticateAsSeoCustomer($customer);

        [$status] = $this->reader()->forBusiness($workspace, $business, $customer->user);

        $this->assertFalse($status->mirrorIsFresh);
        $this->assertNull($status->newReviewUri, 'An expired mirror must never surface its review link.');
        $this->assertNull($status->napMismatchCount, 'An expired mirror must never be compared.');
        $this->assertSame(GoogleLocationHealth::Suspended, $status->health, 'Health is operational binding metadata that survives the purge (GBP §13.7).');
        $this->assertTrue($status->bound);
    }

    public function test_a_purged_mirror_is_absent_exactly_like_an_expired_one(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $binding = $this->bindGoogleLocation($business, $location, $connection, true);
        BusinessGoogleLocation::query()->whereKey($binding->id)->update(['profile_mirror' => null, 'mirror_fetched_at' => null, 'mirror_expires_at' => null]);
        $this->authenticateAsSeoCustomer($customer);

        [$status] = $this->reader()->forBusiness($workspace, $business, $customer->user);

        $this->assertFalse($status->mirrorIsFresh);
        $this->assertNull($status->newReviewUri);
        $this->assertNull($status->napMismatchCount);
    }

    public function test_a_mirror_that_expires_between_reads_disappears_on_the_next_read(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $binding = $this->bindGoogleLocation($business, $location, $connection, true);
        $this->authenticateAsSeoCustomer($customer);

        [$before] = $this->reader()->forBusiness($workspace, $business, $customer->user);
        $this->assertNotNull($before->newReviewUri);

        BusinessGoogleLocation::query()->whereKey($binding->id)->update(['mirror_expires_at' => now()->subSecond()]);

        [$after] = $this->reader()->forBusiness($workspace, $business, $customer->user);
        $this->assertNull($after->newReviewUri);
        $this->assertFalse($after->mirrorIsFresh);
    }

    public function test_a_revoked_connection_is_exposed_as_state_only(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business, ['state' => GoogleConnectionState::Revoked, 'revoked_at' => now()]);
        $this->bindGoogleLocation($business, $location, $connection, false);
        $this->authenticateAsSeoCustomer($customer);

        [$status] = $this->reader()->forBusiness($workspace, $business, $customer->user);

        $this->assertSame('revoked', $status->connectionState);
    }

    public function test_it_never_exposes_credentials_or_provider_identifiers(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $binding = $this->bindGoogleLocation($business, $location, $connection, true);
        $this->authenticateAsSeoCustomer($customer);

        $dump = json_encode($this->reader()->forBusiness($workspace, $business, $customer->user));

        $this->assertStringNotContainsString('plain-refresh-token-value', $dump);
        $this->assertStringNotContainsString('owner@example.test', $dump);
        $this->assertStringNotContainsString('accounts/1', $dump);
        $this->assertStringNotContainsString($binding->provider_location_resource_name, $dump);
        $this->assertStringNotContainsString('77 Secret Lane', $dump, 'No street address may appear.');
    }

    // -----------------------------------------------------------------
    // Location ACL — filtered BEFORE reading.
    // -----------------------------------------------------------------

    public function test_a_selected_scope_actor_sees_only_the_granted_locations(): void
    {
        [$owner, $business, $workspace, $first] = $this->growthTenantWithLocation();
        $second = $this->extraLocation($business, 'Restricted Second Site');
        $third = $this->extraLocation($business, 'Restricted Third Site');
        $connection = $this->activeConnection($business);
        foreach ([$first, $second, $third] as $location) {
            $this->bindGoogleLocation($business, $location, $connection, true, ['title' => 'Different']);
        }

        $member = $this->selectedScopeMember($workspace, [$first]);
        $this->authenticateAsSeoCustomer($member);

        $statuses = $this->reader()->forBusiness($workspace, $business, $member->user);

        $this->assertCount(1, $statuses);
        $this->assertSame((int) $first->id, $statuses[0]->locationId);

        $dump = json_encode($statuses);
        foreach ([$second, $third] as $inaccessible) {
            $this->assertStringNotContainsString($inaccessible->name, $dump);
            $this->assertStringNotContainsString($inaccessible->uid, $dump);
        }
    }

    public function test_an_actor_with_no_location_grant_gets_an_empty_list_not_null_and_not_a_count(): void
    {
        [, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $location, $connection, true);

        $member = $this->selectedScopeMember($workspace, []);
        $this->authenticateAsSeoCustomer($member);

        $this->assertSame([], $this->reader()->forBusiness($workspace, $business, $member->user));
    }

    public function test_an_inaccessible_locations_binding_is_never_loaded_into_a_result(): void
    {
        [, $business, $workspace, $granted] = $this->growthTenantWithLocation();
        $hidden = $this->extraLocation($business, 'Hidden Site');
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $granted, $connection, true, ['new_review_uri' => 'https://search.google.test/granted']);
        $this->bindGoogleLocation($business, $hidden, $connection, true, ['new_review_uri' => 'https://search.google.test/hidden-secret']);

        $member = $this->selectedScopeMember($workspace, [$granted]);
        $this->authenticateAsSeoCustomer($member);

        $dump = json_encode($this->reader()->forBusiness($workspace, $business, $member->user));

        $this->assertStringContainsString('granted', $dump);
        $this->assertStringNotContainsString('hidden-secret', $dump);
    }

    // -----------------------------------------------------------------
    // Read-only: no provider call, no write, no persistence.
    // -----------------------------------------------------------------

    public function test_it_makes_no_provider_call_and_writes_nothing(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $location, $connection, true);
        $this->bindGoogleLocation($business, $this->extraLocation($business, 'Second'), $connection, false);
        $this->authenticateAsSeoCustomer($customer);

        $this->forbidProviderClient();
        $before = $this->dbFingerprint($this->seoProtectedTables());

        $this->assertNotNull($this->reader()->forBusiness($workspace, $business, $customer->user));

        Http::assertNothingSent();
        $this->assertSame($before, $this->dbFingerprint($this->seoProtectedTables()), 'The reader must write nothing, including no operation-ledger row.');
        $this->assertSame(0, \DB::table('business_google_operations')->count());
    }

    public function test_repeated_reads_are_stable_and_leave_the_mirror_untouched(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $binding = $this->bindGoogleLocation($business, $location, $connection, true);
        $this->authenticateAsSeoCustomer($customer);

        $first = $this->reader()->forBusiness($workspace, $business, $customer->user);
        $second = $this->reader()->forBusiness($workspace, $business, $customer->user);

        $this->assertEquals($first, $second);
        $fresh = $binding->fresh();
        $this->assertEquals($binding->mirror_expires_at, $fresh->mirror_expires_at, 'Reading must never extend a mirror\'s life.');
        $this->assertNull($fresh->last_synced_at);
    }

    // -----------------------------------------------------------------
    // Constant cost.
    // -----------------------------------------------------------------

    public function test_the_query_count_does_not_grow_with_the_number_of_locations(): void
    {
        $build = function (int $locationCount) {
            [$customer, $business, $workspace, $primary] = $this->growthTenantWithLocation();
            $connection = $this->activeConnection($business);
            $this->bindGoogleLocation($business, $primary, $connection, true, ['title' => 'X']);

            for ($i = 2; $i <= $locationCount; $i++) {
                $location = $this->extraLocation($business, "Site {$i}");
                $this->bindGoogleLocation($business, $location, $connection, $i % 2 === 0, ['title' => 'X']);
            }

            return [$customer, $business, $workspace];
        };

        // Warm anything that is cached once per process, so the two
        // measurements below start from the same state.
        [$warmCustomer, $warmBusiness, $warmWorkspace] = $build(2);
        $this->authenticateAsSeoCustomer($warmCustomer);
        $this->reader()->forBusiness($warmWorkspace, $warmBusiness, $warmCustomer->user);

        [$smallCustomer, $smallBusiness, $smallWorkspace] = $build(1);
        [$largeCustomer, $largeBusiness, $largeWorkspace] = $build(25);

        $this->authenticateAsSeoCustomer($smallCustomer);
        $small = $this->capturedQueries(fn () => $this->reader()->forBusiness($smallWorkspace, $smallBusiness, $smallCustomer->user));

        $this->authenticateAsSeoCustomer($largeCustomer);
        $large = $this->capturedQueries(fn () => $this->reader()->forBusiness($largeWorkspace, $largeBusiness, $largeCustomer->user));

        $this->assertSame(count($small), count($large), 'Query count must be identical for 1 and 25 Locations.');
    }
}
