<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Usage\BusinessPayerChanged;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Usage\Concerns\AgencyRebillFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 09 §6 — the AgencyRebill money-authority matrix,
 * row by row, through BillingProfileManager (the single human authority seam)
 * and every manager that consumes it.
 *
 * Every refusal is asserted to write nothing: a refused grant leaves the
 * assignment and the audit exactly as they were.
 */
class AgencyRebillAuthorityTest extends TestCase
{
    use AgencyRebillFixtures;
    use RefreshDatabase;

    private function billing(): BillingProfileManager
    {
        return app(BillingProfileManager::class);
    }

    /**
     * Every actor who must NOT hold AgencyRebill authority over $m's Business.
     *
     * @return array<string, int>
     */
    private function refusedActors(array $m): array
    {
        [$unrelatedAgencyOwner] = $this->agencyAccount('Unrelated Agency');

        return [
            'agency admin (all scope)' => (int) $this->memberOf($m['agency'], WorkspaceMembershipRole::Admin)->user_id,
            'agency staff' => (int) $this->memberOf($m['agency'], WorkspaceMembershipRole::Staff)->user_id,
            'client owner' => (int) $m['clientOwner']->user_id,
            'client admin' => (int) $this->memberOf($m['client'], WorkspaceMembershipRole::Admin)->user_id,
            'client staff' => (int) $this->memberOf($m['client'], WorkspaceMembershipRole::Staff)->user_id,
            'platform administrator' => $this->platformAdminUserId(),
            'owner of an unrelated agency' => (int) $unrelatedAgencyOwner->user_id,
        ];
    }

    // =====================================================================
    // Granting AgencyRebill (assignment + standing consent)
    // =====================================================================

    public function test_the_managing_agency_owner_grants_agency_rebill_with_standing_consent_and_audit(): void
    {
        Event::fake([BusinessPayerChanged::class]);
        $m = $this->managedClient();
        $agencyOwnerId = (int) $m['agencyOwner']->user_id;

        $outcome = $this->billing()->assignPayer($m['business'], PayerType::AgencyRebill, $agencyOwnerId, 'Agency funds this client.');

        $this->assertTrue($outcome['changed']);
        $row = $this->assignmentRow($m['business']);
        $this->assertSame(PayerType::AgencyRebill->value, $row->payer_type);
        $this->assertSame((int) $m['relationship']->id, (int) $row->managing_agency_relationship_id);
        $this->assertNotNull($row->agency_rebill_consented_at);
        $this->assertSame($agencyOwnerId, (int) $row->agency_rebill_consented_by_user_id);

        $transition = DB::table('business_payer_transitions')->where('business_id', $m['business']->id)->orderByDesc('id')->first();
        $this->assertSame(PayerType::AgencyRebill->value, $transition->to_payer_type);
        $this->assertSame((int) $m['relationship']->id, (int) $transition->managing_agency_relationship_id);
        $this->assertSame(BillingProfileManager::AGENCY_REBILL_CONSENT_GRANTED, $transition->agency_rebill_consent);
        $this->assertSame($agencyOwnerId, (int) $transition->actor_user_id);
        $this->assertSame('Agency funds this client.', $transition->reason);

        Event::assertDispatched(BusinessPayerChanged::class);
    }

    public function test_every_other_actor_is_refused_the_grant_and_nothing_is_written(): void
    {
        $m = $this->managedClient();
        $before = (array) $this->assignmentRow($m['business']);
        $transitionsBefore = DB::table('business_payer_transitions')->count();

        foreach ($this->refusedActors($m) as $label => $actorId) {
            try {
                $this->billing()->assignPayer($m['business'], PayerType::AgencyRebill, $actorId, 'Should be refused.');
                $this->fail("{$label} must not be able to grant AgencyRebill.");
            } catch (UnauthorizedPayerAssignmentException) {
                // expected
            }
        }

        $this->assertSame($before, (array) $this->assignmentRow($m['business']));
        $this->assertSame($transitionsBefore, DB::table('business_payer_transitions')->count());
    }

