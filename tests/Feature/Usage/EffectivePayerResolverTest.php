<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Exceptions\Usage\AgencyRebillRelationshipInvalidException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\EffectivePayerResolver;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Usage\Concerns\AgencyRebillFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 09 §5.3 — EffectivePayerResolver, the one canonical
 * answer to "who pays". The payer truth matrix, every fail-closed AgencyRebill
 * case, the locking re-resolution, and the spend-time gate.
 */
class EffectivePayerResolverTest extends TestCase
{
    use AgencyRebillFixtures;
    use RefreshDatabase;

    private function resolver(): EffectivePayerResolver
    {
        return app(EffectivePayerResolver::class);
    }

    // =====================================================================
    // Payer truth matrix
    // =====================================================================

    public function test_a_workspace_payer_resolves_to_the_businesss_own_workspace_provider_customer(): void
    {
        [, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->setPayer($business, PayerType::Workspace);

        $payer = $this->resolver()->resolve($business);

        $this->assertSame(PayerType::Workspace, $payer->payerType);
        $this->assertSame((int) $workspace->id, $payer->providerCustomerWorkspaceId);
        $this->assertNull($payer->providerCustomerBusinessId);
        $this->assertNull($payer->managingAgencyRelationshipId);
    }

    public function test_a_business_payer_resolves_to_the_business_provider_customer(): void
    {
        [, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->setPayer($business, PayerType::Business);

        $payer = $this->resolver()->resolve($business);

        $this->assertSame(PayerType::Business, $payer->payerType);
        $this->assertSame((int) $business->id, $payer->providerCustomerBusinessId);
        $this->assertNull($payer->providerCustomerWorkspaceId);
    }

    public function test_an_agency_rebill_payer_resolves_to_the_managing_agency_workspace_never_the_client(): void
    {
        $m = $this->rebilledClient();

        $payer = $this->resolver()->resolve($m['business']);

        $this->assertSame(PayerType::AgencyRebill, $payer->payerType);
        $this->assertSame((int) $m['agency']->id, $payer->providerCustomerWorkspaceId);
        $this->assertNotSame((int) $m['client']->id, $payer->providerCustomerWorkspaceId);
        $this->assertNull($payer->providerCustomerBusinessId);
        $this->assertSame((int) $m['relationship']->id, $payer->managingAgencyRelationshipId);
        $this->assertTrue($payer->hasAgencyRebillStandingConsent());
    }

    public function test_a_business_with_no_assignment_row_defaults_to_the_workspace_payer(): void
    {
        [, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        DB::table('business_payer_assignments')->where('business_id', $business->id)->delete();

        $payer = $this->resolver()->resolve($business);

        $this->assertSame(PayerType::Workspace, $payer->payerType);
        $this->assertSame((int) $workspace->id, $payer->providerCustomerWorkspaceId);
        $this->assertNull($this->resolver()->assignedPayerType($business));
    }

    // =====================================================================
    // AgencyRebill fails closed — never a fallback to the client
    // =====================================================================

    public function test_agency_rebill_without_a_relationship_id_fails_closed(): void
    {
        [, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        DB::table('business_payer_assignments')->where('business_id', $business->id)->update([
            'payer_type' => PayerType::AgencyRebill->value,
            'managing_agency_relationship_id' => null,
            'agency_rebill_consented_at' => now(),
        ]);

        $this->expectException(AgencyRebillRelationshipInvalidException::class);
        $this->resolver()->resolve($business);
    }

    public function test_agency_rebill_with_a_terminated_relationship_fails_closed(): void
    {
        $m = $this->rebilledClient();
        app(AgencyClientRelationshipManager::class)->terminate((int) $m['agencyOwner']->user_id, $m['relationship'], 'Client moved on.');

        // The stored payer is NOT silently flipped.
        $this->assertSame(PayerType::AgencyRebill->value, $this->assignmentRow($m['business'])->payer_type);

        $this->expectException(AgencyRebillRelationshipInvalidException::class);
        $this->resolver()->resolve($m['business']);
    }

    public function test_agency_rebill_pointing_at_another_clients_relationship_fails_closed(): void
    {
        $m = $this->rebilledClient();
        $other = $this->managedClient(WorkspacePlanTier::Growth, ' Two');

        // Forged: this Business's assignment names a relationship that
        // targets a DIFFERENT Client Workspace.
        DB::table('business_payer_assignments')->where('business_id', $m['business']->id)
            ->update(['managing_agency_relationship_id' => $other['relationship']->id]);

        $this->expectException(AgencyRebillRelationshipInvalidException::class);
        $this->resolver()->resolve($m['business']);
    }

    public function test_agency_rebill_through_a_self_referencing_relationship_fails_closed(): void
    {
        [, $business, $client] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $selfLink = app(AgencyClientWorkspaceRelationshipRepository::class)->create([
            'agency_workspace_id' => $client->id,
            'client_workspace_id' => $client->id,
            'status' => AgencyClientRelationshipStatus::Active,
            'established_by_user_id' => $this->platformAdminId(),
            'established_at' => now(),
        ]);
        DB::table('business_payer_assignments')->where('business_id', $business->id)->update([
            'payer_type' => PayerType::AgencyRebill->value,
            'managing_agency_relationship_id' => $selfLink->id,
            'agency_rebill_consented_at' => now(),
        ]);

        // Would otherwise resolve to the CLIENT Workspace's provider customer.
        $this->expectException(AgencyRebillRelationshipInvalidException::class);
        $this->resolver()->resolve($business);
    }

    public function test_agency_rebill_whose_agency_workspace_left_the_agency_tier_fails_closed(): void
    {
        $m = $this->rebilledClient();
        $this->assertSame(PayerType::AgencyRebill, $this->resolver()->resolve($m['business'])->payerType);

        // Contract 01 §6: the still-Active relationship row is never proof of
        // current Agency entitlement.
        app(EntitlementManager::class)->changePlan($m['agency'], WorkspacePlanTier::Growth, $this->platformAdminId(), 'Downgraded.');
        $this->assertSame('active', $m['relationship']->fresh()->status->value);

        try {
            DB::transaction(fn () => $this->resolver()->resolveForPaidEffect($m['business']));
            $this->fail('A downgraded Agency must not resolve as the paying Agency under the lock.');
        } catch (AgencyRebillRelationshipInvalidException) {
        }

        $this->expectException(AgencyRebillRelationshipInvalidException::class);
        $this->resolver()->resolve($m['business']);
    }

    public function test_the_stored_payer_type_stays_readable_for_display_while_resolution_fails_closed(): void
    {
        $m = $this->rebilledClient();
        app(AgencyClientRelationshipManager::class)->terminate((int) $m['agencyOwner']->user_id, $m['relationship'], 'Ended.');

        $this->assertSame(PayerType::AgencyRebill, $this->resolver()->payerTypeOf($m['business']));
        $this->assertSame(PayerType::AgencyRebill, $this->resolver()->assignedPayerType($m['business']));
    }

    // =====================================================================
    // Locking re-resolution (§7)
    // =====================================================================

    public function test_paid_effect_resolution_matches_plain_resolution_and_locks_agency_rebill_rows(): void
    {
        $m = $this->rebilledClient();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $locked = DB::transaction(fn () => $this->resolver()->resolveForPaidEffect($m['business']));

        $this->assertTrue($locked->fundsFromSameSourceAs($this->resolver()->resolve($m['business'])));
        $this->assertNotEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'business_payer_assignments') && str_contains($sql, 'for update')));
        $this->assertNotEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'agency_client_workspace_relationships') && str_contains($sql, 'for update')));
    }

    /**
     * Review correction: a plain consistent read of the assignment inside a
     * paid effect would fix the REPEATABLE READ snapshot before reserve() /
     * claimAutoRechargeAdmissionUnderLock() lock the Workspace controls row,
     * so the Workspace aggregate sums would miss concurrent commits. Every
     * payer type therefore reads its assignment with a locking read (and
     * only AgencyRebill also locks a relationship).
     */
    #[DataProvider('nonAgencyPayerTypes')]
    public function test_paid_effect_resolution_never_takes_a_consistent_read_of_the_assignment(PayerType $payerType): void
    {
        [, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->setPayer($business, $payerType);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $payer = DB::transaction(fn () => $this->resolver()->resolveForPaidEffect($business));

        $this->assertSame($payerType, $payer->payerType);
        $assignmentReads = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'business_payer_assignments')));
        $this->assertNotEmpty($assignmentReads);
        foreach ($assignmentReads as $sql) {
            $this->assertStringContainsString('for update', $sql);
        }
        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'agency_client_workspace_relationships')));
    }

    public static function nonAgencyPayerTypes(): array
    {
        return ['workspace payer' => [PayerType::Workspace], 'business payer' => [PayerType::Business]];
    }

    // =====================================================================
    // The spend-time gate (§11)
    // =====================================================================

    public function test_the_gate_adds_nothing_for_business_and_workspace_payers_even_when_the_account_is_locked(): void
    {
        [, $workspacePaid, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->setPayer($workspacePaid, PayerType::Workspace);
        $this->putWorkspaceInto($workspace, 'locked');

        $this->assertNull($this->resolver()->paidEffectRefusal($workspacePaid, $this->resolver()->resolve($workspacePaid)));
    }

    public function test_the_gate_refuses_agency_rebill_without_standing_consent(): void
    {
        $m = $this->rebilledClient();
        DB::table('business_payer_assignments')->where('business_id', $m['business']->id)
            ->update(['agency_rebill_consented_at' => null, 'agency_rebill_consented_by_user_id' => null]);

        $payer = $this->resolver()->resolve($m['business']);

        $this->assertFalse($payer->hasAgencyRebillStandingConsent());
        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_CONSENT_MISSING, $this->resolver()->paidEffectRefusal($m['business'], $payer));
    }

    public static function lockedStates(): array
    {
        return [
            'client locked' => ['client', 'locked'],
            'client inactive' => ['client', 'inactive'],
            'client suspended' => ['client', 'suspended'],
            'agency locked' => ['agency', 'locked'],
            'agency inactive' => ['agency', 'inactive'],
            'agency suspended' => ['agency', 'suspended'],
        ];
    }

    #[DataProvider('lockedStates')]
    public function test_the_gate_refuses_agency_rebill_for_every_locked_effective_access_state(string $side, string $state): void
    {
        $m = $this->rebilledClient();
        $this->putWorkspaceInto($m[$side], $state);

        $refusal = $this->resolver()->paidEffectRefusal($m['business'], $this->resolver()->resolve($m['business']));

        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_ACCOUNT_ACCESS_LOCKED, $refusal);
    }

    public static function graceSides(): array
    {
        return ['client grace' => ['client'], 'agency grace' => ['agency']];
    }

    #[DataProvider('graceSides')]
    public function test_grace_stays_usable_for_agency_rebill(string $side): void
    {
        $m = $this->rebilledClient();
        $this->putWorkspaceInto($m[$side], 'grace');

        $this->assertNull($this->resolver()->paidEffectRefusal($m['business'], $this->resolver()->resolve($m['business'])));
    }

    public function test_the_gate_allows_agency_rebill_with_consent_and_usable_access(): void
    {
        $m = $this->rebilledClient();

        $this->assertNull($this->resolver()->paidEffectRefusal($m['business'], $this->resolver()->resolve($m['business'])));
    }
}
