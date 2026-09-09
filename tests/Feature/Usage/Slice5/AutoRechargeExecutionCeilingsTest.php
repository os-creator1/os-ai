<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\PaymentProviderEvent;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — Correction Round 1 §5, §6.2, §6.3 and
 * §13.5 (tests 22–33), T-WALLET-6 through the real job and fake gateway:
 * the Business monthly ceiling and its approved hard maximum, the Agency
 * Workspace aggregate ceiling and its hard maximum (failing closed when it
 * is missing), client-paid and manual top-ups never counting, pending and
 * successful amounts counted exactly once, idempotent replay, and failed
 * attempts releasing their claim — every refusal before the provider call,
 * with no balance increase and no false success.
 */
class AutoRechargeExecutionCeilingsTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    private function configured(WorkspacePlanTier $tier, string $capMicro): array
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet($tier);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '5000000', $capMicro, (int) $owner->user_id);

        return [$owner, $business, $workspace];
    }

    private function autoRechargeAttempts(Business $business): int
    {
        return DB::table('business_funding_attempts')->where('business_id', $business->id)->where('purpose', 'auto_recharge')->count();
    }

    private function setWallet(Business $business, array $columns): void
    {
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update($columns);
    }

    public function test_the_real_job_admits_the_exact_remaining_business_allowance_and_refuses_one_unit_more_before_the_provider(): void
    {
        [, $business] = $this->configured(WorkspacePlanTier::Growth, '10000000');

        // 27. Exactly the remaining allowance: 5,000,000 already added + 5,000,000 = the 10,000,000 ceiling.
        $this->setWallet($business, ['available_balance_micro' => 1_000_000, 'recharged_this_period_micro' => 5_000_000]);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $wallet = $this->walletRow($business);
        $this->assertSame('6000000', (string) $wallet->available_balance_micro);
        $this->assertSame('10000000', (string) $wallet->recharged_this_period_micro);
        $this->assertSame(1, $this->autoRechargeAttempts($business));
        $this->assertDatabaseHas('business_funding_attempts', ['business_id' => $business->id, 'purpose' => 'auto_recharge', 'state' => 'succeeded']);

        // 28/29/30. One micro-unit above the allowance: refused before the fake gateway — a
        // provider call would leave a failed attempt behind, and none appears.
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->setWallet($business, ['available_balance_micro' => 1_000_000, 'recharged_this_period_micro' => 5_000_001]);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $wallet = $this->walletRow($business);
        $this->assertSame('1000000', (string) $wallet->available_balance_micro);
        $this->assertSame('5000001', (string) $wallet->recharged_this_period_micro);
        $this->assertSame(1, $this->autoRechargeAttempts($business));
        $this->assertSame(0, (int) $wallet->consecutive_recharge_failures, 'A policy refusal is never a payment failure.');
        $this->assertSame(1, (int) $wallet->auto_recharge_enabled);

        $admission = app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000);
        $this->assertFalse($admission->allowed);
        $this->assertSame(UsageWalletManager::DENIAL_BUSINESS_RECHARGE_CAP, $admission->denialReason);
    }

    public function test_the_approved_business_hard_maximum_bounds_execution_even_when_a_stored_ceiling_is_higher(): void
    {
        [, $business] = $this->configured(WorkspacePlanTier::Growth, (string) UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO);

        // 495,000,000 + 5,000,000 = exactly the $500 maximum: admitted.
        $this->setWallet($business, ['available_balance_micro' => 1_000_000, 'recharged_this_period_micro' => 495_000_000]);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame('500000000', (string) $this->walletRow($business)->recharged_this_period_micro);
        $this->assertSame(1, $this->autoRechargeAttempts($business));

        // One micro-unit more: refused.
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->setWallet($business, ['available_balance_micro' => 1_000_000, 'recharged_this_period_micro' => 495_000_001]);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame('1000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame(1, $this->autoRechargeAttempts($business));

        // A ceiling stored above the maximum (never accepted by the manager or the request;
        // written here directly) is bounded by the maximum in execution.
        $this->setWallet($business, ['monthly_recharge_cap_micro' => 600_000_000, 'available_balance_micro' => 1_000_000, 'recharged_this_period_micro' => 496_000_000]);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame('1000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame(1, $this->autoRechargeAttempts($business));
        $this->assertSame(UsageWalletManager::DENIAL_BUSINESS_RECHARGE_CAP, app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000)->denialReason);

        // A missing ceiling fails closed.
        $this->setWallet($business, ['monthly_recharge_cap_micro' => null, 'recharged_this_period_micro' => 0]);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame(1, $this->autoRechargeAttempts($business));
        $this->assertSame(UsageWalletManager::DENIAL_BUSINESS_RECHARGE_CAP_MISSING, app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000)->denialReason);
    }

    public function test_the_agency_aggregate_ceiling_is_enforced_by_the_real_job_fails_closed_without_one_and_ignores_client_paid_businesses(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        $this->fakeProvider();
        [, $clientA] = $this->clientBusiness($workspace, 'Client A');
        [, $clientB] = $this->clientBusiness($workspace, 'Client B');
        [, $clientC] = $this->clientBusiness($workspace, 'Client C');
        [$selfPayer, $selfPaid] = $this->clientBusiness($workspace, 'Self Paid');
        foreach ([$clientA, $clientB, $clientC] as $client) {
            $this->setPayer($client, PayerType::Workspace);
        }
        $this->setPayer($selfPaid, PayerType::Business);
        $this->attachFakeCard($clientA, (int) $agency->user_id); // the Agency's instrument serves every agency-paid client
        $this->attachFakeCard($selfPaid, (int) $selfPayer->user_id);
        $max = (string) UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO;
        foreach ([$clientA, $clientB, $clientC] as $client) {
            app(UsageWalletManager::class)->configureAutoRecharge($client, true, '2000000', '5000000', $max, (int) $agency->user_id);
            $this->fund($client, 1_000_000);
        }
        app(UsageWalletManager::class)->configureAutoRecharge($selfPaid, true, '2000000', '5000000', $max, (int) $selfPayer->user_id);
        $this->fund($selfPaid, 1_000_000);

        // 22. No effective Agency ceiling yet: fails closed before the provider, no attempt.
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        EvaluateBusinessAutoRecharge::dispatch((int) $clientA->id);
        $this->assertSame(0, $this->autoRechargeAttempts($clientA));
        $this->assertSame('1000000', (string) $this->walletRow($clientA)->available_balance_micro);
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_RECHARGE_CAP_MISSING, app(UsageWalletManager::class)->autoRechargeCeilingAdmission($clientA, 5_000_000)->denialReason);
        // … while the client-paid Business is not held back by the Agency at all.
        $this->assertTrue(app(UsageWalletManager::class)->autoRechargeCeilingAdmission($selfPaid, 5_000_000)->allowed);
        $this->gateway->paymentIntentOutcomes = [];

        // 26/27. Aggregate ceiling 10,000,000: A then B reach exactly 10,000,000; C is one preset over.
        app(UsageWalletManager::class)->setWorkspaceAggregateRechargeCap($workspace, '10000000', (int) $agency->user_id, 'Agency ceiling.');
        EvaluateBusinessAutoRecharge::dispatch((int) $clientA->id);
        $this->assertSame('6000000', (string) $this->walletRow($clientA)->available_balance_micro);
        EvaluateBusinessAutoRecharge::dispatch((int) $clientB->id);
        $this->assertSame('6000000', (string) $this->walletRow($clientB)->available_balance_micro);

        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        EvaluateBusinessAutoRecharge::dispatch((int) $clientC->id);
        $this->assertSame(0, $this->autoRechargeAttempts($clientC), 'The third agency-paid client is refused before the provider.');
        $this->assertSame('1000000', (string) $this->walletRow($clientC)->available_balance_micro);
        $refused = app(UsageWalletManager::class)->autoRechargeCeilingAdmission($clientC, 5_000_000);
        $this->assertFalse($refused->allowed);
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_RECHARGE_CAP, $refused->denialReason);
        $this->assertSame('0', $refused->remainingHeadroomMicro);
        $this->gateway->paymentIntentOutcomes = [];

        // 23. The client-paid Business neither consumes nor is limited by the exhausted Agency ceiling.
        EvaluateBusinessAutoRecharge::dispatch((int) $selfPaid->id);
        $this->assertSame('6000000', (string) $this->walletRow($selfPaid)->available_balance_micro);
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_RECHARGE_CAP, app(UsageWalletManager::class)->autoRechargeCeilingAdmission($clientC, 5_000_000)->denialReason);

        // The Agency hard maximum bounds execution even when a stored aggregate ceiling is higher.
        app(UsageWalletManager::class)->setWorkspaceAggregateRechargeCap($workspace, (string) UsageWalletManager::WORKSPACE_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO, (int) $agency->user_id, 'Maximum.');
        $this->setWallet($clientA, ['recharged_this_period_micro' => 495_000_000]);
        $this->setWallet($clientB, ['recharged_this_period_micro' => 0, 'available_balance_micro' => 1_000_000]);
        $this->setWallet($clientC, ['recharged_this_period_micro' => 0, 'available_balance_micro' => 1_000_000]);
        $this->assertTrue(app(UsageWalletManager::class)->autoRechargeCeilingAdmission($clientB, 5_000_000)->allowed, '495,000,000 + 5,000,000 is exactly the $500 maximum.');
        DB::table('workspace_usage_controls')->where('workspace_id', $workspace->id)->update(['monthly_aggregate_recharge_cap_micro' => 600_000_000]);
        $this->setWallet($clientA, ['recharged_this_period_micro' => 496_000_000]);
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        EvaluateBusinessAutoRecharge::dispatch((int) $clientC->id);
        $this->assertSame(0, $this->autoRechargeAttempts($clientC));
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_RECHARGE_CAP, app(UsageWalletManager::class)->autoRechargeCeilingAdmission($clientC, 5_000_000)->denialReason);
    }

    public function test_manual_top_ups_never_consume_an_automatic_top_up_ceiling(): void
    {
        [$owner, $business] = $this->configured(WorkspacePlanTier::Growth, '5000000');

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, (int) $owner->user_id, 50_000_000);
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn(app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId));
        $this->assertSame('50000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame('0', (string) $this->walletRow($business)->recharged_this_period_micro, 'A manual top-up is never an automatic one.');

        $admission = app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000);
        $this->assertTrue($admission->allowed);
        $this->assertSame('5000000', $admission->remainingHeadroomMicro);
    }

    public function test_pending_and_successful_amounts_count_exactly_once_and_a_failed_attempt_releases_its_claim(): void
    {
        [, $business] = $this->configured(WorkspacePlanTier::Growth, '10000000');
        $this->fund($business, 1_000_000);
        $manager = app(UsageWalletManager::class);

        // 31. A pending (requires-action) attempt claims its 5,000,000 …
        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertDatabaseHas('business_funding_attempts', ['business_id' => $business->id, 'purpose' => 'auto_recharge', 'state' => 'requires_action']);
        $this->assertSame('0', (string) $this->walletRow($business)->recharged_this_period_micro);
        $this->assertSame('5000000', $manager->autoRechargeCeilingAdmission($business, 5_000_000)->remainingHeadroomMicro, 'The pending claim counts.');
        $this->setWallet($business, ['monthly_recharge_cap_micro' => 5_000_000]);
        $this->assertSame(UsageWalletManager::DENIAL_BUSINESS_RECHARGE_CAP, $manager->autoRechargeCeilingAdmission($business, 5_000_000)->denialReason);
        $this->setWallet($business, ['monthly_recharge_cap_micro' => 10_000_000]);

        // … and settling it moves the same 5,000,000 into recharged_this_period_micro — never both.
        $attempt = app(BusinessFundingAttemptRepository::class)->findByLocalIdempotencyKey(DB::table('business_funding_attempts')->where('business_id', $business->id)->value('local_idempotency_key'));
        app(UsageBillingCheckoutManager::class)->retryFundingAttemptAsAdministrator($attempt, $this->platformAdminUserId(), 'Settle.');
        $this->assertDatabaseHas('business_funding_attempts', ['id' => $attempt->id, 'state' => 'succeeded']);
        $this->assertSame('5000000', (string) $this->walletRow($business)->recharged_this_period_micro);
        $this->assertSame('5000000', $manager->autoRechargeCeilingAdmission($business, 5_000_000)->remainingHeadroomMicro, 'Counted once, not twice.');

        // 33. A definitively failed attempt releases its MONETARY claim …
        $this->fund($business, 1_000_000);
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertDatabaseHas('business_funding_attempts', ['business_id' => $business->id, 'purpose' => 'auto_recharge', 'state' => 'failed']);
        $this->assertSame('5000000', (string) $this->walletRow($business)->recharged_this_period_micro);
        $this->assertSame(0, app(BusinessFundingAttemptRepository::class)->outstandingAutoRechargeAmountMicroForBusinesses([(int) $business->id]), 'A failed attempt reserves no money.');

        // … but not its frequency slot (Correction Round 2 §1.1): two attempt
        // rows exist in this window, so the next automatic initiation is
        // refused on frequency even though 5,000,000 of monetary headroom
        // remains under the ceiling.
        $repository = app(BusinessFundingAttemptRepository::class);
        $this->assertSame(2, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, now()->subHours(UsageWalletManager::AUTO_RECHARGE_ROLLING_WINDOW_HOURS)));
        $refused = $manager->autoRechargeCeilingAdmission($business, 5_000_000);
        $this->assertFalse($refused->allowed);
        $this->assertSame(UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY, $refused->denialReason);
    }

    public function test_an_idempotent_replay_of_the_same_attempt_never_consumes_the_allowance_twice(): void
    {
        [, $business] = $this->configured(WorkspacePlanTier::Growth, '10000000');
        $this->fund($business, 1_000_000);
        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $attemptId = (int) DB::table('business_funding_attempts')->where('business_id', $business->id)->value('id');

        $confirm = function () use ($attemptId): void {
            $attempt = app(BusinessFundingAttemptRepository::class)->findById($attemptId);
            $event = PaymentProviderEvent::create([
                'provider' => 'stripe', 'provider_event_id' => 'evt_fake_' . uniqid(), 'event_type' => 'payment_intent.succeeded',
                'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
                'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
            ]);
            app(UsageBillingCheckoutManager::class)->confirmAttemptFromWebhook($attempt, $event);
        };

        $confirm();
        $confirm();
        $confirm();

        $this->assertSame(1, $this->autoRechargeAttempts($business));
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attemptId)->count());
        $this->assertSame('5000000', (string) $this->walletRow($business)->recharged_this_period_micro);
        $this->assertSame('6000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame(1, app(BusinessFundingAttemptRepository::class)->countAutoRechargeAttemptsCreatedAfter((int) $business->id, now()->subDay()));
        $this->assertSame('5000000', app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000)->remainingHeadroomMicro);
    }
}
