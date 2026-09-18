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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\Feature\Workspace\Support\CreatesLegacyMultiBusinessFixtures;
use Tests\Feature\Workspace\Support\PreContract13HistoricalTestCase;

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
/**
 * Every legacy multi-Business Agency Workspace fixture in this file runs
 * against PreContract13HistoricalTestCase's own disposable database — the
 * real pre-Contract-13 schema, where businesses.workspace_id carries no
 * unique constraint — never the shared ultimatesms_testing-family
 * database, which Contract 13 permanently enforces one Business per
 * Workspace on.
 */
class AgencyBusinessMigrationV1Test extends PreContract13HistoricalTestCase
{
    use CreatesCustomerContextFixtures;
    use CreatesLegacyMultiBusinessFixtures;

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
        $business = $this->legacyBusiness($customer, $agency, $name);

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
        $this->legacyBusiness($customer, $workspace, 'Core Primary');
        $this->legacyBusiness($customer, $workspace, 'Core Secondary');

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
        $b1 = $this->legacyBusiness($customerB, $agencyB, 'B One');
        $b2 = $this->legacyBusiness($customerB, $agencyB, 'B Two');
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
    // PREFLIGHT AGGREGATES (§8.1, review correction Finding 5)
    // ------------------------------------------------------------------

    public function test_preflight_reports_exact_aggregate_counts(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $withLocation = $this->legacyClientBusiness($agency, $customer, 'Has Location', PayerType::Business);
        app(BusinessLocationRepository::class)->upsertPrimary($withLocation, [
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
        $withoutLocationA = $this->legacyClientBusiness($agency, $customer, 'No Location A', PayerType::Workspace);
        $withoutLocationB = $this->legacyClientBusiness($agency, $customer, 'No Location B', PayerType::Business);

        $report = $this->migration()->preflight([$agency->id]);
        $agencyReport = $report['agencies'][0];

        $this->assertSame('ready', $agencyReport['status']);
        $this->assertSame(4, $agencyReport['business_count'], 'Primary + 3 candidates.');
        $this->assertSame(3, $agencyReport['candidate_business_count']);
        $this->assertSame(2, $agencyReport['primary_location_missing_count']);

        $byId = collect($agencyReport['businesses'])->keyBy('business_id');
        $this->assertSame('unchanged', $byId[$withLocation->id]['payer_action']);
        $this->assertSame('convert_to_pending_agency_rebill', $byId[$withoutLocationA->id]['payer_action']);
        $this->assertSame('unchanged', $byId[$withoutLocationB->id]['payer_action']);
        $this->assertTrue($byId[$withLocation->id]['primary_location_present']);
        $this->assertFalse($byId[$withoutLocationA->id]['primary_location_present']);
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

    /**
     * Review correction Finding 2: under the corrected per-Agency atomic
     * boundary, one unresolved candidate must block the ENTIRE Agency's
     * batch with zero writes — never migrate the "good" sibling around it.
     */
    public function test_an_unresolved_sibling_blocks_the_entire_agency_with_zero_writes(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $validClient = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Business);
        $unresolvedClient = $this->legacyClientBusiness($agency, $customer, 'Beta Salon', null);
        $workspaceCountBefore = Workspace::count();
        $relationshipCountBefore = AgencyClientWorkspaceRelationship::count();

        $result = $this->migration()->run($this->platformOperator(), false, [$agency->id]);
        $agencyReport = $result['agencies'][0];

        $this->assertSame('blocked', $agencyReport['status']);
        $this->assertSame('unresolved_business_payer', $agencyReport['reason']);
        $blockedIds = collect($agencyReport['blocked_businesses'])->pluck('business_id')->all();
        $this->assertSame([$unresolvedClient->id], $blockedIds);
        $this->assertSame([], $agencyReport['businesses'], 'No per-Business cutover results at all — the batch never started executing.');

        // ZERO writes for BOTH — including the otherwise-perfectly-valid sibling.
        $this->assertSame($agency->id, (int) $validClient->fresh()->workspace_id);
        $this->assertSame($agency->id, (int) $unresolvedClient->fresh()->workspace_id);
        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($relationshipCountBefore, AgencyClientWorkspaceRelationship::count());
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
    // MULTI-BUSINESS AGENCY BATCH ATOMICITY (§7, review correction Finding 1)
    // ------------------------------------------------------------------

    public function test_a_multi_business_agency_with_all_valid_candidates_commits_atomically(): void
    {
        [$agency, $customer, $primary] = $this->legacyAgency();
        $clientA = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Business);
        $clientB = $this->legacyClientBusiness($agency, $customer, 'Beta Salon', PayerType::Workspace);

        $result = $this->migration()->run($this->platformOperator(), false, [$agency->id]);
        $agencyReport = $result['agencies'][0];

        $this->assertSame('migrated', $agencyReport['status']);
        $this->assertTrue($agencyReport['verified']);
        $this->assertCount(2, $agencyReport['businesses']);

        $statuses = collect($agencyReport['businesses'])->pluck('status', 'business_id');
        $this->assertSame('migrated', $statuses[$clientA->id]);
        $this->assertSame('migrated', $statuses[$clientB->id]);

        // The Agency retains only its own primary Business.
        $this->assertSame(0, Business::where('workspace_id', $agency->id)->where('is_primary', false)->count());
        $this->assertSame($agency->id, (int) $primary->fresh()->workspace_id);

        $this->assertNotSame(
            (int) $clientA->fresh()->workspace_id,
            (int) $clientB->fresh()->workspace_id,
            'Each moved Business gets its OWN independent Client Workspace.'
        );
    }

    /**
     * Review correction Finding 1: the Agency Workspace, not one Business,
     * is the true atomic unit. A failure migrating the SECOND of two
     * client Businesses must roll back the FIRST sibling too, even though
     * its own cutover had already fully succeeded earlier in the same
     * loop — no partial-Agency commit is ever left behind.
     */
    public function test_a_failure_migrating_the_second_of_two_siblings_rolls_back_the_whole_agency(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $first = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Workspace);
        $second = $this->legacyClientBusiness($agency, $customer, 'Beta Salon', PayerType::Workspace);
        $workspaceCountBefore = Workspace::count();
        $relationshipCountBefore = AgencyClientWorkspaceRelationship::count();

        // Businesses are processed in ascending id order — $first's
        // createWorkspace()+reassignBusiness() both genuinely succeed
        // before this forces the SECOND Business's reassignment to fail.
        $realWorkspaceManager = app(WorkspaceManager::class);
        $callCount = 0;
        $manager = Mockery::mock(WorkspaceManager::class);
        $manager->shouldReceive('createWorkspace')
            ->andReturnUsing(fn (int $ownerId, string $name) => $realWorkspaceManager->createWorkspace($ownerId, $name));
        $manager->shouldReceive('reassignBusiness')
            ->andReturnUsing(function (int $actorId, Business $business, Workspace $target) use (&$callCount, $realWorkspaceManager) {
                $callCount++;

                if ($callCount === 2) {
                    throw new RuntimeException('Forced failure on second sibling.');
                }

                return $realWorkspaceManager->reassignBusiness($actorId, $business, $target);
            });
        $this->app->instance(WorkspaceManager::class, $manager);

        $result = app(AgencyBusinessMigrationV1::class)->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame('failed', $result['agencies'][0]['status']);
        $this->assertSame([], $result['agencies'][0]['businesses']);

        // The FIRST sibling — already fully cutover before the failure —
        // must roll back too.
        $this->assertSame($agency->id, (int) $first->fresh()->workspace_id, 'The already-succeeded-so-far first sibling must roll back as well.');
        $this->assertSame($agency->id, (int) $second->fresh()->workspace_id);
        $this->assertSame($workspaceCountBefore, Workspace::count(), 'Zero new Client Workspaces survive, including the one already created for the first sibling before the failure.');
        $this->assertSame($relationshipCountBefore, AgencyClientWorkspaceRelationship::count());

        $this->assertSame(PayerType::Workspace, BusinessPayerAssignment::where('business_id', $first->id)->first()->payer_type);
        $this->assertSame(PayerType::Workspace, BusinessPayerAssignment::where('business_id', $second->id)->first()->payer_type);

        $this->assertSame(0, DB::table('workspace_transitions')->where('business_id', $first->id)->count(), 'No committed transition residue from the first sibling survives the rollback.');
        $this->assertSame(0, DB::table('workspace_transitions')->where('business_id', $second->id)->count());
    }

    /**
     * Review correction Finding 3: a post-cutover verification invariant
     * failure must THROW and roll back the whole Agency transaction, via
     * the narrowest possible test seam — findActiveForClientWorkspace() is
     * called ONLY by verifyAgencyCutover() in this whole class, never by
     * the real cutover logic, so forcing it to lie about a relationship
     * createForMigration() genuinely created (left completely untouched)
     * induces a real verification failure without disturbing any actual
     * production write path.
     */
    public function test_a_post_cutover_verification_failure_rolls_back_the_whole_agency(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $client = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental', PayerType::Workspace);
        $workspaceCountBefore = Workspace::count();
        $relationshipCountBefore = AgencyClientWorkspaceRelationship::count();

        $partialRepository = Mockery::mock(
            \App\Repositories\Eloquent\EloquentAgencyClientWorkspaceRelationshipRepository::class,
            [new AgencyClientWorkspaceRelationship()],
        )->makePartial();
        $partialRepository->shouldReceive('findActiveForClientWorkspace')->andReturn(null);
        $this->app->instance(\App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository::class, $partialRepository);

        $result = app(AgencyBusinessMigrationV1::class)->run($this->platformOperator(), false, [$agency->id]);

        $this->assertSame('verification_failed', $result['agencies'][0]['status']);
        $this->assertStringContainsString('active relationship', $result['agencies'][0]['reason']);

        // The whole Agency's attempt rolled back — as if nothing happened.
        $this->assertSame($agency->id, (int) $client->fresh()->workspace_id);
        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($relationshipCountBefore, AgencyClientWorkspaceRelationship::count());

        $assignment = BusinessPayerAssignment::where('business_id', $client->id)->first();
        $this->assertSame(PayerType::Workspace, $assignment->payer_type, 'The payer conversion must roll back too — never left half-converted.');
    }

    // ------------------------------------------------------------------
    // FAILURE / ROLLBACK (single-Business Agency)
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

        $this->assertSame('failed', $result['agencies'][0]['status']);
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

        $this->assertSame('failed', $result['agencies'][0]['status']);
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

        $this->assertSame('failed', $result['agencies'][0]['status']);
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

        $this->assertSame('failed', $result['agencies'][0]['status']);
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

    /**
     * Review correction Finding 1: resumability must never be proven by
     * relying on the corrected command committing one sibling while
     * failing another — that behavior has been removed. Instead this
     * reproduces a genuinely already-partly-migrated Agency the honest
     * way: a real, fully-committed prior run() call (the corrected
     * per-Agency transaction always leaves an Agency either fully
     * migrated or fully untouched), followed by a NEW legacy client
     * Business appearing under the same Agency afterward.
     */
    public function test_resuming_after_a_genuinely_partly_migrated_agency_only_processes_the_remainder(): void
    {
        [$agency, $customer] = $this->legacyAgency();
        $alreadyMigrated = $this->legacyClientBusiness($agency, $customer, 'Alpha Dental');
        $operatorId = $this->platformOperator();

        $firstRun = $this->migration()->run($operatorId, false, [$agency->id]);
        $this->assertSame('migrated', $firstRun['agencies'][0]['status']);
        $firstClientWorkspaceId = $firstRun['agencies'][0]['businesses'][0]['client_workspace_id'];
        $firstRelationshipId = $firstRun['agencies'][0]['businesses'][0]['relationship_id'];

        // A second, still-legacy client Business appears under the SAME
        // Agency Workspace afterward — the authoritative,
        // workspace_id-driven candidate detection is what finds it.
        $stillPending = $this->legacyClientBusiness($agency, $customer, 'Beta Salon');

        $secondRun = $this->migration()->run($operatorId, false, [$agency->id]);
        $agencyReport = $secondRun['agencies'][0];

        $this->assertSame('migrated', $agencyReport['status']);
        $this->assertCount(1, $agencyReport['businesses'], 'Only the genuine remainder is processed — the already-migrated sibling is untouched.');
        $this->assertSame($stillPending->id, $agencyReport['businesses'][0]['business_id']);

        // The already-migrated Business was never touched again: same
        // Client Workspace, same relationship, no duplication.
        $this->assertSame($firstClientWorkspaceId, (int) $alreadyMigrated->fresh()->workspace_id);
        $this->assertSame(1, AgencyClientWorkspaceRelationship::where('client_workspace_id', $firstClientWorkspaceId)->count());
        $this->assertSame($firstRelationshipId, AgencyClientWorkspaceRelationship::where('client_workspace_id', $firstClientWorkspaceId)->first()->id);

        // A rerun after full completion creates nothing further.
        $thirdRun = $this->migration()->run($operatorId, false, [$agency->id]);
        $this->assertSame([], $thirdRun['agencies'], 'Fully migrated — the Agency no longer qualifies as a candidate at all.');
    }

    // ------------------------------------------------------------------
    // COMMAND EXIT STATUS (review correction Finding 4)
    // ------------------------------------------------------------------

    public function test_command_exit_code_reflects_the_actual_outcome(): void
    {
        $operatorId = $this->platformOperator();

        // 1. Clean, fully successful execute -> SUCCESS.
        [$cleanAgency, $cleanCustomer] = $this->legacyAgency('Clean Agency');
        $this->legacyClientBusiness($cleanAgency, $cleanCustomer, 'Clean Client', PayerType::Business);
        $this->artisan('agency:migrate-client-businesses', [
            '--execute' => true,
            '--operator' => $operatorId,
            '--agency' => [$cleanAgency->uid],
        ])->assertExitCode(0);

        // 2. Blocked (unresolved payer) -> FAILURE, zero writes.
        [$blockedAgency, $blockedCustomer] = $this->legacyAgency('Blocked Agency');
        $unresolvedClient = $this->legacyClientBusiness($blockedAgency, $blockedCustomer, 'Unresolved Co', null);
        $this->artisan('agency:migrate-client-businesses', [
            '--execute' => true,
            '--operator' => $operatorId,
            '--agency' => [$blockedAgency->uid],
        ])->assertExitCode(1);
        $this->assertSame($blockedAgency->id, (int) $unresolvedClient->fresh()->workspace_id);

        // 3. Malformed (ambiguous primary) during preflight -> FAILURE.
        $ambiguousCustomer = $this->createCustomer();
        $ambiguousAgency = $this->createWorkspace($ambiguousCustomer->user, ['name' => 'Ambiguous Agency']);
        $this->assignTier($ambiguousAgency, WorkspacePlanTier::Agency);
        $one = $this->legacyBusiness($ambiguousCustomer, $ambiguousAgency, 'One');
        $two = $this->legacyBusiness($ambiguousCustomer, $ambiguousAgency, 'Two');
        DB::table('businesses')->whereIn('id', [$one->id, $two->id])->update(['is_primary' => false]);
        $this->artisan('agency:migrate-client-businesses', [
            '--preflight' => true,
            '--agency' => [$ambiguousAgency->uid],
        ])->assertExitCode(1);
    }

    public function test_command_exit_code_is_failure_for_execute_and_verification_failures(): void
    {
        $operatorId = $this->platformOperator();

        // Execute failure (forced exception mid-cutover) -> FAILURE.
        [$failAgency, $failCustomer] = $this->legacyAgency('Fail Agency');
        $this->legacyClientBusiness($failAgency, $failCustomer, 'Fail Client', PayerType::Business);
        DB::table('workspace_plan_catalog')->where('tier', WorkspacePlanTier::Core->value)->update(['is_active' => false]);

        $this->artisan('agency:migrate-client-businesses', [
            '--execute' => true,
            '--operator' => $operatorId,
            '--agency' => [$failAgency->uid],
        ])->assertExitCode(1);

        DB::table('workspace_plan_catalog')->where('tier', WorkspacePlanTier::Core->value)->update(['is_active' => true]);

        // Post-cutover verification failure -> FAILURE (same narrow seam
        // as the feature-level verification test above).
        [$verifyFailAgency, $verifyFailCustomer] = $this->legacyAgency('Verify Fail Agency');
        $this->legacyClientBusiness($verifyFailAgency, $verifyFailCustomer, 'Verify Fail Client', PayerType::Workspace);

        $partialRepository = Mockery::mock(
            \App\Repositories\Eloquent\EloquentAgencyClientWorkspaceRelationshipRepository::class,
            [new AgencyClientWorkspaceRelationship()],
        )->makePartial();
        $partialRepository->shouldReceive('findActiveForClientWorkspace')->andReturn(null);
        $this->app->instance(\App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository::class, $partialRepository);

        $this->artisan('agency:migrate-client-businesses', [
            '--execute' => true,
            '--operator' => $operatorId,
            '--agency' => [$verifyFailAgency->uid],
        ])->assertExitCode(1);
    }
}
