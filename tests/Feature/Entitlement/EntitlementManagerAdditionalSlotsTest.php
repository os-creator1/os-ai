<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\WorkspaceAdditionalBusinessSlotsChanged;
use App\Exceptions\Entitlement\ComplimentaryWorkspaceCannotAllocatePaidSlotsException;
use App\Exceptions\Entitlement\InvalidAdditionalBusinessSlotsException;
use App\Exceptions\Entitlement\InvalidPaymentAllocationEvidenceException;
use App\Exceptions\Entitlement\PaymentAllocationIdempotencyConflictException;
use App\Exceptions\Entitlement\PaymentAllocationWorkspaceMismatchException;
use App\Exceptions\Entitlement\UndefinedPlanPricingException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EntitlementManagerAdditionalSlotsTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createNonAdmin(): int
    {
        return User::create([
            'first_name' => 'Non', 'last_name' => 'Admin', 'email' => 'nonadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ])->id;
    }

    private function definePricing(WorkspacePlanTier $tier): void
    {
        $currency = Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        WorkspacePlanCatalog::where('tier', $tier->value)->update(['price' => '49.00', 'currency_id' => $currency->id, 'additional_business_slot_price_ratio' => 0.5]);
    }

    /**
     * Base price + currency only (ratio left null) — lets a paid first
     * assignment with zero additional slots succeed (assignFirstPlan() only
     * requires the ratio when additionalBusinessSlots > 0), while leaving
     * the ratio genuinely undefined for a subsequent slot-increase check.
     */
    private function definePricingWithoutRatio(WorkspacePlanTier $tier): void
    {
        $currency = Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        WorkspacePlanCatalog::where('tier', $tier->value)->update(['price' => '49.00', 'currency_id' => $currency->id, 'additional_business_slot_price_ratio' => null]);
    }

    private function assignedWorkspace(WorkspacePlanTier $tier, bool $complimentary, int $slots = 0): Workspace
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->createAdmin(), 'Fixture.', $complimentary, $slots);

        return $workspace->fresh();
    }

    public function test_core_accepts_zero_one_two(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $updated = app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 2, $this->createAdmin());

        $this->assertSame(2, $updated->additional_business_slots);
    }

    public function test_agency_rejects_any_non_zero_value(): void
    {
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Agency, true, 0);

        $this->expectException(InvalidAdditionalBusinessSlotsException::class);
        app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 1, $this->createAdmin());
    }

    public function test_increase_for_non_complimentary_requires_pricing(): void
    {
        $this->definePricingWithoutRatio(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $this->expectException(UndefinedPlanPricingException::class);
        app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 1, $this->createAdmin());
    }

    public function test_increase_for_complimentary_does_not_require_pricing(): void
    {
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, true, 0);

        $updated = app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 2, $this->createAdmin());

        $this->assertSame(2, $updated->additional_business_slots);
    }

    public function test_decrease_is_always_permitted_regardless_of_pricing(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 2);

        // Pricing is fully defined for the first assignment above (required
        // since it allocates 2 paid slots), then removed entirely so the
        // decrease below is proven to succeed with genuinely undefined
        // pricing, not merely with pricing the fixture happened to have.
        WorkspacePlanCatalog::where('tier', WorkspacePlanTier::Core->value)->update([
            'price' => null, 'currency_id' => null, 'additional_business_slot_price_ratio' => null,
        ]);

        $updated = app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 0, $this->createAdmin());

        $this->assertSame(0, $updated->additional_business_slots);
    }

    public function test_writes_exact_before_after_audit_and_dispatches_event(): void
    {
        Event::fake([WorkspaceAdditionalBusinessSlotsChanged::class]);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, true, 0);

        app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 1, $this->createAdmin());

        $this->assertDatabaseHas('workspace_entitlement_transitions', [
            'workspace_id' => $workspace->id,
            'transition_type' => 'additional_business_slots_changed',
            'from_additional_business_slots' => 0,
            'to_additional_business_slots' => 1,
        ]);
        Event::assertDispatched(WorkspaceAdditionalBusinessSlotsChanged::class, fn ($e) => $e->fromAdditionalBusinessSlots === 0 && $e->toAdditionalBusinessSlots === 1);
    }

    public function test_non_administrator_actor_is_denied(): void
    {
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, true, 0);

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 1, $this->createNonAdmin());
    }

    public function test_non_administrator_against_undefined_pricing_still_receives_authorization_exception(): void
    {
        $this->definePricingWithoutRatio(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        try {
            app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 1, $this->createNonAdmin());
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        } catch (UndefinedPlanPricingException $e) {
            $this->fail('Expected AuthorizationException, got UndefinedPlanPricingException: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // RFC-004 Amendment 1 — allocateAdditionalBusinessSlotsFromVerifiedPayment()
    // =========================================================================

    public function test_payment_verified_allocation_succeeds_and_is_correctly_audited(): void
    {
        Event::fake([WorkspaceAdditionalBusinessSlotsChanged::class]);
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $updated = app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment(
            $workspace,
            1,
            $this->createNonAdmin(),
            $workspace->id,
            'idem-key-' . uniqid(),
            'pi_test_123',
        );

        $this->assertSame(1, $updated->additional_business_slots);
        $this->assertDatabaseHas('workspace_entitlement_transitions', [
            'workspace_id' => $workspace->id,
            'transition_type' => 'additional_business_slots_changed',
            'actor_user_id' => null,
            'from_additional_business_slots' => 0,
            'to_additional_business_slots' => 1,
        ]);
        Event::assertDispatched(WorkspaceAdditionalBusinessSlotsChanged::class, fn ($e) => $e->fromAdditionalBusinessSlots === 0 && $e->toAdditionalBusinessSlots === 1 && $e->actorUserId === null);
    }

    public function test_true_replay_is_idempotent_and_writes_no_second_row_or_event(): void
    {
        Event::fake([WorkspaceAdditionalBusinessSlotsChanged::class]);
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);
        $customerId = $this->createNonAdmin();
        $key = 'idem-key-' . uniqid();

        $first = app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $customerId, $workspace->id, $key, 'pi_test_123');
        $second = app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $customerId, $workspace->id, $key, 'pi_test_123');

        $this->assertSame($first->additional_business_slots, $second->additional_business_slots);
        $this->assertSame(1, \App\Models\WorkspaceEntitlementTransition::where('payment_idempotency_key', $key)->count());
        Event::assertDispatchedTimes(WorkspaceAdditionalBusinessSlotsChanged::class, 1);
    }

    public function test_same_key_reused_for_a_different_logical_event_throws_conflict(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);
        $key = 'idem-key-' . uniqid();

        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $this->createNonAdmin(), $workspace->id, $key, 'pi_test_123');

        $this->expectException(PaymentAllocationIdempotencyConflictException::class);
        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 2, $this->createNonAdmin(), $workspace->id, $key, 'pi_test_456');
    }

    public function test_payment_evidence_recorded_for_another_workspace_is_rejected(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);
        $otherWorkspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $this->expectException(PaymentAllocationWorkspaceMismatchException::class);
        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $this->createNonAdmin(), $otherWorkspace->id, 'idem-key-' . uniqid(), 'pi_test_123');
    }

    public function test_empty_idempotency_key_is_rejected(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $this->expectException(InvalidPaymentAllocationEvidenceException::class);
        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $this->createNonAdmin(), $workspace->id, '', 'pi_test_123');
    }

    public function test_empty_provider_reference_is_rejected(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $this->expectException(InvalidPaymentAllocationEvidenceException::class);
        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $this->createNonAdmin(), $workspace->id, 'idem-key-' . uniqid(), '');
    }

    public function test_non_positive_delta_is_rejected(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $this->expectException(InvalidPaymentAllocationEvidenceException::class);
        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 0, $this->createNonAdmin(), $workspace->id, 'idem-key-' . uniqid(), 'pi_test_123');
    }

    public function test_complimentary_workspace_cannot_allocate_from_verified_payment(): void
    {
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, true, 0);

        $this->expectException(ComplimentaryWorkspaceCannotAllocatePaidSlotsException::class);
        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $this->createNonAdmin(), $workspace->id, 'idem-key-' . uniqid(), 'pi_test_123');
    }

    public function test_exceeding_tier_cap_is_rejected_even_for_a_verified_payment(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 2);

        $this->expectException(InvalidAdditionalBusinessSlotsException::class);
        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $this->createNonAdmin(), $workspace->id, 'idem-key-' . uniqid(), 'pi_test_123');
    }

    public function test_undefined_pricing_is_rejected_even_for_a_verified_payment(): void
    {
        $this->definePricingWithoutRatio(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $this->expectException(UndefinedPlanPricingException::class);
        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $this->createNonAdmin(), $workspace->id, 'idem-key-' . uniqid(), 'pi_test_123');
    }

    public function test_workspace_with_no_assignment_is_rejected(): void
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'Unassigned Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);

        $this->expectException(WorkspacePlanUnassignedException::class);
        app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $this->createNonAdmin(), $workspace->id, 'idem-key-' . uniqid(), 'pi_test_123');
    }

    public function test_repeated_calls_with_the_same_key_serialize_and_the_second_is_idempotent(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);
        $customerId = $this->createNonAdmin();
        $key = 'idem-key-' . uniqid();

        for ($i = 0; $i < 3; $i++) {
            $updated = app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment($workspace, 1, $customerId, $workspace->id, $key, 'pi_test_123');
        }

        $this->assertSame(1, $updated->additional_business_slots);
        $this->assertSame(1, \App\Models\WorkspaceEntitlementTransition::where('payment_idempotency_key', $key)->count());
    }

    public function test_set_additional_business_slots_own_tests_are_unaffected_by_this_amendment(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $updated = app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 2, $this->createAdmin());

        $this->assertSame(2, $updated->additional_business_slots);

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 1, $this->createNonAdmin());
    }
}
