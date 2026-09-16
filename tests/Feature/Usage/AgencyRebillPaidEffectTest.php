<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptPurpose;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\EffectivePayer;
use App\Library\Usage\EffectivePayerResolver;
use App\Library\Usage\FundingAttemptResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Feature\Usage\Concerns\AgencyRebillFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 09 §5/§7/§11 — Agency-funded paid effects.
 *
 * The questions this file exists to answer, each against the real wallet,
 * ledger and checkout managers:
 *   - whose provider customer and instrument is actually charged — never the
 *     client's, with no fallback when the Agency's is missing;
 *   - which spend controls govern an AgencyRebill Business (its own wallet's:
 *     yes; the Client Workspace's aggregate controls: no; an invented Agency
 *     aggregate: none);
 *   - that consent, effective account access and the relationship are
 *     re-checked for every NEW effect, including under the lock against a
 *     stale pre-revocation read.
 */
class AgencyRebillPaidEffectTest extends TestCase
{
    use AgencyRebillFixtures;
    use RefreshDatabase;

    private function wallet(): UsageWalletManager
    {
        return app(UsageWalletManager::class);
    }

    private function checkout(): UsageBillingCheckoutManager
    {
        return app(UsageBillingCheckoutManager::class);
    }

    private function reserve(Business $business): \App\Library\Usage\ReservationResult
    {
        return $this->wallet()->reserve($business, 'crm', 'agency-rebill-' . uniqid('', true));
    }

    /** A rebilled client whose wallet can pay for one metered 'crm' unit. */
    private function spendableRebilledClient(): array
    {
        $this->activateFixtureRate('crm');
        $m = $this->rebilledClient();
        $this->fund($m['business'], 50_000_000);

        return $m;
    }

    private function fundingAttempts(Business $business): \Illuminate\Support\Collection
    {
        return DB::table('business_funding_attempts')->where('business_id', $business->id)->get();
    }

    // =====================================================================
    // Which provider customer and instrument is charged (§3 site 1)
    // =====================================================================

    public function test_with_client_and_agency_provider_customers_both_present_the_agency_is_charged(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        [$agencyCustomer] = $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        [$clientBusinessCustomer] = $this->businessProviderCustomerWithCard($m['business'], '9999');
        [$clientWorkspaceCustomer] = $this->workspaceProviderCustomerWithCard($m['client'], '8888');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);

        $result = $this->checkout()->initiateAutoRecharge($m['business'], $amount);

