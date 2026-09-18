<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Library\Usage\EffectivePayerResolver;
use App\Library\Workspace\Migration\NonAgencyBusinessSplitV1;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessPayerAssignment;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\Feature\Workspace\Support\CreatesLegacyMultiBusinessFixtures;
use Tests\Feature\Workspace\Support\PreContract13HistoricalTestCase;

/**
 * Implementation Contract 12 — Non-Agency Multi-Business Migration.
 *
 * Mirrors Contract 10's own test shape at this simpler scope (Contract 12
 * §13's own instruction): reassignment preserves every business_id-keyed
 * table, no Agency relationship is ever created, and `workspace`-type
 * payer assignments are provably unaffected in row content — the
 * economic rule ("the Workspace containing this Business pays") follows
 * the Business's current workspace_id automatically, never a rewritten
 * payer row.
 */
/**
 * Every legacy multi-Business Workspace fixture in this file runs against
 * PreContract13HistoricalTestCase's own disposable database — the real
 * pre-Contract-13 schema, where businesses.workspace_id carries no unique
 * constraint — never the shared ultimatesms_testing-family database,
 * which Contract 13 permanently enforces one Business per Workspace on.
 */
class NonAgencyBusinessSplitV1Test extends PreContract13HistoricalTestCase
{
    use CreatesCustomerContextFixtures;
    use CreatesLegacyMultiBusinessFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
    }

    private function migration(): NonAgencyBusinessSplitV1
    {
        return app(NonAgencyBusinessSplitV1::class);
    }

    private function operator(): int
    {
        $user = User::create([
            'first_name' => 'Migration',
            'last_name' => 'Operator',
            'email' => 'nonagency-migration-operator-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        return (int) $user->fresh()->id;
    }

    /**
     * @return array{0: Workspace, 1: Customer, 2: Business} workspace, its customer, its primary Business
     */
    private function nonAgencyWorkspace(WorkspacePlanTier $tier, string $name = 'Northwind'): array
    {
        [$customer, $primary, $workspace] = $this->tenant($tier, $name . ' Primary', $name);
        $this->createPayerAssignment($primary, PayerType::Business);

        return [$workspace, $customer, $primary];
    }

    private function secondBusiness(Workspace $workspace, Customer $customer, string $name, ?PayerType $payerType = PayerType::Workspace): Business
    {
        $business = $this->legacyBusiness($customer, $workspace, $name);

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
    // 1. CORE WORKSPACE, 2 BUSINESSES
    // ------------------------------------------------------------------

    public function test_a_core_workspace_with_two_businesses_splits_one_retains_one_moves(): void
    {
        [$workspace, $customer, $primary] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Alpha');
        $moved = $this->secondBusiness($workspace, $customer, 'Alpha Secondary');
        $originalUid = $moved->uid;

        $result = $this->migration()->run($this->operator(), false, [$workspace->id]);
        $workspaceReport = $result['workspaces'][0];

        $this->assertSame('migrated', $workspaceReport['status']);
        $this->assertCount(1, $workspaceReport['businesses']);
        $businessResult = $workspaceReport['businesses'][0];
        $this->assertSame($moved->id, $businessResult['business_id']);
        $this->assertSame('migrated', $businessResult['status']);

        // Retained business stays; moved business's identity is preserved.
        $this->assertSame($workspace->id, (int) $primary->fresh()->workspace_id);
        $freshMoved = $moved->fresh();
        $this->assertSame($moved->id, $freshMoved->id);
        $this->assertSame($originalUid, $freshMoved->uid);
        $this->assertNotSame($workspace->id, (int) $freshMoved->workspace_id);
        $this->assertSame($businessResult['new_workspace_id'], (int) $freshMoved->workspace_id);

        // No Agency relationship of any kind was created.
        $this->assertSame(0, AgencyClientWorkspaceRelationship::count());
    }

    // ------------------------------------------------------------------
    // 2. GROWTH WORKSPACE, 2 BUSINESSES — exact Phase 0 plan/lifecycle proof
    // ------------------------------------------------------------------

    public function test_a_growth_workspace_split_gives_the_new_workspace_a_fresh_complimentary_core_plan(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Growth, 'Beta');
        $moved = $this->secondBusiness($workspace, $customer, 'Beta Secondary');

        $sourcePlanBefore = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();

        $result = $this->migration()->run($this->operator(), false, [$workspace->id]);
        $businessResult = $result['workspaces'][0]['businesses'][0];
        $newWorkspaceId = $businessResult['new_workspace_id'];

        $newPlan = DB::table('workspace_plan_assignments')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_assignments.workspace_plan_catalog_id')
            ->where('workspace_plan_assignments.workspace_id', $newWorkspaceId)
            ->select('workspace_plan_catalog.tier', 'workspace_plan_assignments.*')
            ->first();

        $this->assertNotNull($newPlan, 'The new split Workspace must have a usable plan — never none.');
        $this->assertSame('core', $newPlan->tier, 'Phase 0: every new split Workspace gets Core, mirroring Contract 10\'s own precedent — never inherited Growth.');
        $this->assertSame(1, (int) $newPlan->is_complimentary, 'Zero billing obligation — no duplicated/fabricated subscription.');
        $this->assertSame(0, (int) $newPlan->additional_business_slots);
        $this->assertNull($newPlan->trial_ends_at, 'No fabricated trial timestamp.');
        $this->assertNull($newPlan->grace_started_at, 'A fresh Workspace never starts pre-locked/graced.');
        $this->assertNull($newPlan->locked_at);
        $this->assertSame('active', $newPlan->status);

        // The SOURCE Workspace's own Growth plan assignment row is
        // completely untouched — Growth never silently becomes Core.
        $sourcePlanAfter = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertEquals($sourcePlanBefore, $sourcePlanAfter);
    }

    // ------------------------------------------------------------------
    // 3. SOURCE WORKSPACE, 3 BUSINESSES — one atomic batch, two new Workspaces
    // ------------------------------------------------------------------

    public function test_a_workspace_with_three_businesses_produces_two_split_workspaces_in_one_batch(): void
    {
        [$workspace, $customer, $primary] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Gamma');
        $second = $this->secondBusiness($workspace, $customer, 'Gamma Two', PayerType::Business);
        $third = $this->secondBusiness($workspace, $customer, 'Gamma Three', PayerType::Business);

        $result = $this->migration()->run($this->operator(), false, [$workspace->id]);
        $workspaceReport = $result['workspaces'][0];

        $this->assertSame('migrated', $workspaceReport['status']);
        $this->assertCount(2, $workspaceReport['businesses']);

        $newWorkspaceIds = collect($workspaceReport['businesses'])->pluck('new_workspace_id')->all();
        $this->assertCount(2, array_unique($newWorkspaceIds), 'Each moved Business gets its OWN independent new Workspace.');

        $this->assertSame($workspace->id, (int) $primary->fresh()->workspace_id);
        $this->assertSame(0, Business::where('workspace_id', $workspace->id)->where('is_primary', false)->count());
        $this->assertNotSame((int) $second->fresh()->workspace_id, (int) $third->fresh()->workspace_id);
    }

    // ------------------------------------------------------------------
    // 4. FORCED FAILURE ON SECOND SIBLING — whole source Workspace rolls back
    // ------------------------------------------------------------------

    public function test_a_failure_migrating_the_second_of_two_siblings_rolls_back_the_whole_source_workspace(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Delta');
        $first = $this->secondBusiness($workspace, $customer, 'Delta Two', PayerType::Business);
        $second = $this->secondBusiness($workspace, $customer, 'Delta Three', PayerType::Business);
        $workspaceCountBefore = Workspace::count();

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

        $result = app(NonAgencyBusinessSplitV1::class)->run($this->operator(), false, [$workspace->id]);

        $this->assertSame('failed', $result['workspaces'][0]['status']);
        $this->assertSame([], $result['workspaces'][0]['businesses']);
        $this->assertSame($workspace->id, (int) $first->fresh()->workspace_id, 'The already-succeeded-so-far first sibling must roll back too.');
        $this->assertSame($workspace->id, (int) $second->fresh()->workspace_id);
        $this->assertSame($workspaceCountBefore, Workspace::count(), 'Zero new Workspaces survive, including the one already created for the first sibling before the failure.');
    }

    // ------------------------------------------------------------------
    // 5/6. PAYER BEHAVIOR
    // ------------------------------------------------------------------

    public function test_a_workspace_type_payer_row_is_preserved_and_effective_payer_becomes_the_new_workspace(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Epsilon');
        $moved = $this->secondBusiness($workspace, $customer, 'Epsilon Two', PayerType::Workspace);
        $assignmentBefore = BusinessPayerAssignment::where('business_id', $moved->id)->first();

        $result = $this->migration()->run($this->operator(), false, [$workspace->id]);
        $businessResult = $result['workspaces'][0]['businesses'][0];

        $assignmentAfter = BusinessPayerAssignment::where('business_id', $moved->id)->first();
        $this->assertSame($assignmentBefore->id, $assignmentAfter->id, 'The payer assignment row is never rewritten by this migration.');
        $this->assertSame(PayerType::Workspace, $assignmentAfter->payer_type);
        $this->assertNull($assignmentAfter->managing_agency_relationship_id);

        $effective = app(EffectivePayerResolver::class)->resolve($moved->fresh());
        $this->assertSame(PayerType::Workspace, $effective->payerType);
        $this->assertSame($businessResult['new_workspace_id'], $effective->providerCustomerWorkspaceId, 'The effective payer Workspace must be the Business\'s NEW Workspace, never the original one.');
    }

    public function test_a_business_type_payer_is_preserved_unchanged(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Zeta');
        $moved = $this->secondBusiness($workspace, $customer, 'Zeta Two', PayerType::Business);
        $assignmentBefore = BusinessPayerAssignment::where('business_id', $moved->id)->first();

        $this->migration()->run($this->operator(), false, [$workspace->id]);

        $assignmentAfter = BusinessPayerAssignment::where('business_id', $moved->id)->first();
        $this->assertSame($assignmentBefore->id, $assignmentAfter->id);
        $this->assertSame(PayerType::Business, $assignmentAfter->payer_type);
    }

    // ------------------------------------------------------------------
    // 7. UNEXPECTED AGENCY_REBILL — fail closed, zero writes
    // ------------------------------------------------------------------

    public function test_an_unexpected_agency_rebill_payer_blocks_the_entire_source_workspace_with_zero_writes(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Eta');
        $validSibling = $this->secondBusiness($workspace, $customer, 'Eta Valid', PayerType::Business);
        $unexpected = $this->secondBusiness($workspace, $customer, 'Eta Unexpected', PayerType::AgencyRebill);
        $workspaceCountBefore = Workspace::count();

        $result = $this->migration()->run($this->operator(), false, [$workspace->id]);
        $workspaceReport = $result['workspaces'][0];

        $this->assertSame('blocked', $workspaceReport['status']);
        $this->assertSame('unresolved_business_payer', $workspaceReport['reason']);
        $blockedIds = collect($workspaceReport['blocked_businesses'])->pluck('business_id')->all();
        $this->assertSame([$unexpected->id], $blockedIds);

        $this->assertSame($workspace->id, (int) $validSibling->fresh()->workspace_id, 'Zero writes for the WHOLE source Workspace, including the otherwise-valid sibling.');
        $this->assertSame($workspace->id, (int) $unexpected->fresh()->workspace_id);
        $this->assertSame($workspaceCountBefore, Workspace::count());
    }

    // ------------------------------------------------------------------
    // 8. LOCATION PRESERVATION / REPAIR
    // ------------------------------------------------------------------

    public function test_an_existing_primary_location_is_preserved_not_recreated(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Theta');
        $moved = $this->secondBusiness($workspace, $customer, 'Theta Two');
        $location = app(BusinessLocationRepository::class)->upsertPrimary($moved, [
            'service_mode' => 'storefront',
            'country_code' => 'US',
            'name' => 'Original Primary',
        ]);

        $result = $this->migration()->run($this->operator(), false, [$workspace->id]);

        $this->assertFalse($result['workspaces'][0]['businesses'][0]['primary_location_created']);
        $this->assertSame(1, DB::table('business_locations')->where('business_id', $moved->id)->count());
        $this->assertTrue(DB::table('business_locations')->where('id', $location->id)->where('name', 'Original Primary')->exists());
    }

    public function test_a_business_with_no_primary_location_gets_one_created(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Iota');
        $moved = $this->secondBusiness($workspace, $customer, 'Iota Two');
        $this->assertSame(0, DB::table('business_locations')->where('business_id', $moved->id)->count());

        $result = $this->migration()->run($this->operator(), false, [$workspace->id]);

        $this->assertTrue($result['workspaces'][0]['businesses'][0]['primary_location_created']);
        $this->assertSame(1, DB::table('business_locations')->where('business_id', $moved->id)->where('is_primary', true)->count());
    }

    // ------------------------------------------------------------------
    // 9. MEMBERSHIP-BUSINESS STALE GRANT CLEANUP
    // ------------------------------------------------------------------

    public function test_stale_membership_business_grants_are_removed(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Kappa');
        $moved = $this->secondBusiness($workspace, $customer, 'Kappa Two');

        $staffCustomer = $this->createCustomer();
        $membership = $this->createMembership($workspace, $staffCustomer->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $this->assign($membership, $moved);

        $this->migration()->run($this->operator(), false, [$workspace->id]);

        $this->assertSame(
            0,
            DB::table('workspace_membership_businesses')->where('business_id', $moved->id)->count(),
            'A grant scoped to the source Workspace membership has no meaning once the Business leaves it.'
        );
    }

    // ------------------------------------------------------------------
    // 10. RERUN / RESUMABILITY
    // ------------------------------------------------------------------

    public function test_rerunning_does_not_duplicate_completed_work(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Lambda');
        $moved = $this->secondBusiness($workspace, $customer, 'Lambda Two');
        $operatorId = $this->operator();

        $first = $this->migration()->run($operatorId, false, [$workspace->id]);
        $this->assertSame('migrated', $first['workspaces'][0]['status']);
        $newWorkspaceId = $first['workspaces'][0]['businesses'][0]['new_workspace_id'];

        $workspaceCountAfterFirst = Workspace::count();

        $second = $this->migration()->run($operatorId, false, [$workspace->id]);

        // The source Workspace now holds only its primary Business, so it
        // no longer qualifies as a candidate at all.
        $this->assertSame([], $second['workspaces']);
        $this->assertSame($workspaceCountAfterFirst, Workspace::count());
        $this->assertSame($newWorkspaceId, (int) $moved->fresh()->workspace_id);
    }

    // ------------------------------------------------------------------
    // 11. PRESERVATION INVENTORY (smallest high-value subset)
    // ------------------------------------------------------------------

    public function test_the_moved_businesss_id_uid_and_business_keyed_records_survive(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Mu');
        $moved = $this->secondBusiness($workspace, $customer, 'Mu Two');
        $originalId = $moved->id;
        $originalUid = $moved->uid;

        $contactId = DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $customer->user_id,
            'business_id' => $moved->id,
            'phone' => '15551234567',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $walletId = DB::table('business_usage_wallets')->insertGetId([
            'business_id' => $moved->id,
            'currency_id' => $this->seedCurrencyId(),
            'spend_period_key' => now()->format('Y-m'),
            'spend_period_start_utc' => now()->startOfMonth(),
            'spend_period_end_utc' => now()->endOfMonth(),
            'recharge_period_key' => now()->format('Y-m'),
            'recharge_period_start_utc' => now()->startOfMonth(),
            'recharge_period_end_utc' => now()->endOfMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->run($this->operator(), false, [$workspace->id]);

        $fresh = Business::find($originalId);
        $this->assertNotNull($fresh);
        $this->assertSame($originalUid, $fresh->uid);
        $this->assertSame('Mu Two', $fresh->name);

        $contact = DB::table('contacts')->find($contactId);
        $this->assertSame($moved->id, (int) $contact->business_id);

        $this->assertSame(1, DB::table('business_usage_wallets')->where('id', $walletId)->where('business_id', $moved->id)->count());
    }

    private function seedCurrencyId(): int
    {
        $currencyId = DB::table('currencies')->where('status', true)->value('id');

        if ($currencyId !== null) {
            return $currencyId;
        }

        return DB::table('currencies')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => 'US Dollar',
            'code' => 'USD',
            'format' => '$%s',
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // OPERATOR COMMAND WRAPPER (review correction) — proves ONLY
    // wrapper-level concerns; the migration algorithm itself is already
    // proven above via the direct service tests.
    // ------------------------------------------------------------------

    public function test_command_default_and_preflight_mode_are_zero_write(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Nu');
        $this->secondBusiness($workspace, $customer, 'Nu Two');

        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();

        $this->artisan('workspaces:migrate-nonagency-multibusiness')->assertExitCode(0);
        $this->artisan('workspaces:migrate-nonagency-multibusiness', ['--preflight' => true])->assertExitCode(0);

        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($businessCountBefore, Business::count());
    }

    public function test_command_dry_run_is_zero_write(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Xi');
        $this->secondBusiness($workspace, $customer, 'Xi Two');

        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();

        $this->artisan('workspaces:migrate-nonagency-multibusiness', [
            '--dry-run' => true,
            '--operator' => $this->operator(),
        ])->assertExitCode(0);

        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($businessCountBefore, Business::count());
    }

    public function test_command_execute_reaches_the_real_service_and_migrates_a_seeded_workspace(): void
    {
        [$workspace, $customer, $primary] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Omicron');
        $moved = $this->secondBusiness($workspace, $customer, 'Omicron Two');

        $this->artisan('workspaces:migrate-nonagency-multibusiness', [
            '--execute' => true,
            '--operator' => $this->operator(),
            '--workspace' => [$workspace->uid],
        ])->assertExitCode(0);

        $this->assertSame($workspace->id, (int) $primary->fresh()->workspace_id);
        $this->assertNotSame($workspace->id, (int) $moved->fresh()->workspace_id);
        $this->assertSame(0, Business::where('workspace_id', $workspace->id)->where('is_primary', false)->count());
    }

    public function test_command_rejects_both_dry_run_and_execute_together(): void
    {
        $this->artisan('workspaces:migrate-nonagency-multibusiness', [
            '--dry-run' => true,
            '--execute' => true,
            '--operator' => $this->operator(),
        ])->assertExitCode(1);
    }

    public function test_command_fails_clearly_without_an_operator_for_write_modes(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Pi');
        $moved = $this->secondBusiness($workspace, $customer, 'Pi Two');

        $this->artisan('workspaces:migrate-nonagency-multibusiness', ['--dry-run' => true])->assertExitCode(1);
        $this->artisan('workspaces:migrate-nonagency-multibusiness', ['--execute' => true])->assertExitCode(1);

        $this->assertSame($workspace->id, (int) $moved->fresh()->workspace_id, 'Refusing for a missing --operator must happen before any write.');
    }

    public function test_command_fails_on_an_invalid_workspace_uid(): void
    {
        $this->artisan('workspaces:migrate-nonagency-multibusiness', [
            '--preflight' => true,
            '--workspace' => ['does-not-exist-uid'],
        ])->assertExitCode(1);
    }

    public function test_command_exits_with_failure_for_a_blocked_workspace(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Rho');
        $this->secondBusiness($workspace, $customer, 'Rho Unexpected', PayerType::AgencyRebill);

        $this->artisan('workspaces:migrate-nonagency-multibusiness', [
            '--execute' => true,
            '--operator' => $this->operator(),
            '--workspace' => [$workspace->uid],
        ])->assertExitCode(1);
    }

    public function test_command_exits_with_failure_on_verification_failure(): void
    {
        [$workspace, $customer] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Sigma');
        $this->secondBusiness($workspace, $customer, 'Sigma Two');

        $partialRepository = Mockery::mock(
            \App\Repositories\Eloquent\EloquentAgencyClientWorkspaceRelationshipRepository::class,
            [new AgencyClientWorkspaceRelationship()],
        )->makePartial();
        $partialRepository->shouldReceive('findActiveForClientWorkspace')->andReturn(new AgencyClientWorkspaceRelationship());
        $this->app->instance(\App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository::class, $partialRepository);

        $this->artisan('workspaces:migrate-nonagency-multibusiness', [
            '--execute' => true,
            '--operator' => $this->operator(),
            '--workspace' => [$workspace->uid],
        ])->assertExitCode(1);
    }

    public function test_command_returns_success_for_a_clean_no_candidate_run(): void
    {
        $this->nonAgencyWorkspace(WorkspacePlanTier::Core, 'Tau');

        $this->artisan('workspaces:migrate-nonagency-multibusiness')->assertExitCode(0);
    }
}
