<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Exceptions\Entitlement\LastActiveLocationCannotBeArchivedException;
use App\Exceptions\Entitlement\LocationAllocationCancellationRefusedException;
use App\Exceptions\Entitlement\LocationSlotAllocationRequiredException;
use App\Exceptions\Entitlement\LocationSlotLimitExceededException;
use App\Exceptions\Entitlement\PrimaryLocationCannotBeArchivedException;
use App\Library\Business\BusinessLocationManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\LocationSlotAllocationAuthority;
use App\Models\BusinessLocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — physical-location capacity and lifecycle.
 *
 * Covers contracted tests T-LOC-1..T-LOC-8 and T-LOC-11..T-LOC-16.
 * T-LOC-9 (source-boundary inventory) and T-LOC-10 (route delegation) live
 * in BusinessLocationBoundaryTest; T-BIZ-1..2 live in
 * BusinessAccountCapacityTest; concurrency lives in
 * BusinessLocationConcurrencyTest.
 *
 * A physical location is a branch, storefront, office or service area
 * INSIDE one Business. Nothing here treats it as a tenancy, payer, wallet,
 * authorization or account-switcher boundary.
 */
class BusinessLocationCapacityTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    private function manager(): BusinessLocationManager
    {
        return app(BusinessLocationManager::class);
    }

    private function entitlements(): EntitlementManager
    {
        return app(EntitlementManager::class);
    }

    // -----------------------------------------------------------------
    // T-LOC-1..5 — included, paid, ceiling, Agency
    // -----------------------------------------------------------------

    /** T-LOC-1 — Core/Growth include three active locations, free. */
    public function test_first_three_active_locations_are_included_and_free(): void
    {
        [, $business] = $this->locationTenant();

        foreach ([1, 2, 3] as $index) {
            $this->manager()->createLocation($business, $this->locationPayload(['name' => 'Branch ' . $index]));
        }

        $this->assertSame(3, $this->activeLocationCount($business));

        $business->refresh();
        $this->assertSame(0, (int) $business->additional_location_slots, 'No allocation may be consumed by the included three.');
        $this->assertSame(0, (int) $business->grandfathered_location_slots);
    }

    /** T-LOC-2 — the fourth needs a paid allocation, then succeeds. */
    public function test_fourth_active_location_requires_a_paid_allocation(): void
    {
        [, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $business->refresh();

        try {
            $this->manager()->createLocation($business, $this->locationPayload());
            $this->fail('A fourth active location must be refused without an allocation.');
        } catch (LocationSlotAllocationRequiredException) {
            // expected
        }

        $this->assertSame(3, $this->activeLocationCount($business), 'A refused creation must be a true no-op.');

        $this->setAdditionalLocationSlots($business, 1);

        $this->manager()->createLocation($business->refresh(), $this->locationPayload(['name' => 'Fourth']));

        $this->assertSame(4, $this->activeLocationCount($business));
    }

    /** T-LOC-3 — the fifth behaves identically against a second allocation. */
    public function test_fifth_active_location_requires_a_second_allocation(): void
    {
        [, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 4);
        $this->setAdditionalLocationSlots($business, 1);
        $business->refresh();

        try {
            $this->manager()->createLocation($business, $this->locationPayload());
            $this->fail('A fifth active location must be refused with only one allocation.');
        } catch (LocationSlotAllocationRequiredException) {
            // expected
        }

        $this->setAdditionalLocationSlots($business, 2);

        $this->manager()->createLocation($business->refresh(), $this->locationPayload(['name' => 'Fifth']));

        $this->assertSame(5, $this->activeLocationCount($business));
    }

    /** T-LOC-4 — the sixth is refused, and NO allocation can raise it. */
    public function test_sixth_active_location_is_refused_and_no_allocation_raises_the_ceiling(): void
    {
        [, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 5);
        $this->setAdditionalLocationSlots($business, 2);
        $business->refresh();

        try {
            $this->manager()->createLocation($business, $this->locationPayload());
            $this->fail('A sixth active location must be refused on Core/Growth.');
        } catch (LocationSlotLimitExceededException) {
            // expected
        }

        // Even a further allocation cannot raise the tier ceiling.
        $this->setAdditionalLocationSlots($business, 5);

        try {
            $this->manager()->createLocation($business->refresh(), $this->locationPayload());
            $this->fail('No allocation may raise the Core/Growth location ceiling.');
        } catch (LocationSlotLimitExceededException) {
            // expected
        }

        $this->assertSame(5, $this->activeLocationCount($business));
    }

    /** T-LOC-5 — Agency is unlimited. */
    public function test_agency_business_creates_locations_without_limit(): void
    {
        [, $business] = $this->agencyTenant();

        foreach (range(1, 8) as $index) {
            $this->manager()->createLocation($business, $this->locationPayload(['name' => 'Agency Branch ' . $index]));
        }

        $this->assertSame(8, $this->activeLocationCount($business));

        $decision = $this->entitlements()->decideLocationSlotCapacity($business->refresh());
        $this->assertTrue($decision->unlimited);
        $this->assertNull($decision->remaining());
    }

    // -----------------------------------------------------------------
    // T-LOC-11..16 — lifecycle, reuse, grandfathering
    // -----------------------------------------------------------------

    /** T-LOC-11 — archiving frees a slot; the PAID allocation is reusable. */
    public function test_archiving_frees_a_slot_and_the_paid_allocation_is_reusable(): void
    {
        [, $business] = $this->locationTenant();
        $locations = $this->seedActiveLocations($business, 4);
        $this->setAdditionalLocationSlots($business, 1);
        $business->refresh();

        // Full: 3 included + 1 paid = 4 active.
        $this->assertSame(0, $this->entitlements()->decideLocationSlotCapacity($business)->remaining());

        $this->manager()->archiveLocation($business, $locations[3]);

        $business->refresh();
        $this->assertSame(3, $this->activeLocationCount($business));
        $this->assertSame(
            1,
            (int) $business->additional_location_slots,
            'Archiving must NOT decrement a paid allocation — it stays subscribed and reusable.'
        );

        // The replacement reuses the same paid slot, with no re-purchase.
        $this->manager()->createLocation($business, $this->locationPayload(['name' => 'Replacement']));

        $this->assertSame(4, $this->activeLocationCount($business));
        $this->assertSame(1, (int) $business->refresh()->additional_location_slots);
    }

    /** T-LOC-12 — reactivation re-runs the full capacity check. */
    public function test_reactivation_rechecks_capacity_and_fails_without_mutation(): void
    {
        [, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $archived = $this->seedLocation($business, 'Closed Branch', false, BusinessLocationLifecycleState::Archived);
        $business->refresh();

        try {
            $this->manager()->reactivateLocation($business, $archived);
            $this->fail('Reactivation must run the same capacity check as creation.');
        } catch (LocationSlotAllocationRequiredException) {
            // expected
        }

        $this->assertSame(
            BusinessLocationLifecycleState::Archived,
            $archived->refresh()->lifecycle_state,
            'A refused reactivation must be a true no-op.'
        );
        $this->assertSame(3, $this->activeLocationCount($business));

        $this->setAdditionalLocationSlots($business, 1);
        $this->manager()->reactivateLocation($business->refresh(), $archived);

        $this->assertSame(BusinessLocationLifecycleState::Active, $archived->refresh()->lifecycle_state);
        $this->assertSame(4, $this->activeLocationCount($business));
    }

    /** T-LOC-14 — the primary location cannot be archived while primary. */
    public function test_primary_location_cannot_be_archived_while_primary(): void
    {
        [, $business] = $this->locationTenant();
        $locations = $this->seedActiveLocations($business, 2);
        $primary = $locations[0];
        $business->refresh();

        try {
            $this->manager()->archiveLocation($business, $primary);
            $this->fail('The primary location must not be archivable without reassignment.');
        } catch (PrimaryLocationCannotBeArchivedException) {
            // expected
        }

        $this->assertSame(BusinessLocationLifecycleState::Active, $primary->refresh()->lifecycle_state);
        $this->assertTrue((bool) $primary->refresh()->is_primary);

        // With an explicit replacement, both happen in one transaction.
        $this->manager()->archiveLocation($business, $primary, (string) $locations[1]->uid);

        $this->assertSame(BusinessLocationLifecycleState::Archived, $primary->refresh()->lifecycle_state);
        $this->assertFalse((bool) $primary->refresh()->is_primary);
        $this->assertTrue((bool) $locations[1]->refresh()->is_primary);
    }

    /** T-LOC-14 — the last active location cannot be archived at all. */
    public function test_last_active_location_cannot_be_archived(): void
    {
        [, $business] = $this->locationTenant();
        $only = $this->seedActiveLocations($business, 1)[0];
        $business->refresh();

        $this->expectException(LastActiveLocationCannotBeArchivedException::class);

        $this->manager()->archiveLocation($business, $only);
    }

    /** T-LOC-15 — archiving deletes nothing, including the GBP binding. */
    public function test_archiving_retains_the_row_its_history_and_its_google_binding(): void
    {
        [, $business] = $this->locationTenant();
        $locations = $this->seedActiveLocations($business, 2);
        $target = $locations[1];

        $connectionId = DB::table('business_google_connections')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $business->id,
            'state' => 'active',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bindingId = DB::table('business_google_locations')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_google_connection_id' => $connectionId,
            'business_id' => $business->id,
            'business_location_id' => $target->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->manager()->archiveLocation($business->refresh(), $target);

        // The row survives, readable, with its history.
        $this->assertDatabaseHas('business_locations', [
            'id' => $target->id,
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
        ]);
        $this->assertNotNull(BusinessLocation::query()->find($target->id));

        // The Google binding survives — archiving is never a delete, so the
        // bgl_location_business_foreign cascade never fires.
        $this->assertDatabaseHas('business_google_locations', ['id' => $bindingId]);
        $this->assertDatabaseHas('business_google_connections', ['id' => $connectionId]);
    }

    /** T-LOC-16 — grandfathered excess is consumed, never reusable. */
    public function test_grandfathered_excess_is_consumed_on_archive_and_is_not_transferable(): void
    {
        [, $business] = $this->locationTenant();
        // Pre-existing over-capacity state: 5 active, 2 complimentary.
        $locations = $this->seedActiveLocations($business, 5);
        $this->setGrandfatheredLocationSlots($business, 2);
        $business->refresh();

        $this->assertSame(5, $this->entitlements()->decideLocationSlotCapacity($business)->effectiveCapacity);

        $this->manager()->archiveLocation($business, $locations[4]);

        $business->refresh();
        $this->assertSame(4, $this->activeLocationCount($business));
        $this->assertSame(
            1,
            (int) $business->grandfathered_location_slots,
            'Archiving a grandfathered excess location consumes that complimentary allowance.'
        );

        $this->manager()->archiveLocation($business, $locations[3]);

        $business->refresh();
        $this->assertSame(3, $this->activeLocationCount($business));
        $this->assertSame(0, (int) $business->grandfathered_location_slots);

        // The consumed complimentary allowance is NOT a reusable free slot:
        // a replacement now needs a paid allocation like any other.
        $this->expectException(LocationSlotAllocationRequiredException::class);
        $this->manager()->createLocation($business, $this->locationPayload(['name' => 'Not Free']));
    }

    // -----------------------------------------------------------------
    // T-LOC-13 — allocation cancellation
    // -----------------------------------------------------------------

    /** T-LOC-13 — cancellation is refused while the active count is too high. */
    public function test_allocation_cancellation_is_refused_while_active_count_is_too_high(): void
    {
        [$customer, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 4);
        $this->setAdditionalLocationSlots($business, 1);
        $business->refresh();

        try {
            $this->entitlements()->cancelAdditionalLocationSlot($business, $this->operatorLocationSlotAuthority());
            $this->fail('Cancelling must be refused while 4 locations are active and capacity would drop to 3.');
        } catch (LocationAllocationCancellationRefusedException $exception) {
            $this->assertSame(4, $exception->activeCount);
            $this->assertSame(3, $exception->capacityAfter);
        }

        // A refused cancellation is a COMPLETE no-op: no counter change and
        // no misleading audit row.
        $this->assertSame(1, (int) $business->refresh()->additional_location_slots);
        $this->assertDatabaseMissing('workspace_entitlement_transitions', [
            'workspace_id' => $business->workspace_id,
            'transition_type' => 'additional_location_slots_changed',
        ]);
    }

    /** T-LOC-13 — a permitted cancellation succeeds and is audited. */
    public function test_permitted_allocation_cancellation_succeeds_and_is_audited(): void
    {
        [$customer, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $this->setAdditionalLocationSlots($business, 1);
        $business->refresh();

        $this->entitlements()->cancelAdditionalLocationSlot($business, $this->operatorLocationSlotAuthority());

        $this->assertSame(0, (int) $business->refresh()->additional_location_slots);

        $transition = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $business->workspace_id)
            ->where('transition_type', 'additional_location_slots_changed')
            ->first();

        $this->assertNotNull($transition);

        $payload = json_decode((string) $transition->payload, true);
        $this->assertSame((int) $business->id, $payload['business_id']);
        $this->assertSame(1, $payload['from_additional_location_slots']);
        $this->assertSame(0, $payload['to_additional_location_slots']);

        // Correction round 1 — the audit row records WHICH authority made
        // the change, so a paid-capacity movement is never anonymous.
        $this->assertSame('platform_operator', $payload['allocation_provenance']);
    }

    /** Cancelling with nothing allocated is a true no-op, never a false audit row. */
    public function test_cancelling_with_no_allocation_is_a_true_no_op(): void
    {
        [$customer, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 1);
        $business->refresh();

        $this->entitlements()->cancelAdditionalLocationSlot($business, $this->operatorLocationSlotAuthority());

        $this->assertSame(0, (int) $business->refresh()->additional_location_slots);
        $this->assertDatabaseMissing('workspace_entitlement_transitions', [
            'workspace_id' => $business->workspace_id,
            'transition_type' => 'additional_location_slots_changed',
        ]);
    }

    /** Allocating records capacity and audits it — and charges nothing. */
    public function test_allocating_records_capacity_and_collects_nothing(): void
    {
        [$customer, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $business->refresh();

        $this->entitlements()->allocateAdditionalLocationSlot($business, $this->operatorLocationSlotAuthority());

        $this->assertSame(1, (int) $business->refresh()->additional_location_slots);

        // Slice 1A collects nothing: no wallet movement of any kind.
        $this->assertDatabaseCount('business_usage_ledger_entries', 0);
        $this->assertDatabaseCount('business_usage_reservations', 0);
    }

    /** The tier ceiling also bounds how many allocations may be bought. */
    public function test_allocation_cannot_exceed_the_tier_ceiling(): void
    {
        [$customer, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $business->refresh();

        $this->entitlements()->allocateAdditionalLocationSlot($business, $this->operatorLocationSlotAuthority());
        $this->entitlements()->allocateAdditionalLocationSlot($business->refresh(), $this->operatorLocationSlotAuthority());

        $this->assertSame(2, (int) $business->refresh()->additional_location_slots);

        $this->expectException(LocationSlotLimitExceededException::class);
        $this->entitlements()->allocateAdditionalLocationSlot($business->refresh(), $this->operatorLocationSlotAuthority());
    }

    // -----------------------------------------------------------------
    // Correction round 1 — the allocation seam's authority/provenance
    // boundary. The paid slot seam must not mutate merely because some
    // caller invoked it with an actor id.
    // -----------------------------------------------------------------

    /** A customer's own user id is not operator provenance, and grants nothing. */
    public function test_allocation_refuses_a_customer_posing_as_a_platform_operator(): void
    {
        [$customer, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $business->refresh();

        $authority = LocationSlotAllocationAuthority::fromPlatformOperator(
            (int) $customer->user_id,
            'Attempted self-authorised allocation.',
        );

        try {
            $this->entitlements()->allocateAdditionalLocationSlot($business, $authority);
            $this->fail('A non-administrator must not be able to allocate a paid location slot.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(0, (int) $business->refresh()->additional_location_slots);
        $this->assertDatabaseMissing('workspace_entitlement_transitions', [
            'workspace_id' => $business->workspace_id,
            'transition_type' => 'additional_location_slots_changed',
        ]);
    }

    /** Cancellation is gated by the identical boundary. */
    public function test_cancellation_refuses_a_customer_posing_as_a_platform_operator(): void
    {
        [$customer, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $this->setAdditionalLocationSlots($business, 1);
        $business->refresh();

        $authority = LocationSlotAllocationAuthority::fromPlatformOperator(
            (int) $customer->user_id,
            'Attempted self-authorised cancellation.',
        );

        try {
            $this->entitlements()->cancelAdditionalLocationSlot($business, $authority);
            $this->fail('A non-administrator must not be able to cancel a paid location slot.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(1, (int) $business->refresh()->additional_location_slots);
        $this->assertDatabaseMissing('workspace_entitlement_transitions', [
            'workspace_id' => $business->workspace_id,
            'transition_type' => 'additional_location_slots_changed',
        ]);
    }

    /**
     * Verified-billing provenance cannot be manufactured without the
     * evidence it claims to carry. Empty strings are rejected at
     * construction, so no caller can fabricate a payment it never took.
     */
    public function test_verified_billing_authority_requires_real_evidence(): void
    {
        [$customer] = $this->locationTenant();

        $this->expectException(InvalidArgumentException::class);

        LocationSlotAllocationAuthority::fromVerifiedBilling(
            (int) $customer->user_id,
            '',
            '',
            'Fabricated evidence.',
        );
    }

    /** A genuine verified-billing caller records its evidence on the audit row. */
    public function test_verified_billing_allocation_records_its_payment_evidence(): void
    {
        [$customer, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $business->refresh();

        $this->entitlements()->allocateAdditionalLocationSlot(
            $business,
            $this->verifiedBillingLocationSlotAuthority((int) $customer->user_id, 'idem_key_loc_1', 'prov_ref_loc_1'),
        );

        $this->assertSame(1, (int) $business->refresh()->additional_location_slots);

        $transition = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $business->workspace_id)
            ->where('transition_type', 'additional_location_slots_changed')
            ->first();

        $this->assertNotNull($transition);
        $this->assertNull($transition->actor_user_id);
        $this->assertSame((int) $customer->user_id, (int) $transition->requesting_customer_user_id);
        $this->assertSame('idem_key_loc_1', $transition->payment_idempotency_key);

        $payload = json_decode((string) $transition->payload, true);
        $this->assertSame('verified_billing', $payload['allocation_provenance']);
        $this->assertSame('prov_ref_loc_1', $payload['billing_provider_reference']);
    }

    /**
     * The seam's signature itself is the guarantee: it accepts no actor id,
     * so no caller anywhere can allocate paid capacity by supplying one.
     */
    public function test_the_allocation_seam_accepts_no_bare_actor_id(): void
    {
        foreach (['allocateAdditionalLocationSlot', 'cancelAdditionalLocationSlot'] as $method) {
            $parameters = (new ReflectionMethod(EntitlementManager::class, $method))->getParameters();

            $this->assertCount(2, $parameters, $method . '() must take exactly the Business and an explicit authority.');
            $this->assertSame(
                LocationSlotAllocationAuthority::class,
                (string) $parameters[1]->getType(),
                $method . '() must demand LocationSlotAllocationAuthority, never an actor id.',
            );
            $this->assertFalse(
                $parameters[1]->isOptional(),
                $method . '() must not let a caller omit the authority.',
            );
        }
    }

    // -----------------------------------------------------------------
    // T-LOC-6/7/8 — capacity kinds are separately computable
    // -----------------------------------------------------------------

    /** The four capacity kinds of §7.5.1 are each readable without inference. */
    public function test_the_four_capacity_kinds_are_separately_computable(): void
    {
        [, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 4);
        $this->seedLocation($business, 'Closed', false, BusinessLocationLifecycleState::Archived);
        $this->setAdditionalLocationSlots($business, 1);
        $this->setGrandfatheredLocationSlots($business, 1);
        $business->refresh();

        $decision = $this->entitlements()->decideLocationSlotCapacity($business);

        $this->assertSame(4, $decision->activeLocationCount, 'The archived row must not be counted.');
        $this->assertSame(3, $decision->includedSlots);
        $this->assertSame(1, $decision->additionalSlotsAllocated);
        $this->assertSame(1, $decision->grandfatheredSlots);
        $this->assertSame(5, $decision->effectiveCapacity);
        $this->assertSame(1, $decision->remaining());
    }
}