    public function test_an_agency_owner_without_a_relationship_is_refused_before_ownership_is_even_considered(): void
    {
        [$agencyOwner] = $this->agencyAccount('Lonely Agency');
        [, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth, 'Unmanaged Bakery', 'Unmanaged');

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        $this->billing()->assignPayer($business, PayerType::AgencyRebill, (int) $agencyOwner->user_id, 'No relationship.');
    }

    public function test_a_terminated_relationship_cannot_be_used_to_grant(): void
    {
        $m = $this->managedClient();
        app(AgencyClientRelationshipManager::class)->terminate((int) $m['agencyOwner']->user_id, $m['relationship'], 'Ended.');

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        $this->billing()->assignPayer($m['business'], PayerType::AgencyRebill, (int) $m['agencyOwner']->user_id, 'Too late.');
    }

    public function test_an_agency_workspace_no_longer_on_the_agency_tier_cannot_grant(): void
    {
        $m = $this->managedClient();
        app(EntitlementManager::class)->changePlan($m['agency'], WorkspacePlanTier::Growth, $this->platformAdminId(), 'Downgraded.');

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        $this->billing()->assignPayer($m['business'], PayerType::AgencyRebill, (int) $m['agencyOwner']->user_id, 'Downgraded agency.');
    }

    public function test_a_relationship_terminated_between_the_authority_check_and_the_write_is_refused(): void
    {
        $m = $this->managedClient();
        $relationshipId = (int) $m['relationship']->id;
        $terminated = false;

        // §7: the authority check reads the relationship once; terminate it
        // right after that read, inside the same transaction, before the
        // locking re-read that must catch it.
        DB::listen(function ($query) use ($relationshipId, &$terminated): void {
            $sql = strtolower($query->sql);

            if (! $terminated && str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'agency_client_workspace_relationships') && ! str_contains($sql, 'for update')) {
                $terminated = true;
                DB::table('agency_client_workspace_relationships')->where('id', $relationshipId)->update([
                    'status' => 'terminated',
                    'terminated_at' => now(),
                    'terminated_by_user_id' => 1,
                    'termination_reason' => 'Terminated mid-write.',
                ]);
            }
        });

        try {
            $this->billing()->assignPayer($m['business'], PayerType::AgencyRebill, (int) $m['agencyOwner']->user_id, 'Racing a termination.');
            $this->fail('A relationship terminated before the write must never be recorded as the payer.');
        } catch (UnauthorizedPayerAssignmentException) {
            // expected
        }