        $this->assertNotSame(FundingAttemptState::Failed, $result->state, (string) $result->denialReason);
        $attempt = DB::table('business_funding_attempts')->where('id', $result->fundingAttemptId)->first();
        $this->assertSame((int) $agencyCustomer->id, (int) $attempt->provider_customer_id);
        $this->assertNotSame((int) $clientBusinessCustomer->id, (int) $attempt->provider_customer_id);
        $this->assertNotSame((int) $clientWorkspaceCustomer->id, (int) $attempt->provider_customer_id);
        $this->assertStringContainsString('1111', $attempt->payment_method_display_snapshot);
        $this->assertSame(PayerType::AgencyRebill->value, $attempt->payer_type_snapshot);
    }

    public function test_a_manual_top_up_by_the_agency_owner_is_checked_out_against_the_agency_provider_customer(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        [$agencyCustomer] = $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $this->businessProviderCustomerWithCard($m['business'], '9999');

        $result = $this->checkout()->initiateTopUp($m['business'], (int) $m['agencyOwner']->user_id, 10_000_000);

        $attempt = DB::table('business_funding_attempts')->where('id', $result->fundingAttemptId)->first();
        $this->assertNotNull($attempt, (string) $result->denialReason);
        $this->assertSame((int) $agencyCustomer->id, (int) $attempt->provider_customer_id);
    }

    public function test_a_missing_agency_provider_customer_fails_closed_even_though_the_client_has_one_with_a_card(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        [, $clientCard] = $this->businessProviderCustomerWithCard($m['business'], '9999');
        $this->workspaceProviderCustomerWithCard($m['client'], '8888');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);

        $result = $this->checkout()->initiateAutoRecharge($m['business'], $amount);

        $this->assertSame(FundingAttemptState::Failed, $result->state);
        $this->assertSame('no_provider_customer', $result->denialReason);
        $this->assertCount(0, $this->fundingAttempts($m['business']), 'Nothing is ever charged to the client instead.');
        $this->assertTrue((bool) $clientCard->fresh()->is_default);
    }

    public function test_an_agency_provider_customer_without_a_card_fails_closed_even_though_the_client_has_a_card(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithoutCard($m['agency']);
        $this->businessProviderCustomerWithCard($m['business'], '9999');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);

        $result = $this->checkout()->initiateAutoRecharge($m['business'], $amount);

        $this->assertSame('no_payment_instrument', $result->denialReason);
        $this->assertCount(0, $this->fundingAttempts($m['business']));
    }

    // =====================================================================
    // The spend-time gate at both paid-effect boundaries (§11)
    // =====================================================================

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
    public function test_locked_effective_access_refuses_a_new_agency_funded_reservation(string $side, string $state): void
    {
        $m = $this->spendableRebilledClient();
        $this->putWorkspaceInto($m[$side], $state);

        $result = $this->reserve($m['business']);

        $this->assertFalse($result->granted);
        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_ACCOUNT_ACCESS_LOCKED, $result->denialReason);
    }

    #[DataProvider('lockedStates')]
    public function test_locked_effective_access_refuses_a_new_agency_funded_charge(string $side, string $state): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);
        $this->putWorkspaceInto($m[$side], $state);

        $result = $this->checkout()->initiateAutoRecharge($m['business'], $amount);

        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_ACCOUNT_ACCESS_LOCKED, $result->denialReason);
        $this->assertCount(0, $this->fundingAttempts($m['business']));
    }

    public static function graceSides(): array
    {
        return ['client grace' => ['client'], 'agency grace' => ['agency']];
    }

    #[DataProvider('graceSides')]
    public function test_grace_does_not_block_agency_funded_spend(string $side): void
    {
        $m = $this->spendableRebilledClient();
        $this->putWorkspaceInto($m[$side], 'grace');

        $this->assertTrue($this->reserve($m['business'])->granted);
    }

    public function test_an_agency_funded_reservation_proceeds_with_consent_and_usable_access(): void
    {
        $m = $this->spendableRebilledClient();

        $this->assertTrue($this->reserve($m['business'])->granted);
    }

    public function test_revoked_consent_refuses_new_reservations_and_charges_immediately_and_keeps_history(): void
    {
        $this->fakeProvider();
        $m = $this->spendableRebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);
        $this->fund($m['business'], 50_000_000);
        $this->assertTrue($this->reserve($m['business'])->granted);
        $ledgerBefore = DB::table('business_usage_reservations')->where('business_id', $m['business']->id)->count();

        app(BillingProfileManager::class)->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Stop funding.');

        $reservation = $this->reserve($m['business']);
        $this->assertFalse($reservation->granted);
        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_CONSENT_MISSING, $reservation->denialReason);

        $charge = $this->checkout()->initiateAutoRecharge($m['business'], $amount);
        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_CONSENT_MISSING, $charge->denialReason);

        $this->assertSame($ledgerBefore, DB::table('business_usage_reservations')->where('business_id', $m['business']->id)->count(), 'Existing history is untouched.');
    }

    public function test_a_terminated_relationship_fails_closed_at_both_boundaries_without_flipping_the_payer(): void
    {
        $this->fakeProvider();
        $m = $this->spendableRebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $this->businessProviderCustomerWithCard($m['business'], '9999');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);

        app(AgencyClientRelationshipManager::class)->terminate((int) $m['agencyOwner']->user_id, $m['relationship'], 'Client moved on.');

        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_RELATIONSHIP_INVALID, $this->reserve($m['business'])->denialReason);
        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_RELATIONSHIP_INVALID, $this->checkout()->initiateAutoRecharge($m['business'], $amount)->denialReason);
        $this->assertCount(0, $this->fundingAttempts($m['business']));
        $this->assertSame(PayerType::AgencyRebill->value, $this->assignmentRow($m['business'])->payer_type);
    }

    public function test_an_agency_that_left_the_agency_tier_stops_funding_at_both_boundaries(): void
    {
        $this->fakeProvider();
        $m = $this->spendableRebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $this->businessProviderCustomerWithCard($m['business'], '9999');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);
        $this->fund($m['business'], 50_000_000);
        $this->assertTrue($this->reserve($m['business'])->granted);

        app(EntitlementManager::class)->changePlan($m['agency'], WorkspacePlanTier::Growth, $this->platformAdminId(), 'Downgraded.');

        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_RELATIONSHIP_INVALID, $this->reserve($m['business'])->denialReason);
        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_RELATIONSHIP_INVALID, $this->checkout()->initiateAutoRecharge($m['business'], $amount)->denialReason);
        $this->assertCount(0, $this->fundingAttempts($m['business']));
        $this->assertSame(PayerType::AgencyRebill->value, $this->assignmentRow($m['business'])->payer_type, 'Never a silent fallback to a client payer.');
    }

    /**
     * Review correction (T-CAP-2 / T-CAP-4): inside reserve(), nothing may
     * take a consistent-read snapshot of the payer assignment before the
     * Workspace controls row is locked, or the Workspace aggregate cap sum
     * would be computed from a stale snapshot under concurrency.
     */
    public function test_a_workspace_paid_reservation_reads_the_payer_only_with_a_locking_read_before_the_controls_lock(): void
    {
        $this->activateFixtureRate('crm');
        [, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->setPayer($business, PayerType::Workspace);
        $this->fund($business, 50_000_000);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->assertTrue($this->reserve($business)->granted);

        $controlsLock = null;
        foreach ($queries as $i => $sql) {
            if (str_contains($sql, 'workspace_usage_controls') && str_contains($sql, 'for update')) {
                $controlsLock = $i;
                break;
            }
        }
        $this->assertNotNull($controlsLock, 'A Workspace payer still locks its Workspace controls.');

        $assignmentReadsBeforeLock = array_filter(array_slice($queries, 0, $controlsLock), fn (string $sql): bool => str_contains($sql, 'business_payer_assignments'));
        $this->assertNotEmpty($assignmentReadsBeforeLock);
        foreach ($assignmentReadsBeforeLock as $sql) {
            $this->assertStringContainsString('for update', $sql);
        }
    }

    public function test_a_forged_relationship_id_fails_closed_at_both_boundaries(): void
    {
        $this->fakeProvider();
        $m = $this->spendableRebilledClient();
        $other = $this->managedClient(WorkspacePlanTier::Growth, ' Two');
        $this->workspaceProviderCustomerWithCard($other['agency'], '2222');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);

        DB::table('business_payer_assignments')->where('business_id', $m['business']->id)
            ->update(['managing_agency_relationship_id' => $other['relationship']->id]);

        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_RELATIONSHIP_INVALID, $this->reserve($m['business'])->denialReason);
        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_RELATIONSHIP_INVALID, $this->checkout()->initiateAutoRecharge($m['business'], $amount)->denialReason);
        $this->assertCount(0, $this->fundingAttempts($m['business']));
    }

    // =====================================================================
    // Stale reads under the lock (§7)
    // =====================================================================

    private function initiateChargeWithPayer(Business $business, EffectivePayer $payer, int $amount): FundingAttemptResult
    {
        return (new ReflectionMethod(UsageBillingCheckoutManager::class, 'initiateCharge'))
            ->invoke($this->checkout(), $business, FundingAttemptPurpose::AutoRecharge, $payer, $amount, null);
    }

    public function test_a_stale_pre_revocation_payer_cannot_authorize_a_charge_under_the_lock(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);

        // Read before the revocation commits — it still carries consent.
        $stalePayer = app(EffectivePayerResolver::class)->resolve($m['business']);
        $this->assertTrue($stalePayer->hasAgencyRebillStandingConsent());

        app(BillingProfileManager::class)->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Revoked mid-flight.');

        $result = $this->initiateChargeWithPayer($m['business'], $stalePayer, $amount);

        $this->assertSame(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_CONSENT_MISSING, $result->denialReason);
        $this->assertCount(0, $this->fundingAttempts($m['business']));
    }

    public function test_a_stale_payer_from_a_replaced_managing_agency_is_refused_as_a_payer_change(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);
        $stalePayer = app(EffectivePayerResolver::class)->resolve($m['business']);

        // The first Agency's relationship ends; a second Agency takes over and grants.
        app(AgencyClientRelationshipManager::class)->terminate((int) $m['agencyOwner']->user_id, $m['relationship'], 'Handover.');
        [$newOwner, $newAgency] = $this->agencyAccount('Successor Agency');
        app(AgencyClientRelationshipManager::class)->create((int) $newOwner->user_id, $newAgency, $m['client']);
        app(BillingProfileManager::class)->assignPayer($m['business'], PayerType::AgencyRebill, (int) $newOwner->user_id, 'Successor funds.');
        $this->workspaceProviderCustomerWithCard($newAgency, '3333');

        $result = $this->initiateChargeWithPayer($m['business'], $stalePayer, $amount);

        $this->assertSame(EffectivePayerResolver::REFUSAL_PAYER_CHANGED, $result->denialReason);
        $this->assertCount(0, $this->fundingAttempts($m['business']));
    }

    // =====================================================================
    // Spend controls: the Business wallet always; Workspace aggregates by payer
    // =====================================================================

    public function test_the_business_spend_cap_feature_limit_and_business_pause_still_govern_an_agency_funded_business(): void
    {
        $m = $this->spendableRebilledClient();
        $agencyOwnerId = (int) $m['agencyOwner']->user_id;

        $this->wallet()->setSpendCap($m['business'], '1', $agencyOwnerId, 'Tiny cap.');
        $this->assertSame('business_spend_cap', $this->reserve($m['business'])->denialReason);
        $this->wallet()->setSpendCap($m['business'], null, $agencyOwnerId, 'Clear.');

        $this->wallet()->setFeatureLimit($m['business'], 'crm', '1', $agencyOwnerId, 'Tiny limit.');
        $this->assertSame('feature_limit', $this->reserve($m['business'])->denialReason);
        $this->wallet()->setFeatureLimit($m['business'], 'crm', null, $agencyOwnerId, 'Clear.');

        $this->wallet()->pausePaidActivity($m['business'], $agencyOwnerId, 'Pause.');
        $this->assertSame(UsageWalletManager::DENIAL_PAID_ACTIVITY_PAUSED, $this->reserve($m['business'])->denialReason);
        $this->wallet()->resumePaidActivity($m['business'], $agencyOwnerId, 'Resume.');

        $this->fund($m['business'], 0);
        $this->assertSame('insufficient_balance', $this->reserve($m['business'])->denialReason);
    }

    public function test_the_client_workspace_aggregate_cap_and_pause_do_not_masquerade_as_agency_payer_controls(): void
    {
        $m = $this->spendableRebilledClient();
        $this->workspaceControls($m['client'], ['paid_activity_paused_at' => now(), 'monthly_aggregate_spend_cap_micro' => 0]);

        $this->assertTrue($this->reserve($m['business'])->granted);
    }

    public function test_no_agency_wide_aggregate_is_invented_over_its_rebilled_clients(): void
    {
        $m = $this->spendableRebilledClient();
        $this->workspaceControls($m['agency'], ['paid_activity_paused_at' => now(), 'monthly_aggregate_spend_cap_micro' => 0]);

        $this->assertTrue($this->reserve($m['business'])->granted);
    }

    public function test_workspace_aggregate_controls_are_unchanged_for_a_workspace_payer(): void
    {
        $this->activateFixtureRate('crm');
        [, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->setPayer($business, PayerType::Workspace);
        $this->fund($business, 50_000_000);

        $this->workspaceControls($workspace, ['monthly_aggregate_spend_cap_micro' => 0]);
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_SPEND_CAP, $this->reserve($business)->denialReason);

        $this->workspaceControls($workspace, ['paid_activity_paused_at' => now()]);
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_PAID_ACTIVITY_PAUSED, $this->reserve($business)->denialReason);
    }

    public function test_the_workspace_pause_still_governs_a_business_payer_as_before(): void
    {
        $this->activateFixtureRate('crm');
        [, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->setPayer($business, PayerType::Business);
        $this->fund($business, 50_000_000);
        $this->workspaceControls($workspace, ['paid_activity_paused_at' => now(), 'monthly_aggregate_spend_cap_micro' => 0]);

        // Pause applies (unchanged); the aggregate cap never did for a Business payer.
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_PAID_ACTIVITY_PAUSED, $this->reserve($business)->denialReason);
        $this->workspaceControls($workspace, ['paid_activity_paused_at' => null]);
        $this->assertTrue($this->reserve($business)->granted);
    }

    // =====================================================================
    // Automatic top-up ceilings and refusal semantics
    // =====================================================================

    public function test_agency_rebill_auto_recharge_is_bounded_by_the_business_ceiling_only(): void
    {
        $m = $this->rebilledClient();
        $amount = $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id, (string) UsageWalletManager::AUTO_RECHARGE_PRESETS_MICRO[1]);

        // The Client Workspace's and the Agency Workspace's aggregate recharge
        // ceilings are both set to nothing: neither applies.
        $this->workspaceControls($m['client'], ['monthly_aggregate_recharge_cap_micro' => 1]);
        $this->workspaceControls($m['agency'], ['monthly_aggregate_recharge_cap_micro' => 1]);
        $this->assertTrue($this->wallet()->autoRechargeCeilingAdmission($m['business'], $amount)->allowed);

        // The Business's own ceiling does.
        DB::table('business_usage_wallets')->where('business_id', $m['business']->id)->update(['recharged_this_period_micro' => $amount]);
        $refused = $this->wallet()->autoRechargeCeilingAdmission($m['business'], $amount);
        $this->assertFalse($refused->allowed);
        $this->assertSame(UsageWalletManager::DENIAL_BUSINESS_RECHARGE_CAP, $refused->denialReason);
    }

    public function test_the_workspace_aggregate_recharge_ceiling_is_unchanged_for_a_workspace_payer(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->setPayer($business, PayerType::Workspace);
        $amount = $this->enableAutoRecharge($business, (int) $owner->user_id);
        $this->workspaceControls($workspace, ['monthly_aggregate_recharge_cap_micro' => 1]);

        $refused = $this->wallet()->autoRechargeCeilingAdmission($business, $amount);

        $this->assertFalse($refused->allowed);
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_RECHARGE_CAP, $refused->denialReason);
    }

    public function test_a_consent_refusal_is_an_auto_recharge_policy_refusal_never_a_payment_failure(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);
        app(BillingProfileManager::class)->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Stop.');

        app()->call([new EvaluateBusinessAutoRecharge((int) $m['business']->id), 'handle']);

        $wallet = $this->walletRow($m['business']);
        $this->assertCount(0, $this->fundingAttempts($m['business']));
        $this->assertSame(0, (int) $wallet->consecutive_recharge_failures);
        $this->assertTrue((bool) $wallet->auto_recharge_enabled, 'A policy refusal never disables automatic top-up.');
        $this->assertNotNull($wallet->auto_recharge_refusal_notified_at);
        $this->assertContains(EffectivePayerResolver::REFUSAL_AGENCY_REBILL_CONSENT_MISSING, UsageWalletManager::AUTO_RECHARGE_REFUSAL_REASONS);
    }

    public function test_the_auto_recharge_job_charges_the_agency_when_everything_is_in_order(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        [$agencyCustomer] = $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $this->businessProviderCustomerWithCard($m['business'], '9999');
        $this->enableAutoRecharge($m['business'], (int) $m['agencyOwner']->user_id);

        app()->call([new EvaluateBusinessAutoRecharge((int) $m['business']->id), 'handle']);

        $attempts = $this->fundingAttempts($m['business']);
        $this->assertCount(1, $attempts);
        $this->assertSame((int) $agencyCustomer->id, (int) $attempts->first()->provider_customer_id);
    }
}
