<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\PaymentProviderEvent;
use App\Notifications\Usage\SpendingLimitReachedNotification;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — Correction Round 1 §2.5, §7 and §13.6
 * (tests 34–41, 43, 44): at most two automatic top-ups per Business in any
 * rolling 24 hours, on precise timestamps, derived from the authoritative
 * funding attempts (pending claims count, terminal failures do not, manual
 * top-ups never do, a replay of one attempt counts once), refused before
 * the provider call with a plain-language message and one opted-in alert
 * per window.
 */
class AutoRechargeRollingWindowTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // Every path here mails the opted-in billing contact (receipts, low-balance
        // and refusal alerts); the fake keeps the real transport out of the way.
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Threshold high enough that every evaluation triggers while the balance is 1,000,000. */
    private function configured(): array
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->billingContact($business, (int) $owner->user_id);
        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '20000000', '5000000', (string) UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO, (int) $owner->user_id);
        $this->fund($business, 1_000_000);

        return [$owner, $business, $workspace];
    }

    private function autoRechargeAttempts(Business $business, ?string $state = null): int
    {
        $query = DB::table('business_funding_attempts')->where('business_id', $business->id)->where('purpose', 'auto_recharge');

        return $state === null ? $query->count() : $query->where('state', $state)->count();
    }

    private function evaluate(Business $business): void
    {
        $this->fund($business, 1_000_000);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
    }

    public function test_the_first_two_automatic_top_ups_run_and_the_third_is_refused_before_the_provider_with_one_alert(): void
    {
        [, $business] = $this->configured();

        // 34/35.
        $this->evaluate($business);
        $this->assertSame('6000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->evaluate($business);
        $this->assertSame('6000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame(2, $this->autoRechargeAttempts($business, 'succeeded'));

        // 36. The third: refused before the fake gateway (a provider call would leave a failed attempt).
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $wallet = $this->walletRow($business);
        $this->assertSame('1000000', (string) $wallet->available_balance_micro, 'No balance increase on refusal.');
        $this->assertSame(2, $this->autoRechargeAttempts($business));
        $this->assertSame(0, $this->autoRechargeAttempts($business, 'failed'));
        $this->assertSame(0, (int) $wallet->consecutive_recharge_failures);
        $this->assertSame(1, (int) $wallet->auto_recharge_enabled);
        $this->assertNotNull($wallet->auto_recharge_refusal_notified_at);

        $admission = app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000);
        $this->assertFalse($admission->allowed);
        $this->assertSame(UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY, $admission->denialReason);

        // 43. Plain language: the safety limit, nothing charged, manual funding available; nothing internal.
        $message = app(UsageWalletManager::class)->customerMessageForDenial(UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY);
        $this->assertStringContainsString('at most twice in any 24 hours', $message);
        $this->assertStringContainsString('No automatic charge was made', $message);
        $this->assertStringContainsString('add funds manually', $message);
        foreach (['requires_action', 'provider_pending', 'attempt', 'funding', 'payer_type'] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $message);
        }

        // 44. The opted-in billing contact is alerted exactly once per window, however often the job re-runs.
        Notification::assertSentTo(
            new AnonymousNotifiable(),
            SpendingLimitReachedNotification::class,
            fn (SpendingLimitReachedNotification $n, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'billing@example.test' && $n->reason === UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY,
        );
        $this->evaluate($business);
        $this->evaluate($business);
        Notification::assertSentTimes(SpendingLimitReachedNotification::class, 1);
        $this->assertSame(2, $this->autoRechargeAttempts($business));
    }

    public function test_the_window_boundary_is_exactly_twenty_four_hours_on_precise_timestamps(): void
    {
        $start = Carbon::parse('2026-09-09 10:00:00', 'UTC');
        Carbon::setTestNow($start);
        [, $business] = $this->configured();

        // Two automatic top-ups an hour apart: the first at T0, the second at T0 + 1h.
        $this->evaluate($business);
        Carbon::setTestNow($start->copy()->addHour());
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business, 'succeeded'));

        // One second short of 24 hours: the oldest attempt still counts — refused.
        Carbon::setTestNow($start->copy()->addHours(24)->subSecond());
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business));
        $this->assertSame(UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY, app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000)->denialReason);

        // Exactly 24 hours old no longer counts (created_at > now - 24h is the rule): one slot opens.
        Carbon::setTestNow($start->copy()->addHours(24));
        $this->gateway->paymentIntentOutcomes = [];
        $this->assertSame(1, app(BusinessFundingAttemptRepository::class)->countAutoRechargeAttemptsCreatedAfter((int) $business->id, Carbon::now()->subHours(UsageWalletManager::AUTO_RECHARGE_ROLLING_WINDOW_HOURS)));
        $this->evaluate($business);
        $this->assertSame(3, $this->autoRechargeAttempts($business, 'succeeded'));
        $this->assertSame('6000000', (string) $this->walletRow($business)->available_balance_micro);

        // The slot that opened is used again; the next one is refused until the second attempt ages out.
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->assertSame(3, $this->autoRechargeAttempts($business));
    }

    public function test_manual_top_ups_never_count_against_the_window(): void
    {
        [$owner, $business] = $this->configured();

        // Two manual top-ups (Checkout-backed attempts, purpose manual_top_up) …
        app(UsageBillingCheckoutManager::class)->initiateTopUp($business, (int) $owner->user_id, 5_000_000);
        app(UsageBillingCheckoutManager::class)->initiateTopUp($business, (int) $owner->user_id, 5_000_000);
        $this->assertSame(2, DB::table('business_funding_attempts')->where('business_id', $business->id)->where('purpose', 'manual_top_up')->count());

        // … leave both automatic slots available.
        $this->evaluate($business);
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business, 'succeeded'));
        $this->assertSame(2, app(BusinessFundingAttemptRepository::class)->countAutoRechargeAttemptsCreatedAfter((int) $business->id, now()->subDay()));
    }

    public function test_pending_attempts_count_while_failed_and_cancelled_attempts_do_not(): void
    {
        [, $business] = $this->configured();
        $repository = app(BusinessFundingAttemptRepository::class);
        $since = fn () => now()->subHours(UsageWalletManager::AUTO_RECHARGE_ROLLING_WINDOW_HOURS);

        // 40. Two declined (failed) attempts: nothing counts, both slots remain.
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business, 'failed'));
        $this->assertSame(0, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, $since()));
        $this->assertTrue(app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000)->allowed);

        // One success, then a pending (requires-action) attempt: 2 counted, the window is full.
        $this->gateway->paymentIntentOutcomes = [];
        $this->evaluate($business);
        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];
        $this->evaluate($business);
        $this->assertSame(1, $this->autoRechargeAttempts($business, 'requires_action'));
        $this->assertSame(2, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, $since()));
        $this->assertSame(UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY, app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000)->denialReason);

        // The pending attempt is cancelled: its slot is released.
        DB::table('business_funding_attempts')->where('business_id', $business->id)->where('state', 'requires_action')->update(['state' => 'canceled']);
        $this->assertSame(1, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, $since()));
        $this->assertTrue(app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000)->allowed);
    }

    public function test_retrying_or_replaying_the_same_attempt_counts_once(): void
    {
        [, $business] = $this->configured();
        $repository = app(BusinessFundingAttemptRepository::class);
        $since = fn () => now()->subHours(UsageWalletManager::AUTO_RECHARGE_ROLLING_WINDOW_HOURS);

        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];
        $this->evaluate($business);
        $attemptId = (int) DB::table('business_funding_attempts')->where('business_id', $business->id)->value('id');
        $this->assertSame(1, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, $since()));

        // The platform-administrator retry re-syncs the same attempt: no new provider charge, same row.
        app(UsageBillingCheckoutManager::class)->retryFundingAttemptAsAdministrator($repository->findById($attemptId), $this->platformAdminUserId(), 'Retry.');
        $this->assertDatabaseHas('business_funding_attempts', ['id' => $attemptId, 'state' => 'succeeded']);
        $this->assertSame(1, $this->autoRechargeAttempts($business));
        $this->assertSame(1, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, $since()));

        // A replayed webhook for the same attempt: still one attempt, one ledger row, one slot.
        $attempt = $repository->findById($attemptId);
        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_' . uniqid(), 'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromWebhook($attempt, $event);
        $this->assertSame(1, $this->autoRechargeAttempts($business));
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attemptId)->count());
        $this->assertSame(1, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, $since()));
        $this->assertSame('5000000', (string) $this->walletRow($business)->recharged_this_period_micro);

        // One slot is left: the next evaluation still runs, the one after that is refused.
        $this->gateway->paymentIntentOutcomes = [];
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business, 'succeeded'));
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business));
    }
}
