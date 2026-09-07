<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Models\BusinessGoogleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §8 / §19.2 / §32.4 — explicit binding.
 *
 * CORRECTION PASS ITEM 5. Binding no longer accepts caller-supplied
 * provider resource names. Each rendered candidate carries a short-lived
 * HMAC token bound to the Business, connection, actor and account/location
 * PAIR; the controller derives both names from that verified token, so a
 * tampered, expired, wrong-actor, wrong-Business, wrong-connection or
 * substituted pair is rejected before any provider read and before any
 * write.
 *
 * Security criterion G-5 (no listing claimable twice) is unchanged.
 */
class GoogleBusinessProfileBindingTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    /** T-BIND-2 — rendering the chooser creates ZERO binding rows. */
    public function test_rendering_the_chooser_persists_no_candidate(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Snap Booth Co');

        $this->assertDatabaseCount('business_google_locations', 0);
    }

    /** T-BIND-3 — nothing is auto-bound, and no radio is pre-checked. */
    public function test_a_single_candidate_is_never_auto_bound(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $this->assertDatabaseCount('business_google_locations', 0);
        $this->assertStringNotContainsString('checked', explode('candidate_token', $response->getContent())[1] ?? '');
    }

    /**
     * Item 5 — the chooser renders SIGNED TOKENS, not raw resource names,
     * so there is nothing in the form to substitute.
     */
    public function test_the_chooser_renders_signed_tokens_not_raw_resource_names(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);

        $body = (string) $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]))->getContent();

        $this->assertStringContainsString('name="candidate_token"', $body);
        $this->assertStringNotContainsString('name="provider_account_resource_name"', $body);
        $this->assertStringNotContainsString('name="provider_location_resource_name"', $body);
    }

    /** T-BIND-1 / T-BIND-5 / T-BIND-6 — both resource names persisted. */
    public function test_binding_persists_both_provider_resource_names(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $this->candidateTokenFor($business, $connection, $customer->user_id),
            'business_location_uid' => $location->uid,
        ])->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        $this->assertDatabaseHas('business_google_locations', [
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        $this->assertDatabaseHas('business_google_operations', [
            'business_id' => $business->id,
            'operation_type' => 'location_bound',
            'status' => 'succeeded',
        ]);
    }

    /** T-BIND-4 / G-5 — a Google location cannot be claimed twice. */
    public function test_a_google_location_cannot_be_claimed_twice_across_workspaces(): void
    {
        [$firstCustomer, $firstBusiness, $firstWorkspace] = $this->entitledTenant();
        $firstLocation = $this->createLocation($firstBusiness);
        $firstConnection = $this->activeConnection($firstBusiness);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($firstCustomer);
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$firstWorkspace->uid, $firstBusiness->uid]), [
            'candidate_token' => $this->candidateTokenFor($firstBusiness, $firstConnection, $firstCustomer->user_id),
            'business_location_uid' => $firstLocation->uid,
        ])->assertRedirect();

        $this->assertDatabaseCount('business_google_locations', 1);

        [$secondCustomer, $secondBusiness, $secondWorkspace] = $this->entitledTenant();
        $secondLocation = $this->createLocation($secondBusiness);
        $secondConnection = $this->activeConnection($secondBusiness);

        $this->authenticateAsCustomer($secondCustomer);
        $response = $this->post(route('customer.workspaces.businesses.gbp.bind', [$secondWorkspace->uid, $secondBusiness->uid]), [
            'candidate_token' => $this->candidateTokenFor($secondBusiness, $secondConnection, $secondCustomer->user_id),
            'business_location_uid' => $secondLocation->uid,
        ]);

        $response->assertRedirect();
        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('already connected', (string) session('message'));

        $this->assertDatabaseCount('business_google_locations', 1);
        $this->assertDatabaseHas('business_google_locations', ['business_id' => $firstBusiness->id]);
        $this->assertStringNotContainsString($firstBusiness->name, (string) session('message'));
    }

    /** T-BIND-7 — a BusinessLocation uid from another Business is 404. */
    public function test_binding_a_foreign_business_location_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        [, $otherBusiness] = $this->entitledTenant();
        $foreignLocation = $this->createLocation($otherBusiness);

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $this->candidateTokenFor($business, $connection, $customer->user_id),
            'business_location_uid' => $foreignLocation->uid,
        ])->assertNotFound();

        $this->assertDatabaseCount('business_google_locations', 0);
    }

    /**
     * Item 5 — a TAMPERED token is refused before any provider read and
     * before any write.
     */
    public function test_a_tampered_candidate_token_is_refused(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $token = $this->candidateTokenFor($business, $connection, $customer->user_id);
        $tampered = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $tampered,
            'business_location_uid' => $location->uid,
        ])->assertRedirect();

        $this->assertSame('error', session('status'));
        $this->assertDatabaseCount('business_google_locations', 0);
        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'));
    }

    /** Item 5 — an EXPIRED token is refused. */
    public function test_an_expired_candidate_token_is_refused(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $token = $this->candidateTokenFor($business, $connection, $customer->user_id);

        $this->travel(16)->minutes();

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $token,
            'business_location_uid' => $location->uid,
        ])->assertRedirect();

        $this->assertSame('error', session('status'));
        $this->assertDatabaseCount('business_google_locations', 0);
        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'));

        $this->travelBack();
    }

    /**
     * Item 5 — a token issued to ANOTHER ACTOR, for ANOTHER BUSINESS, or
     * for ANOTHER CONNECTION is refused, and so is a SUBSTITUTED PAIR.
     * Each case leaves no binding and makes no provider read.
     */
    public function test_every_candidate_token_binding_is_enforced(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        [$otherCustomer, $otherBusiness] = $this->entitledTenant();
        $otherConnection = $this->activeConnection($otherBusiness);

        $tokens = [
            // Issued to a different actor.
            'wrong actor' => $this->candidateTokenFor($business, $connection, $otherCustomer->user_id),
            // Issued for a different Business.
            'wrong business' => $this->candidateTokenFor($otherBusiness, $otherConnection, $customer->user_id),
            // Issued against a different connection.
            'wrong connection' => $this->candidateTokenFor($business, $otherConnection, $customer->user_id),
        ];

        $this->authenticateAsCustomer($customer);

        foreach ($tokens as $label => $token) {
            $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
                'candidate_token' => $token,
                'business_location_uid' => $location->uid,
            ])->assertRedirect();

            $this->assertSame('error', session('status'), "A {$label} token must be refused.");
            $this->assertDatabaseCount('business_google_locations', 0);
        }

        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'), 'No refused token may reach the provider.');
    }

    /**
     * Item 5 — PAIR SUBSTITUTION. A caller posts the legitimate token for
     * accounts/A1 + locations/L1 while ALSO posting raw fields naming a
     * different pair. The raw fields must be ignored entirely: the binding
     * that results is the one inside the signature.
     */
    public function test_raw_resource_name_fields_cannot_substitute_the_signed_pair(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);

        // The Fake knows BOTH locations, so the substitution would
        // genuinely succeed if the raw fields were trusted.
        $this->fakeGoogleWithLocation();
        $this->fakeGoogle->withLocation('accounts/A1', 'locations/L9', $this->rawLocationPayload(['name' => 'locations/L9', 'title' => 'Attacker Co']));

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $this->candidateTokenFor($business, $connection, $customer->user_id, 'accounts/A1', 'locations/L1'),
            'business_location_uid' => $location->uid,
            'provider_location_resource_name' => 'locations/L9',
            'provider_account_resource_name' => 'accounts/EVIL',
        ])->assertRedirect();

        // The SIGNED pair won; the raw fields were never read.
        $this->assertDatabaseHas('business_google_locations', [
            'business_id' => $business->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        $this->assertDatabaseMissing('business_google_locations', ['provider_location_resource_name' => 'locations/L9']);
        $this->assertDatabaseMissing('business_google_locations', ['provider_account_resource_name' => 'accounts/EVIL']);

        // ...and the provider was asked for L1, never L9.
        $requested = array_column($this->fakeGoogle->callsTo('getLocation'), 'location');
        $this->assertContains('locations/L1', $requested);
        $this->assertNotContains('locations/L9', $requested);
    }

    /** Contract §18.2 — a missing token fails validation. */
    public function test_a_missing_candidate_token_fails_validation(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'business_location_uid' => $location->uid,
        ])->assertSessionHasErrors(['candidate_token']);

        $this->assertDatabaseCount('business_google_locations', 0);
        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'));
    }

    /** T-BIND-8 — unbind removes the binding, keeps the connection. */
    public function test_unbind_removes_the_binding_but_keeps_the_connection(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $this->candidateTokenFor($business, $connection, $customer->user_id),
            'business_location_uid' => $location->uid,
        ]);

        $binding = BusinessGoogleLocation::query()->where('business_id', $business->id)->firstOrFail();

        $this->post(route('customer.workspaces.businesses.gbp.unbind', [$workspace->uid, $business->uid]), [
            'binding_uid' => $binding->uid,
        ])->assertRedirect();

        $this->assertDatabaseMissing('business_google_locations', ['id' => $binding->id]);

        $connection->refresh();
        $this->assertTrue($connection->isActive());

        $this->assertDatabaseHas('business_google_operations', [
            'business_id' => $business->id,
            'operation_type' => 'location_unbound',
            'status' => 'succeeded',
        ]);
    }

    /** T-DEL-2 — deleting a BusinessLocation deletes only its binding. */
    public function test_deleting_a_business_location_deletes_only_its_binding(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);

        BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        DB::table('business_locations')->where('id', $location->id)->delete();

        $this->assertSame(0, DB::table('business_google_locations')->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('business_google_connections', ['id' => $connection->id, 'state' => 'active']);
    }

    /** Contract §12 (C-1) — one connection per Business, enforced in the DB. */
    public function test_a_business_can_hold_only_one_connection(): void
    {
        [, $business] = $this->entitledTenant();
        $this->activeConnection($business);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->activeConnection($business);
    }
}
