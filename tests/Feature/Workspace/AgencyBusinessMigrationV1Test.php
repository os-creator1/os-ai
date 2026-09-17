<?php

namespace Tests\Feature\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\EffectivePayerResolver;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Library\Workspace\Migration\AgencyBusinessMigrationV1;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessPayerAssignment;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 10 — Agency Data Migration.
 *
 * Every legacy fixture in this file reproduces the ACTUAL current-main
 * shape a multi-Business Agency Workspace has today: every Business under
 * it shares the SAME customer_id (confirmed by mechanical inspection of
 * WorkspaceController::storeBusiness(), which creates a second Business in
 * an existing Workspace under Auth::user()->customer — never a distinct
 * real client User). There is no separate "sub-account" identity to
 * migrate to; the new Client Workspace is created under that same real
 * owner (see AgencyBusinessMigrationV1's own class docblock for why this
 * is what makes WorkspaceManager::reassignBusiness()'s owner-or-active-
 * admin-over-both-Workspaces authority requirement satisfiable honestly).
 */
class AgencyBusinessMigrationV1Test extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
    }

    private function migration(): AgencyBusinessMigrationV1
    {
        return app(AgencyBusinessMigrationV1::class);
    }

    private function platformOperator(): int
    {
        $user = User::create([
            'first_name' => 'Migration',
            'last_name' => 'Operator',
            'email' => 'migration-operator-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $role = Role::create(['name' => 'relationship-operator-' . uniqid('', true), 'status' => 1]);
        $role->permissions()->create(['name' => AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION]);
        $user->roles()->attach($role->id);

        return (int) $user->fresh()->id;
    }

    /**
     * @return array{0: Workspace, 1: Customer, 2: Business} agency workspace, its customer, its primary Business
     */
    private function legacyAgency(string $name = 'Northwind Agency'): array
    {
        $customer = $this->createCustomer();
        $agency = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($agency, WorkspacePlanTier::Agency);

        $primary = $this->addBusiness($customer, $agency, $name . ' Primary');
        $this->createPayerAssignment($primary, PayerType::Business);

        return [$agency->fresh(), $customer, $primary->fresh()];
    }

    /**
     * A second (or third...) Business under the SAME Agency Workspace and
     * the SAME customer_id — the exact legacy shape, never a distinct
     * owner.
     */
    private function legacyClientBusiness(Workspace $agency, Customer $customer, string $name, ?PayerType $payerType = PayerType::Workspace): Business
    {
        $business = $this->addBusiness($customer, $agency, $name);

        if ($payerType !== null) {
            $this->createPayerAssignment($business, $payerType);
        }

        return $business->fresh();
    }

    private function createPayerAssignment(Business $business, PayerType $payerType): BusinessPayerAssignment
    {
        return app(BusinessPayerAssignmentRepository::class)->create([
            'business_id' => $business->id,
            'payer_type' => $payerType,
        ]);
    }

    // ------------------------------------------------------------------
    // CANDIDATE DETECTION
    // ------------------------------------------------------------------

    public function test_an_agency_with_one_business_needs_no_migration(): void
    {
        [$agency] = $this->legacyAgency();

        $report = $this->migration()->preflight();

        $this->assertSame([], $report['agencies']);
    }

    public function test_an_agency_with_primary_and_one_client_is_detected(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        $report = $this->migration()->preflight([$agency->id]);

        $this->assertCount(1, $report['agencies']);
        $this->assertSame('ready', $report['agencies'][0]['status']);
        $this->assertCount(1, $report['agencies'][0]['businesses']);
        $this->assertSame($client->id, $report['agencies'][0]['businesses'][0]['business_id']);
    }

    public function test_an_agency_with_primary_and_several_clients_detects_every_client(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $this->legacyClientBusiness($agency, $customer, 'Beta Salon');
        $this->legacyClientBusiness($agency, $customer, 'Gamma Cafe');

        $report = $this->migration()->preflight([$agency->id]);

        $this->assertCount(3, $report['agencies'][0]['businesses']);
    }

    public function test_a_non_agency_workspace_is_ignored_even_with_multiple_businesses(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Core Co']);
        $this->assignTier($workspace, WorkspacePlanTier::Core);
        $this->addBusiness($customer, $workspace, 'Core Primary');
        $this->addBusiness($customer, $workspace, 'Core Secondary');

        $report = $this->migration()->preflight([$workspace->id]);

        $this->assertSame([], $report['agencies']);
    }

    public function test_an_already_migrated_client_is_not_reported_as_a_candidate_again(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $report = $this->migration()->preflight([$agency->id]);

        // With zero remaining non-primary Businesses, the Agency Workspace
        // no longer even qualifies as a "multi-Business Agency Workspace"
        // candidate at all — it correctly drops out of the report entirely,
        // the same way a never-multi-Business Agency does.
        $this->assertSame([], $report['agencies']);
    }

    public function test_ambiguous_primary_state_fails_preflight_for_that_agency_only(): void
    {
        [$agencyA, $customerA] = $this->legacyAgency('Agency A');
        $this->legacyClientBusiness($agencyA, $customerA, 'Client A');

        // A second Agency with a malformed, zero-primary state.
        $customerB = $this->createCustomer();
        $agencyB = $this->createWorkspace($customerB->user, ['name' => 'Agency B']);
        $this->assignTier($agencyB, WorkspacePlanTier::Agency);
        $b1 = $this->addBusiness($customerB, $agencyB, 'B One');
        $b2 = $this->addBusiness($customerB, $agencyB, 'B Two');
        DB::table('businesses')->whereIn('id', [$b1->id, $b2->id])->update(['is_primary' => false]);

        $report = $this->migration()->preflight([$agencyA->id, $agencyB->id]);

        $byId = collect($report['agencies'])->keyBy('agency_workspace_id');
        $this->assertSame('ready', $byId[$agencyA->id]['status']);
        $this->assertSame('blocked', $byId[$agencyB->id]['status']);
        $this->assertSame('ambiguous_primary_business', $byId[$agencyB->id]['reason']);
        $this->assertSame(0, $byId[$agencyB->id]['primary_business_count']);
    }

    // ------------------------------------------------------------------
    // PRESERVATION (§3)
    // ------------------------------------------------------------------

    public function test_the_business_id_and_uid_are_preserved(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $originalId = $client->id;
        $originalUid = $client->uid;

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $fresh = Business::find($originalId);
        $this->assertNotNull($fresh);
        $this->assertSame($originalUid, $fresh->uid);
        $this->assertSame('Alpha Dental', $fresh->name);
    }

    public function test_business_locations_survive_untouched(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $location = app(BusinessLocationRepository::class)->upsertPrimary($client, [
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame(1, DB::table('business_locations')->where('business_id', $client->id)->count());
        $this->assertTrue(DB::table('business_locations')->where('id', $location->id)->where('business_id', $client->id)->exists());
    }

    public function test_contacts_survive_untouched(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        $contactId = DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $customer->user_id,
            'business_id' => $client->id,
            'phone' => '15551234567',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $contact = DB::table('contacts')->find($contactId);
        $this->assertSame($client->id, (int) $contact->business_id);
    }

    public function test_conversations_survive_untouched(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        $chatBoxId = DB::table('chat_boxes')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $customer->user_id,
            'business_id' => $client->id,
            'from' => '15550000000',
            'to' => '15551234567',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $chatBox = DB::table('chat_boxes')->find($chatBoxId);
        $this->assertSame($client->id, (int) $chatBox->business_id);
    }

    public function test_website_records_survive_untouched(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        $websiteId = DB::table('websites')->insertGetId([
            'uid' => (string) Str::uuid(),
            'public_id' => (string) Str::uuid(),
            'business_id' => $client->id,
            'name' => 'Alpha Dental Site',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $website = DB::table('websites')->find($websiteId);
        $this->assertSame($client->id, (int) $website->business_id);
    }

    public function test_crm_opportunities_survive_untouched(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        $opportunityId = DB::table('opportunities')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $client->id,
            'worker_key' => 'test_worker',
            'type' => 'test_type',
            'fingerprint_version' => 1,
            'fingerprint' => hash('sha256', 'test-' . uniqid('', true)),
            'title' => 'Test Opportunity',
            'summary' => 'Test summary',
            'status' => 'open',
            'freshness' => 'current',
            'impact' => 1,
            'urgency' => 1,
            'effort' => 1,
            'confidence' => 0.5,
            'goal_relevance_rank' => 1,
            'evidence_freshness_rank' => 1,
            'priority_score' => 1,
            'scoring_version' => 1,
            'scored_at' => now(),
            'evidence' => json_encode([]),
            'occurrence_number' => 1,
            'last_confirmed_at' => now(),
            'first_detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $opportunity = DB::table('opportunities')->find($opportunityId);
        $this->assertSame($client->id, (int) $opportunity->business_id);
    }

    public function test_usage_wallet_survives_untouched(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        $currencyId = DB::table('currencies')->where('status', true)->value('id');

        if ($currencyId === null) {
            $currencyId = DB::table('currencies')->insertGetId([
                'uid' => (string) Str::uuid(),
                'name' => 'US Dollar',
                'code' => 'USD',
                'format' => '$%s',
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $walletId = DB::table('business_usage_wallets')->insertGetId([
            'business_id' => $client->id,
            'currency_id' => $currencyId,
            'spend_period_key' => now()->format('Y-m'),
            'spend_period_start_utc' => now()->startOfMonth(),
            'spend_period_end_utc' => now()->endOfMonth(),
            'recharge_period_key' => now()->format('Y-m'),
            'recharge_period_start_utc' => now()->startOfMonth(),
            'recharge_period_end_utc' => now()->endOfMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame(1, DB::table('business_usage_wallets')->where('business_id', $client->id)->count());
        $this->assertTrue(DB::table('business_usage_wallets')->where('id', $walletId)->exists());
    }

    public function test_historical_view_as_sessions_are_never_rewritten(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        // A real historical fact: the Agency owner once viewed this
        // Business AS the client, back when it was a same-Workspace
        // Business (the pre-migration, same-Workspace View As feature).
        $sessionId = DB::table('view_as_sessions')->insertGetId([
            'uid' => (string) Str::uuid(),
            'actor_user_id' => $customer->user_id,
            'workspace_id' => $agency->id,
            'business_id' => $client->id,
            'started_at' => now()->subDays(30),
            'expires_at' => now()->subDays(30)->addMinutes(30),
            'ended_at' => now()->subDays(30)->addMinutes(10),
            'refusals' => json_encode([]),
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $session = DB::table('view_as_sessions')->find($sessionId);
        $this->assertSame($agency->id, (int) $session->workspace_id, 'Historical View As must record where it ACTUALLY happened, never be rewritten to the new Client Workspace.');
        $this->assertSame($client->id, (int) $session->business_id);
    }

    public function test_the_agencys_own_primary_business_remains_in_the_agency_workspace(): void
    {
        [$agency, $customer, $primary] = $this->legacyAgency();
        $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame($agency->id, (int) $primary->fresh()->workspace_id);
    }

    // ------------------------------------------------------------------
    // MEMBERSHIP / ACCESS CLEANUP
    // ------------------------------------------------------------------

    public function test_business_scoped_membership_grants_for_the_moved_business_are_removed(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');

        $staffCustomer = $this->createCustomer();
        $membership = $this->createMembership($agency, $staffCustomer->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $this->assign($membership, $client);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame(
            0,
            DB::table('workspace_membership_businesses')->where('business_id', $client->id)->count(),
            'A grant scoped to the Agency Workspace membership has no meaning once the Business leaves it.'
        );
    }

    public function test_grants_for_a_different_still_unmigrated_business_remain_intact(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $migratedClient = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $otherClient = $this->legacyClientBusiness($agency, $customer, 'Beta Salon');

        $staffCustomer = $this->createCustomer();
        $membership = $this->createMembership($agency, $staffCustomer->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $this->assign($membership, $migratedClient);
        $this->assign($membership, $otherClient);

        // Only migrate the first candidate: filter is not exposed at the
        // Business level, so we assert the OTHER business's own grant
        // remains after a full-agency run touches both.
        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame(0, DB::table('workspace_membership_businesses')->where('business_id', $migratedClient->id)->count());
        $this->assertSame(0, DB::table('workspace_membership_businesses')->where('business_id', $otherClient->id)->count());

        // Re-run with a THIRD, never-migrated business to prove survival
        // for a grant untouched by any migration attempt.
        $untouchedClient = $this->legacyClientBusiness($agency, $customer, 'Gamma Cafe');
        $membership2 = $this->createMembership($agency, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $this->assign($membership2, $untouchedClient);

        // A run scoped to a DIFFERENT agency must never touch this one.
        [$otherAgency, $otherCustomer] = $this->legacyAgency('Other Agency');
        $this->legacyClientBusiness($otherAgency, $otherCustomer, 'Other Client');
        $this->migration()->run($this->platformOperator(), false, [$otherAgency->id]);

        $this->assertTrue(DB::table('workspace_membership_businesses')->where('business_id', $untouchedClient->id)->exists());
    }

    // ------------------------------------------------------------------
    // RELATIONSHIP
    // ------------------------------------------------------------------

    public function test_migration_creates_exactly_one_active_relationship_with_the_real_operator_recorded(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $operatorId = $this->platformOperator();

        $result = $this->migration()->run($operatorId, false, [$agency->id]);
        $businessResult = $result['agencies'][0]['businesses'][0];

        $this->assertSame('migrated', $businessResult['status']);
        $clientWorkspaceId = $businessResult['client_workspace_id'];

        $relationships = AgencyClientWorkspaceRelationship::where('client_workspace_id', $clientWorkspaceId)->get();
        $this->assertCount(1, $relationships);
        $relationship = $relationships->first();
        $this->assertSame($agency->id, (int) $relationship->agency_workspace_id);
        $this->assertSame(AgencyClientRelationshipStatus::Active, $relationship->status);
        $this->assertSame($operatorId, (int) $relationship->established_by_user_id);
        $this->assertNotSame((int) $agency->owner_user_id, (int) $relationship->established_by_user_id, 'The relationship must record the real operator, never the Agency owner (who took no such action).');
    }

    // ------------------------------------------------------------------
    // PAYER MATRIX (§5)
    // ------------------------------------------------------------------

    public function test_a_business_payer_is_left_unchanged(): void
    {
        [$agency, $customer, $primary] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Business);
        $assignmentBefore = BusinessPayerAssignment::where('business_id', $client->id)->first();

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $assignmentAfter = BusinessPayerAssignment::where('business_id', $client->id)->first();
        $this->assertSame(PayerType::Business, $assignmentAfter->payer_type);
        $this->assertNull($assignmentAfter->managing_agency_relationship_id);
        $this->assertSame($assignmentBefore->id, $assignmentAfter->id);
    }

    public function test_a_legacy_workspace_payer_converts_to_pre_consent_agency_rebill(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Workspace);

        $result = $this->migration()->run($this->platformOperator(), false, [$agency->id]);
        $businessResult = $result['agencies'][0]['businesses'][0];

        $assignment = BusinessPayerAssignment::where('business_id', $client->id)->first();
        $this->assertSame(PayerType::AgencyRebill, $assignment->payer_type);
        $this->assertNotNull($assignment->managing_agency_relationship_id);

        $relationship = AgencyClientWorkspaceRelationship::find($assignment->managing_agency_relationship_id);
        $this->assertSame($businessResult['client_workspace_id'], (int) $relationship->client_workspace_id);

        // Never fabricated.
        $this->assertNull($assignment->agency_rebill_consented_at);
        $this->assertNull($assignment->agency_rebill_consented_by_user_id);
    }

    public function test_a_pending_agency_rebill_business_fails_closed_for_a_new_charge(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Workspace);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $fresh = $client->fresh();
        $payer = app(EffectivePayerResolver::class)->resolveForPaidEffect($fresh);
        $refusal = app(EffectivePayerResolver::class)->paidEffectRefusal($fresh, $payer);

        $this->assertSame(PayerType::AgencyRebill, $payer->payerType);
        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_CONSENT_MISSING, $refusal, 'Zero new paid activity may occur while consent is NULL.');
    }

    public function test_the_real_agency_owner_can_subsequently_grant_consent_and_activate_funding(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Workspace);

        $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $fresh = $client->fresh();
        app(BillingProfileManager::class)->assignPayer($fresh, PayerType::AgencyRebill, (int) $agency->owner_user_id, 'Owner confirms AgencyRebill after migration.');

        $refreshed = $fresh->fresh();
        $assignment = BusinessPayerAssignment::where('business_id', $refreshed->id)->first();
        $this->assertNotNull($assignment->agency_rebill_consented_at);
        $this->assertSame((int) $agency->owner_user_id, (int) $assignment->agency_rebill_consented_by_user_id);

        $payer = app(EffectivePayerResolver::class)->resolveForPaidEffect($refreshed);
        $refusal = app(EffectivePayerResolver::class)->paidEffectRefusal($refreshed, $payer);
        $this->assertNull($refusal, 'Once the real owner consents, the paid-effect gate must clear.');
    }

    public function test_a_missing_payer_assignment_blocks_only_that_business(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', null);
        $otherClient = $this->legacyClientBusiness($agency, $customer, 'Beta Salon', PayerType::Business);

        $result = $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $byId = collect($result['agencies'][0]['businesses'])->keyBy('business_id');
        $this->assertSame('blocked', $byId[$client->id]['status']);
        $this->assertSame('missing_payer_assignment', $byId[$client->id]['reason']);
        $this->assertSame('migrated', $byId[$otherClient->id]['status']);

        // The blocked Business never moved.
        $this->assertSame($agency->id, (int) $client->fresh()->workspace_id);
    }

    // ------------------------------------------------------------------
    // LOCATION (§3/A4)
    // ------------------------------------------------------------------

    public function test_a_valid_primary_location_is_not_recreated(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $location = app(BusinessLocationRepository::class)->upsertPrimary($client, [
            'service_mode' => 'storefront',
            'country_code' => 'US',
            'name' => 'Original Primary',
        ]);

        $result = $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $this->assertFalse($result['agencies'][0]['businesses'][0]['primary_location_created']);
        $this->assertSame(1, DB::table('business_locations')->where('business_id', $client->id)->count());
        $this->assertTrue(DB::table('business_locations')->where('id', $location->id)->where('name', 'Original Primary')->exists());
    }

    public function test_a_business_with_no_primary_location_gets_one_created(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $this->assertSame(0, DB::table('business_locations')->where('business_id', $client->id)->count());

        $result = $this->migration()->run($this->platformOperator(), false, [$agency->id]);

        $this->assertTrue($result['agencies'][0]['businesses'][0]['primary_location_created']);
        $this->assertSame(1, DB::table('business_locations')->where('business_id', $client->id)->where('is_primary', true)->count());
    }

    // ------------------------------------------------------------------
    // FAILURE / ROLLBACK
    // ------------------------------------------------------------------

    private function assertNothingMigratedFor(Workspace $agency, Business $business): void
    {
        $this->assertSame($agency->id, (int) $business->fresh()->workspace_id);
        $this->assertSame(0, AgencyClientWorkspaceRelationship::where('agency_workspace_id', $agency->id)->count());
    }

    public function test_a_failure_during_plan_assignment_rolls_back_the_new_workspace_too(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $workspaceCountBefore = Workspace::count();

        // EntitlementManager is final (cannot be Mockery-mocked); a genuine,
        // unmocked failure is forced instead by removing the Core catalog
        // row assignFirstPlan() requires — the same real RuntimeException
        // production code throws when a tier's catalog is missing.
        DB::table('workspace_plan_catalog')->where('tier', WorkspacePlanTier::Core->value)->update(['is_active' => false]);

        $result = app(AgencyBusinessMigrationV1::class)->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame('failed', $result['agencies'][0]['businesses'][0]['status']);
        $this->assertSame($workspaceCountBefore, Workspace::count(), 'The Workspace created in step 1 must roll back with everything else.');
        $this->assertNothingMigratedFor($agency, $client);
    }

    public function test_a_failure_during_business_reassignment_rolls_back_the_workspace_and_plan(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $workspaceCountBefore = Workspace::count();
        $planAssignmentCountBefore = DB::table('workspace_plan_assignments')->count();

        $realWorkspaceManager = app(\App\Library\Workspace\WorkspaceManager::class);
        $manager = \Mockery::mock(\App\Library\Workspace\WorkspaceManager::class);
        $manager->shouldReceive('createWorkspace')
            ->andReturnUsing(fn (int $ownerId, string $name) => $realWorkspaceManager->createWorkspace($ownerId, $name));
        $manager->shouldReceive('reassignBusiness')->once()->andThrow(new \RuntimeException('Forced failure: reassignment.'));
        $this->app->instance(\App\Library\Workspace\WorkspaceManager::class, $manager);

        $result = app(AgencyBusinessMigrationV1::class)->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame('failed', $result['agencies'][0]['businesses'][0]['status']);
        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($planAssignmentCountBefore, DB::table('workspace_plan_assignments')->count());
        $this->assertNothingMigratedFor($agency, $client);
    }

    public function test_a_failure_during_relationship_creation_rolls_back_the_whole_cutover(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $workspaceCountBefore = Workspace::count();

        $relationshipManager = \Mockery::mock(AgencyClientRelationshipManager::class);
        $relationshipManager->shouldReceive('createForMigration')->once()->andThrow(new \RuntimeException('Forced failure: relationship.'));
        $this->app->instance(AgencyClientRelationshipManager::class, $relationshipManager);

        $result = app(AgencyBusinessMigrationV1::class)->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame('failed', $result['agencies'][0]['businesses'][0]['status']);
        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertNothingMigratedFor($agency, $client);
    }

    public function test_a_failure_during_payer_conversion_rolls_back_everything_including_the_relationship(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Workspace);
        $workspaceCountBefore = Workspace::count();
        $assignmentBefore = BusinessPayerAssignment::where('business_id', $client->id)->first();

        $payerRepository = \Mockery::mock(BusinessPayerAssignmentRepository::class);
        $payerRepository->shouldReceive('findByBusinessId')->andReturnUsing(
            fn (int $id) => BusinessPayerAssignment::where('business_id', $id)->first()
        );
        $payerRepository->shouldReceive('findForUpdateByBusinessId')->andReturnUsing(
            fn (int $id) => BusinessPayerAssignment::where('business_id', $id)->lockForUpdate()->first()
        );
        $payerRepository->shouldReceive('update')->once()->andThrow(new \RuntimeException('Forced failure: payer conversion.'));
        $this->app->instance(BusinessPayerAssignmentRepository::class, $payerRepository);

        $result = app(AgencyBusinessMigrationV1::class)->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame('failed', $result['agencies'][0]['businesses'][0]['status']);
        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertNothingMigratedFor($agency, $client);

        $assignmentAfter = BusinessPayerAssignment::where('business_id', $client->id)->first();
        $this->assertSame($assignmentBefore->payer_type, $assignmentAfter->payer_type);
        $this->assertNull($assignmentAfter->managing_agency_relationship_id);
    }

    // ------------------------------------------------------------------
    // DRY RUN
    // ------------------------------------------------------------------

    public function test_dry_run_reports_exact_candidates_and_makes_zero_writes(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();
        $client1 = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Workspace);
        $client2 = $this->legacyClientBusiness($agency, $customer, 'Beta Salon', PayerType::Business);
        $relationshipCountBefore = AgencyClientWorkspaceRelationship::count();
        $planAssignmentCountBefore = DB::table('workspace_plan_assignments')->count();

        $result = $this->migration()->run($this->platformOperator(), true, [$agency->id]);

        $this->assertTrue($result['dry_run']);
        $businesses = collect($result['agencies'][0]['businesses'])->keyBy('business_id');
        $this->assertSame('convert_to_pending_agency_rebill', $businesses[$client1->id]['payer_action']);
        $this->assertSame('unchanged', $businesses[$client2->id]['payer_action']);

        $this->assertSame(Business::count(), $businessCountBefore + 2, 'Only the fixture businesses themselves were created; the dry run created none.');
        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($relationshipCountBefore, AgencyClientWorkspaceRelationship::count());
        $this->assertSame($planAssignmentCountBefore, DB::table('workspace_plan_assignments')->count());
        $this->assertSame($agency->id, (int) $client1->fresh()->workspace_id);
        $this->assertSame($agency->id, (int) $client2->fresh()->workspace_id);
    }

    // ------------------------------------------------------------------
    // IDEMPOTENCY / RESUMABILITY
    // ------------------------------------------------------------------

    public function test_a_second_run_does_not_duplicate_completed_work(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Workspace);
        $operatorId = $this->platformOperator();

        $first = $this->migration()->run($operatorId, false, [$agency->id]);
        $this->assertSame('migrated', $first['agencies'][0]['businesses'][0]['status']);
        $clientWorkspaceId = $first['agencies'][0]['businesses'][0]['client_workspace_id'];

        $workspaceCountAfterFirst = Workspace::count();
        $relationshipCountAfterFirst = AgencyClientWorkspaceRelationship::count();

        $second = $this->migration()->run($operatorId, false, [$agency->id]);

        // The Agency Workspace now holds only its primary Business, so it
        // no longer qualifies as a candidate at all — the correct,
        // idempotent "nothing left to do" outcome.
        $this->assertSame([], $second['agencies']);
        $this->assertSame(Workspace::count(), $workspaceCountAfterFirst);
        $this->assertSame(AgencyClientWorkspaceRelationship::count(), $relationshipCountAfterFirst);
        $this->assertSame($clientWorkspaceId, (int) $client->fresh()->workspace_id);

        $assignment = BusinessPayerAssignment::where('business_id', $client->id)->first();
        $this->assertNull($assignment->agency_rebill_consented_at, 'A rerun must never reset or otherwise touch consent state.');
    }

    public function test_resuming_after_a_partial_agency_run_only_processes_the_remainder(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $migrated = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $stillPending = $this->legacyClientBusiness($agency, $customer, 'Beta Salon');
        $operatorId = $this->platformOperator();

        // Simulate a prior partial run: only the first Business was migrated.
        $result = $this->migration()->run($operatorId, false, [$agency->id]);
        $this->assertSame('migrated', collect($result['agencies'][0]['businesses'])->firstWhere('business_id', $migrated->id)['status']);
        $this->assertSame('migrated', collect($result['agencies'][0]['businesses'])->firstWhere('business_id', $stillPending->id)['status']);

        // Both were actually migrated by the single run() call above (it
        // processes every candidate); to prove genuine resumption after a
        // TRUE partial failure, force the FIRST Business's relationship
        // creation to fail (deterministic by call order — Businesses are
        // processed in ascending id), then rerun and confirm only that one
        // is retried, while the successfully-migrated sibling is not.
        [$agencyB, $customerB] = $this->legacyAgency('Agency B');
        $businessOne = $this->legacyClientBusiness($agencyB, $customerB, 'One');
        $businessTwo = $this->legacyClientBusiness($agencyB, $customerB, 'Two');

        $realRelationshipManager = app(AgencyClientRelationshipManager::class);
        $callCount = 0;
        $relationshipManager = \Mockery::mock(AgencyClientRelationshipManager::class);
        $relationshipManager->shouldReceive('createForMigration')
            ->andReturnUsing(function (int $operator, Workspace $agencyWs, Workspace $clientWs) use (&$callCount, $realRelationshipManager) {
                $callCount++;

                if ($callCount === 1) {
                    throw new \RuntimeException('Forced failure on first Business.');
                }

                return $realRelationshipManager->createForMigration($operator, $agencyWs, $clientWs);
            });
        $this->app->instance(AgencyClientRelationshipManager::class, $relationshipManager);

        $firstRun = app(AgencyBusinessMigrationV1::class)->run($operatorId, false, [$agencyB->id]);
        $statuses = collect($firstRun['agencies'][0]['businesses'])->pluck('status', 'business_id');
        $this->assertSame('failed', $statuses[$businessOne->id]);
        $this->assertSame('migrated', $statuses[$businessTwo->id]);
        $this->assertSame($agencyB->id, (int) $businessOne->fresh()->workspace_id);

        // Restore the real manager, rerun: only businessOne should be
        // (re)processed — businessTwo is no longer a candidate at all.
        $this->app->forgetInstance(AgencyClientRelationshipManager::class);
        $this->app->forgetInstance(AgencyBusinessMigrationV1::class);

        $secondRun = app(AgencyBusinessMigrationV1::class)->run($operatorId, false, [$agencyB->id]);
        $remainingBusinessIds = collect($secondRun['agencies'][0]['businesses'])->pluck('business_id')->all();
        $this->assertSame([$businessOne->id], $remainingBusinessIds, 'Only the previously-failed Business remains a candidate on rerun.');
        $this->assertSame('migrated', $secondRun['agencies'][0]['businesses'][0]['status']);
    }
}
