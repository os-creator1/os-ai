<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Notifications\Usage\SpendingLimitReachedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — T-CAP-1 (Business monthly limit),
 * T-CAP-2 (Workspace aggregate limit), T-CAP-3 (insufficient funds refuses
 * before the provider call with a task-oriented message), T-CAP-5 (the
 * emergency stop) and the reservation-before-provider rule of T-WALLET-3,
 * all with exact integer boundaries in micro-units.
 *
 * Gated (§28.1a): every amount here uses an explicit fixture rate
 * ($1.00 retail / $0.60 provider cost per unit); the same assertions in
 * real retail currency await the approved rate card.
 */
class SpendingCapBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    private bool $providerCalled = false;

    /**
     * The caller-side shape RFC-005 mandates: reserve, and only on a
     * granted reservation touch the provider. The fake provider seam
     * records whether it was ever reached.
     */
    private function sendWithReservation(Business $business, string $quantity): \App\Library\Usage\ReservationResult
    {
        $result = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), $quantity);

        if ($result->granted) {
            $this->providerCalled = true;
        }

        return $result;
    }

    public function test_the_business_monthly_limit_admits_the_exact_boundary_and_refuses_one_unit_above(): void
    {
        [$owner, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->activateFixtureRate('crm', '1000000');
        $this->fund($business, 100_000_000);
        app(UsageWalletManager::class)->setSpendCap($business, '3000000', (int) $owner->user_id, 'Limit.');

        $this->assertTrue($this->sendWithReservation($business, '2')->granted);
        $exact = $this->sendWithReservation($business, '1');
        $this->assertTrue($exact->granted, 'Exactly the remaining allowance succeeds.');

        $this->providerCalled = false;
        $over = $this->sendWithReservation($business, '0.000001');
        $this->assertFalse($over->granted);
        $this->assertSame('business_spend_cap', $over->denialReason);
        $this->assertFalse($this->providerCalled, 'Refused before any provider work.');
        $this->assertSame(2, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());

        // The refusal reads as a task, not a key.
        $message = app(UsageWalletManager::class)->customerMessageForDenial('business_spend_cap');
        $this->assertStringContainsString('monthly spending limit', $message);
        $this->assertStringNotContainsString('_', $message);
    }

    public function test_one_unit_above_the_limit_is_refused_even_as_the_first_reservation(): void
    {
        [$owner, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->activateFixtureRate('crm', '1000000');
        $this->fund($business, 100_000_000);
        app(UsageWalletManager::class)->setSpendCap($business, '3000000', (int) $owner->user_id, 'Limit.');

        $this->assertFalse($this->sendWithReservation($business, '3.000001')->granted);
        $this->assertTrue($this->sendWithReservation($business, '3')->granted);
    }

    public function test_the_workspace_aggregate_limit_admits_the_exact_boundary_across_agency_paid_businesses_and_refuses_one_unit_above(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [, $clientA] = $this->clientBusiness($workspace, 'Client A');
        [, $clientB] = $this->clientBusiness($workspace, 'Client B');
        [, $selfPaid] = $this->clientBusiness($workspace, 'Self Paid');
        $this->setPayer($clientA, PayerType::Workspace);
        $this->setPayer($clientB, PayerType::Workspace);
        $this->setPayer($selfPaid, PayerType::Business);
        $this->activateFixtureRate('crm', '1000000');
        foreach ([$clientA, $clientB, $selfPaid] as $b) {
            $this->fund($b, 100_000_000);
        }

        app(UsageWalletManager::class)->setWorkspaceAggregateSpendCap($workspace, '5000000', (int) $agency->user_id, 'Agency limit.');

        $this->assertTrue($this->sendWithReservation($clientA, '3')->granted);
        $this->assertTrue($this->sendWithReservation($clientB, '2')->granted, 'Client B takes exactly the last of the aggregate allowance.');

        $this->providerCalled = false;
        $over = $this->sendWithReservation($clientA, '0.000001');
        $this->assertFalse($over->granted);
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_SPEND_CAP, $over->denialReason);
        $this->assertFalse($this->providerCalled);

        // A client-paid Business in the same Workspace is not subject to the Agency limit.
        $this->assertTrue($this->sendWithReservation($selfPaid, '50')->granted);

        $this->assertStringContainsString('agency-wide monthly spending limit', app(UsageWalletManager::class)->customerMessageForDenial(UsageWalletManager::DENIAL_WORKSPACE_SPEND_CAP));
    }

    public function test_insufficient_funds_refuses_before_the_provider_call_with_a_task_oriented_message_and_no_partial_charge(): void
    {
        [$owner, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->activateFixtureRate('crm', '1000000');
        $this->fund($business, 2_500_000);
        $this->billingContact($business, (int) $owner->user_id);
        Notification::fake();

        $result = $this->sendWithReservation($business, '3');

        $this->assertFalse($result->granted);
        $this->assertSame('insufficient_balance', $result->denialReason);
        $this->assertFalse($this->providerCalled);
        $this->assertSame('2500000', (string) $this->walletRow($business)->available_balance_micro, 'No partial charge.');
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());

        $message = app(UsageWalletManager::class)->customerMessageForDenial('insufficient_balance');
        $this->assertStringContainsString('Add funds', $message);
        $this->assertStringContainsString('nothing was charged', $message);

        Notification::assertSentTo(new AnonymousNotifiable(), SpendingLimitReachedNotification::class, function (SpendingLimitReachedNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
            return $notification->reason === 'insufficient_balance' && $notifiable->routes['mail'] === 'billing@example.test';
        });

        // Once per period: a second refusal does not send again.
        $this->sendWithReservation($business, '3');
        Notification::assertSentTimes(SpendingLimitReachedNotification::class, 1);
    }

    public function test_exactly_the_available_balance_is_admitted(): void
    {
        [, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->activateFixtureRate('crm', '1000000');
        $this->fund($business, 3_000_000);

        $this->assertTrue($this->sendWithReservation($business, '3')->granted);
        $this->assertSame('0', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertFalse($this->sendWithReservation($business, '0.000001')->granted);
    }

    public function test_the_business_emergency_stop_refuses_new_paid_work_before_any_provider_call_and_keeps_history(): void
    {
        [$owner, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->activateFixtureRate('crm', '1000000');
        $this->fund($business, 100_000_000);

        $earlier = $this->sendWithReservation($business, '1');
        $this->assertTrue($earlier->granted);
        $ledgerBefore = DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count();

        app(UsageWalletManager::class)->pausePaidActivity($business, (int) $owner->user_id, 'Emergency.');

        $this->providerCalled = false;
        $refused = $this->sendWithReservation($business, '1');
        $this->assertFalse($refused->granted);
        $this->assertSame(UsageWalletManager::DENIAL_PAID_ACTIVITY_PAUSED, $refused->denialReason);
        $this->assertFalse($this->providerCalled, 'The fake provider seam is never reached while paused.');

        // The earlier reservation is neither corrupted nor silently settled: it still commits normally.
        $commit = app(UsageWalletManager::class)->commit($earlier->reservationId, '1');
        $this->assertSame('1000000', $commit->finalAmountMicro);
        $this->assertGreaterThan($ledgerBefore, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count(), 'History is kept and extended, never erased.');
        $this->assertDatabaseHas('usage_control_transitions', ['scope' => 'business', 'scope_id' => $business->id, 'control' => UsageWalletManager::CONTROL_BUSINESS_PAID_ACTIVITY, 'to_value' => 'paused', 'actor_user_id' => $owner->user_id]);

        app(UsageWalletManager::class)->resumePaidActivity($business, (int) $owner->user_id, 'Resume.');
        $this->assertTrue($this->sendWithReservation($business, '1')->granted);

        $this->assertStringContainsString('Paid activity is paused', app(UsageWalletManager::class)->customerMessageForDenial(UsageWalletManager::DENIAL_PAID_ACTIVITY_PAUSED));
    }

    public function test_the_workspace_emergency_stop_refuses_every_business_of_the_workspace(): void
    {
        [$agency, $agencyBusiness, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [$client, $clientBusiness] = $this->clientBusiness($workspace, 'Client A');
        $this->setPayer($clientBusiness, PayerType::Business);
        $this->activateFixtureRate('crm', '1000000');
        $this->fund($agencyBusiness, 100_000_000);
        $this->fund($clientBusiness, 100_000_000);

        app(UsageWalletManager::class)->pauseWorkspacePaidActivity($workspace, (int) $agency->user_id, 'Emergency.');

        foreach ([$agencyBusiness, $clientBusiness] as $b) {
            $this->providerCalled = false;
            $refused = $this->sendWithReservation($b, '1');
            $this->assertFalse($refused->granted);
            $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_PAID_ACTIVITY_PAUSED, $refused->denialReason);
            $this->assertFalse($this->providerCalled);
        }

        // A Business user cannot lift or set the Workspace stop.
        try {
            app(UsageWalletManager::class)->resumeWorkspacePaidActivity($workspace, (int) $client->user_id, 'Denied.');
            $this->fail('A Business user must not control the Workspace stop.');
        } catch (UnauthorizedUsageBillingManagementException) {
            $this->assertFalse($this->sendWithReservation($clientBusiness, '1')->granted);
        }

        app(UsageWalletManager::class)->resumeWorkspacePaidActivity($workspace, (int) $agency->user_id, 'Resume.');
        $this->assertTrue($this->sendWithReservation($clientBusiness, '1')->granted);
    }

    public function test_a_reservation_is_released_on_abandonment_and_expiry_restores_the_balance(): void
    {
        [, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->activateFixtureRate('crm', '1000000');
        $this->fund($business, 10_000_000);

        $reservation = $this->sendWithReservation($business, '4');
        $this->assertSame('6000000', (string) $this->walletRow($business)->available_balance_micro);

        app(UsageWalletManager::class)->release($reservation->reservationId);
        $this->assertSame('10000000', (string) $this->walletRow($business)->available_balance_micro);

        $stale = $this->sendWithReservation($business, '4');
        DB::table('business_usage_reservations')->where('id', $stale->reservationId)->update(['expires_at' => now()->subMinutes(5)]);
        app(UsageWalletManager::class)->expireStaleReservations();
        $this->assertSame('10000000', (string) $this->walletRow($business)->available_balance_micro);
    }

    public function test_every_denial_reason_has_a_customer_sentence_without_internal_vocabulary(): void
    {
        $manager = app(UsageWalletManager::class);

        foreach (['insufficient_balance', 'business_spend_cap', 'workspace_spend_cap', 'feature_limit', 'platform_safety_limit', 'paid_activity_paused', 'workspace_paid_activity_paused', 'wallet_suspended', 'outstanding_debt', 'below_minimum_top_up', 'something_unknown'] as $reason) {
            $message = $manager->customerMessageForDenial($reason);

            $this->assertNotSame('', $message, $reason);
            $this->assertStringNotContainsString('_', $message, $reason);
            $this->assertStringNotContainsStringIgnoringCase('reservation', $message, $reason);
            $this->assertStringNotContainsStringIgnoringCase('meter', $message, $reason);
            $this->assertStringNotContainsStringIgnoringCase('workspace', $message, $reason);
        }
    }
}