        $this->assertTrue($terminated);
        $this->assertNotSame(PayerType::AgencyRebill->value, $this->assignmentRow($m['business'])->payer_type);
        $this->assertNull($this->assignmentRow($m['business'])->managing_agency_relationship_id);
    }

    public function test_re_granting_while_consent_is_present_is_a_true_no_op(): void
    {
        $m = $this->rebilledClient();
        $before = (array) $this->assignmentRow($m['business']);
        $transitions = DB::table('business_payer_transitions')->count();

        $outcome = $this->grantAgencyRebill($m);

        $this->assertFalse($outcome['changed']);
        $this->assertSame($before, (array) $this->assignmentRow($m['business']));
        $this->assertSame($transitions, DB::table('business_payer_transitions')->count());
    }

    // =====================================================================
    // Revoking standing consent
    // =====================================================================

    public function test_the_managing_agency_owner_revokes_consent_without_any_client_fallback(): void
    {
        $m = $this->rebilledClient();

        $outcome = $this->billing()->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Agency stops funding.');

        $this->assertTrue($outcome['changed']);
        $row = $this->assignmentRow($m['business']);
        $this->assertSame(PayerType::AgencyRebill->value, $row->payer_type, 'Revocation never silently flips the payer.');
        $this->assertSame((int) $m['relationship']->id, (int) $row->managing_agency_relationship_id);
        $this->assertNull($row->agency_rebill_consented_at);
        $this->assertNull($row->agency_rebill_consented_by_user_id);

        $transition = DB::table('business_payer_transitions')->where('business_id', $m['business']->id)->orderByDesc('id')->first();
        $this->assertSame(BillingProfileManager::AGENCY_REBILL_CONSENT_REVOKED, $transition->agency_rebill_consent);
        $this->assertSame((int) $m['relationship']->id, (int) $transition->managing_agency_relationship_id);
        $this->assertSame((int) $m['agencyOwner']->user_id, (int) $transition->actor_user_id);
        $this->assertSame('Agency stops funding.', $transition->reason);

        // Revoking again is an authorized no-op.
        $again = $this->billing()->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Again.');
        $this->assertFalse($again['changed']);
        $this->assertSame(2, DB::table('business_payer_transitions')->where('business_id', $m['business']->id)->whereNotNull('agency_rebill_consent')->count());
    }

    public function test_every_other_actor_is_refused_revocation(): void
    {
        $m = $this->rebilledClient();

        foreach ($this->refusedActors($m) as $label => $actorId) {
            try {
                $this->billing()->revokeAgencyRebillConsent($m['business'], $actorId, 'Should be refused.');
                $this->fail("{$label} must not be able to revoke AgencyRebill consent.");
            } catch (UnauthorizedPayerAssignmentException) {
                // expected
            }
        }

        $this->assertNotNull($this->assignmentRow($m['business'])->agency_rebill_consented_at);
    }

    public function test_re_granting_after_revocation_restores_consent_as_a_new_audited_grant(): void
    {
        Event::fake([BusinessPayerChanged::class]);
        $m = $this->rebilledClient();
        $this->billing()->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Paused.');

        $outcome = $this->grantAgencyRebill($m);

        $this->assertTrue($outcome['changed']);
        $this->assertNotNull($this->assignmentRow($m['business'])->agency_rebill_consented_at);
        $this->assertSame(
            ['granted', 'revoked', 'granted'],
            DB::table('business_payer_transitions')->where('business_id', $m['business']->id)->whereNotNull('agency_rebill_consent')->orderBy('id')->pluck('agency_rebill_consent')->all(),
        );
        // Same relationship, same payer type: an audited consent change, not a payer change.
        Event::assertDispatchedTimes(BusinessPayerChanged::class, 1);
    }

    public function test_switching_away_uses_the_unchanged_legacy_rule_and_clears_every_agency_rebill_column(): void
    {
        // An Agency-tier Client Workspace, so the unchanged Business/Workspace
        // rule has a legitimate manager (its own owner).
        $m = $this->rebilledClient(WorkspacePlanTier::Agency);

        $outcome = $this->billing()->assignPayer($m['business'], PayerType::Business, (int) $m['clientOwner']->user_id, 'Client pays again.');

        $this->assertTrue($outcome['changed']);
        $row = $this->assignmentRow($m['business']);
        $this->assertSame(PayerType::Business->value, $row->payer_type);
        $this->assertNull($row->managing_agency_relationship_id);
        $this->assertNull($row->agency_rebill_consented_at);
        $this->assertNull($row->agency_rebill_consented_by_user_id);

        $transition = DB::table('business_payer_transitions')->where('business_id', $m['business']->id)->orderByDesc('id')->first();
        $this->assertSame(PayerType::AgencyRebill->value, $transition->from_payer_type);
        $this->assertSame((int) $m['relationship']->id, (int) $transition->managing_agency_relationship_id, 'The audit keeps which relationship stopped funding.');
    }

    // =====================================================================
    // Funding configuration (instruments, automatic top-up)
    // =====================================================================

    public function test_only_the_managing_agency_owner_may_set_up_the_agency_funding_instrument(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();

        foreach ($this->refusedActors($m) as $label => $actorId) {
            try {
                app(PaymentInstrumentManager::class)->createSetupIntent($m['business'], $actorId);
                $this->fail("{$label} must not be able to set up the Agency funding instrument.");
            } catch (UnauthorizedPayerAssignmentException) {
                // expected
            }
        }

        $this->assertSame(0, DB::table('payment_provider_customers')->count(), 'A refused setup creates no provider customer anywhere.');

        app(PaymentInstrumentManager::class)->createSetupIntent($m['business'], (int) $m['agencyOwner']->user_id);

        $this->assertDatabaseHas('payment_provider_customers', ['workspace_id' => $m['agency']->id, 'business_id' => null]);
        $this->assertDatabaseMissing('payment_provider_customers', ['workspace_id' => $m['client']->id]);
        $this->assertDatabaseMissing('payment_provider_customers', ['business_id' => $m['business']->id]);
    }

    public function test_funding_configuration_needs_no_standing_consent_so_it_never_deadlocks(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->billing()->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Not yet.');

        app(PaymentInstrumentManager::class)->createSetupIntent($m['business'], (int) $m['agencyOwner']->user_id);
        $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);

        $this->assertDatabaseHas('payment_provider_customers', ['workspace_id' => $m['agency']->id]);
        $this->assertTrue((bool) $this->walletRow($m['business'])->auto_recharge_enabled);
    }

    public function test_funding_configuration_is_not_blocked_by_locked_account_access(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->putWorkspaceInto($m['agency'], 'locked');

        app(PaymentInstrumentManager::class)->createSetupIntent($m['business'], (int) $m['agencyOwner']->user_id);

        $this->assertDatabaseHas('payment_provider_customers', ['workspace_id' => $m['agency']->id]);
    }

    public function test_only_the_managing_agency_owner_may_configure_agency_funded_automatic_top_up(): void
    {
        $m = $this->rebilledClient();

        foreach ($this->refusedActors($m) as $label => $actorId) {
            try {
                $this->enableAutoRecharge($m['business'], $actorId);
                $this->fail("{$label} must not be able to configure Agency-funded automatic top-up.");
            } catch (UnauthorizedUsageBillingManagementException) {
                // expected
            }
        }

        $this->assertFalse((bool) $this->walletRow($m['business'])->auto_recharge_enabled);

        $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);

        $wallet = $this->walletRow($m['business']);
        $this->assertTrue((bool) $wallet->auto_recharge_enabled);
        $this->assertSame((int) $m['agencyOwner']->user_id, (int) $wallet->auto_recharge_consented_by_user_id);
    }

    public function test_detach_and_default_act_only_on_the_agency_instrument_and_only_for_the_agency_owner(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        [, $agencyCard] = $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        [, $clientCard] = $this->businessProviderCustomerWithCard($m['business'], '9999');
        $instruments = app(PaymentInstrumentManager::class);

        foreach ($this->refusedActors($m) as $label => $actorId) {
            foreach (['setDefaultInstrument', 'detachInstrument'] as $action) {
                try {
                    $instruments->{$action}($m['business'], $actorId, $agencyCard->fresh());
                    $this->fail("{$label} must not be able to {$action} the Agency's instrument.");
                } catch (UnauthorizedPayerAssignmentException) {
                    // expected
                }
            }
        }

        // The Agency owner can never reach the CLIENT's instrument through the
        // AgencyRebill payer.
        foreach (['setDefaultInstrument', 'detachInstrument'] as $action) {
            try {
                $instruments->{$action}($m['business'], (int) $m['agencyOwner']->user_id, $clientCard->fresh());
                $this->fail("The Agency owner must not be able to {$action} the client's instrument.");
            } catch (UnauthorizedPayerAssignmentException) {
                // expected
            }
        }

        $this->assertSame('active', $clientCard->fresh()->status->value);

        $instruments->detachInstrument($m['business'], (int) $m['agencyOwner']->user_id, $agencyCard->fresh());
        $this->assertSame('detached', $agencyCard->fresh()->status->value);
    }

    // =====================================================================
    // Payer-side financial controls
    // =====================================================================

    public function test_only_the_managing_agency_owner_controls_the_client_business_spend_controls(): void
    {
        $m = $this->rebilledClient();
        $wallet = app(UsageWalletManager::class);

        foreach ($this->refusedActors($m) as $label => $actorId) {
            foreach ([
                'spend cap' => fn () => $wallet->setSpendCap($m['business'], '1000000', $actorId, 'Refused.'),
                'feature limit' => fn () => $wallet->setFeatureLimit($m['business'], 'crm', '1000000', $actorId, 'Refused.'),
                'pause' => fn () => $wallet->pausePaidActivity($m['business'], $actorId, 'Refused.'),
            ] as $control => $attempt) {
                try {
                    $attempt();
                    $this->fail("{$label} must not control the {$control} of an Agency-funded Business.");
                } catch (UnauthorizedUsageBillingManagementException) {
                    // expected
                }
            }

            $this->assertFalse($this->billing()->actorManagesPayerControls($m['business'], $actorId), $label);
        }

        $agencyOwnerId = (int) $m['agencyOwner']->user_id;
        $wallet->setSpendCap($m['business'], '2000000', $agencyOwnerId, 'Agency cap.');
        $wallet->setFeatureLimit($m['business'], 'crm', '1500000', $agencyOwnerId, 'Agency limit.');
        $wallet->pausePaidActivity($m['business'], $agencyOwnerId, 'Agency pause.');

        $this->assertSame('2000000', (string) $this->walletRow($m['business'])->monthly_spend_cap_micro);
        $this->assertNotNull($this->walletRow($m['business'])->paid_activity_paused_at);
        $this->assertTrue($this->billing()->actorManagesPayerControls($m['business'], $agencyOwnerId));
    }

    public function test_a_relationship_that_no_longer_proves_who_pays_leaves_nobody_in_control(): void
    {
        $m = $this->rebilledClient();
        app(AgencyClientRelationshipManager::class)->terminate((int) $m['agencyOwner']->user_id, $m['relationship'], 'Ended.');

        $this->assertFalse($this->billing()->actorManagesPayerControls($m['business'], (int) $m['agencyOwner']->user_id));
        $this->assertFalse($this->billing()->actorManagesPayerControls($m['business'], (int) $m['clientOwner']->user_id));
        $this->assertNull($this->billing()->authorizedFundingPayer($m['business'], (int) $m['agencyOwner']->user_id));
    }

    public function test_an_agency_that_left_the_agency_tier_keeps_no_funding_control_or_charge_authority(): void
    {
        $m = $this->rebilledClient();
        $agencyOwnerId = (int) $m['agencyOwner']->user_id;
        $this->assertTrue($this->billing()->actorManagesPayerControls($m['business'], $agencyOwnerId));

        app(EntitlementManager::class)->changePlan($m['agency'], WorkspacePlanTier::Growth, $this->platformAdminId(), 'Downgraded.');

        $this->assertFalse($this->billing()->actorManagesPayerControls($m['business'], $agencyOwnerId));
        $this->assertFalse($this->billing()->actorManagesPayerControls($m['business'], (int) $m['clientOwner']->user_id));
        $this->assertNull($this->billing()->authorizedFundingPayer($m['business'], $agencyOwnerId));
        $this->assertNull($this->billing()->authorizedChargePayer($m['business'], $agencyOwnerId));
    }

    // =====================================================================
    // Charge origination
    // =====================================================================

    public function test_only_the_managing_agency_owner_with_standing_consent_may_originate_a_charge(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $checkout = app(UsageBillingCheckoutManager::class);

        foreach ($this->refusedActors($m) as $label => $actorId) {
            try {
                $checkout->initiateTopUp($m['business'], $actorId, 10_000_000);
                $this->fail("{$label} must not originate an Agency-funded charge.");
            } catch (UnauthorizedPayerAssignmentException) {
                // expected
            }
        }

        // Authorized, but no Agency provider customer exists yet: a policy
        // outcome, not an authorization failure — and nothing was charged.
        $result = $checkout->initiateTopUp($m['business'], (int) $m['agencyOwner']->user_id, 10_000_000);
        $this->assertSame('no_provider_customer', $result->denialReason);

        $this->billing()->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Stop.');

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        $checkout->initiateTopUp($m['business'], (int) $m['agencyOwner']->user_id, 10_000_000);
    }

    public static function presentationActors(): array
    {
        return ['agency owner' => ['agencyOwner', true], 'client owner' => ['clientOwner', false]];
    }

    #[DataProvider('presentationActors')]
    public function test_billing_responsibility_facts_name_the_managing_agency_owner_as_the_payer(string $actorKey, bool $isPayer): void
    {
        $m = $this->rebilledClient();

        $facts = $this->billing()->billingResponsibilityFor($m['business'], (int) $m[$actorKey]->user_id);

        $this->assertSame(PayerType::AgencyRebill->value, $facts['payer_type']);
        $this->assertTrue($facts['agency_paid']);
        $this->assertSame('agency', $facts['who_pays']);
        $this->assertSame($isPayer, $facts['actor_is_payer']);
        $this->assertSame($isPayer, $facts['actor_manages_limits']);
    }
}
