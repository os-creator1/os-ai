<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Usage\BusinessPayerChanged;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Library\Usage\BillingProfileManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 (contract §12.4, §18 S-7; T-PAYER-2) —
 * billing responsibility is managed by the Agency: the Workspace owner or
 * an active Agency-wide Admin, inside an Agency-tier Workspace, may set
 * either payer. A Business user (the direct owner), a Business-scoped
 * Admin, Staff, a stranger, and every Core/Growth actor are refused for a
 * real change. Reaffirming the current payer is accepted only from the
 * Agency manager or the current payer and writes nothing.
 *
 * Supersedes the RFC-005 M2 §7 matrix this file used to encode (owner ->
 * 'workspace', direct owner -> 'business').
 */
class PayerConsentAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    /**
     * @return array{business: \App\Models\Business, ownerId: int, directOwnerId: int, agencyAdminId: int, scopedAdminId: int, staffId: int, unrelatedId: int}
     */
    private function agencyScenario(): array
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $ownerId = (int) $owner->user_id;

        $client = $this->createCustomer();
        $business = $this->addBusiness($client, $workspace, 'Client Bakery');
        $directOwnerId = (int) $client->user_id;

        $agencyAdmin = $this->createCustomer();
        $this->member($workspace, $agencyAdmin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);

        $scopedAdmin = $this->createCustomer();
        $this->assign($this->member($workspace, $scopedAdmin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected), $business);

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $unrelated = $this->createCustomer();

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        return [
            'business' => $business->fresh(),
            'ownerId' => $ownerId,
            'directOwnerId' => $directOwnerId,
            'agencyAdminId' => (int) $agencyAdmin->user_id,
            'scopedAdminId' => (int) $scopedAdmin->user_id,
            'staffId' => (int) $staff->user_id,
            'unrelatedId' => (int) $unrelated->user_id,
        ];
    }

    public function test_agency_owner_may_set_either_payer(): void
    {
        $s = $this->agencyScenario();
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $s['business']->id, 'payer_type' => 'business']);

        $toAgency = app(BillingProfileManager::class)->assignPayer($s['business'], PayerType::Workspace, $s['ownerId'], 'Agency takes over billing.');
        $this->assertTrue($toAgency['changed']);
        $this->assertSame(PayerType::Workspace, $toAgency['assignment']->payer_type);

        $toClient = app(BillingProfileManager::class)->assignPayer($s['business'], PayerType::Business, $s['ownerId'], 'Client pays again.');
        $this->assertTrue($toClient['changed']);
        $this->assertSame(PayerType::Business, $toClient['assignment']->payer_type);
        $this->assertSame(2, DB::table('business_payer_transitions')->where('business_id', $s['business']->id)->count());
    }

    public function test_agency_wide_admin_may_set_either_payer(): void
    {
        $s = $this->agencyScenario();

        $this->assertTrue(app(BillingProfileManager::class)->assignPayer($s['business'], PayerType::Workspace, $s['agencyAdminId'], 'Admin.')['changed']);
        $this->assertTrue(app(BillingProfileManager::class)->assignPayer($s['business'], PayerType::Business, $s['agencyAdminId'], 'Admin.')['changed']);
    }

    public function test_direct_business_owner_may_never_change_billing_responsibility(): void
    {
        $s = $this->agencyScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Workspace, $s['directOwnerId'], 'Denied.');
    }

    public function test_business_scoped_admin_may_never_change_billing_responsibility(): void
    {
        $s = $this->agencyScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Workspace, $s['scopedAdminId'], 'Denied.');
    }

    public function test_staff_may_never_change_billing_responsibility(): void
    {
        $s = $this->agencyScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Workspace, $s['staffId'], 'Denied.');
    }

    public function test_unrelated_user_may_never_change_billing_responsibility(): void
    {
        $s = $this->agencyScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Workspace, $s['unrelatedId'], 'Denied.');
    }

    public function test_agency_rebill_is_never_a_valid_target(): void
    {
        $s = $this->agencyScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::AgencyRebill, $s['ownerId'], 'Denied.');
    }

    public function test_a_core_or_growth_owner_cannot_manufacture_a_payer_change(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, $business] = $this->tenant($tier, 'Harbor Lane Studios', 'Harbor Lane');
            app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

            try {
                app(BillingProfileManager::class)->changePayer($business, PayerType::Business, (int) $owner->user_id, 'Denied.');
                $this->fail($tier->value . ': a Core/Growth owner must not change the payer.');
            } catch (UnauthorizedPayerAssignmentException) {
                $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);
            }
        }
    }

    public function test_reaffirming_the_current_payer_is_a_true_no_op_for_the_current_payer_and_refused_for_others(): void
    {
        $s = $this->agencyScenario();
        Event::fake([BusinessPayerChanged::class]);

        $before = DB::table('business_payer_assignments')->where('business_id', $s['business']->id)->first();

        // The client currently pays: the client (current payer) and the Agency owner may reaffirm; nothing is written.
        foreach ([$s['directOwnerId'], $s['ownerId'], $s['agencyAdminId']] as $actorId) {
            $outcome = app(BillingProfileManager::class)->assignPayer($s['business'], PayerType::Business, $actorId, 'Reaffirm.');
            $this->assertFalse($outcome['changed']);
        }

        $after = DB::table('business_payer_assignments')->where('business_id', $s['business']->id)->first();
        $this->assertEquals((array) $before, (array) $after, 'The assignment row is byte-for-byte unchanged, updated_at included.');
        $this->assertSame(0, DB::table('business_payer_transitions')->where('business_id', $s['business']->id)->count());
        Event::assertNotDispatched(BusinessPayerChanged::class);

        foreach ([$s['scopedAdminId'], $s['staffId'], $s['unrelatedId']] as $actorId) {
            try {
                app(BillingProfileManager::class)->assignPayer($s['business'], PayerType::Business, $actorId, 'Reaffirm.');
                $this->fail("Actor {$actorId} must not be able to submit the payer at all.");
            } catch (UnauthorizedPayerAssignmentException) {
                $this->assertTrue(true);
            }
        }
    }
}
