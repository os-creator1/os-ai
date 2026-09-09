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

    private function setWallet(Business $business, array $columns): void
    {
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update($columns);
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

    /**
     * Correction Round 2 §1.1, §2 items 1, 3, 4, 5, 14 — every automatically
     * initiated attempt holds its frequency slot for the whole window
     * whatever its outcome, while a failed or cancelled attempt releases its
     * monetary headroom. Frequency and money are two separate calculations.
     */
    public function test_failed_and_cancelled_attempts_keep_their_slot_while_releasing_their_money(): void
    {
        [, $business] = $this->configured();
        $repository = app(BusinessFundingAttemptRepository::class);
        $manager = app(UsageWalletManager::class);
        $since = fn () => now()->subHours(UsageWalletManager::AUTO_RECHARGE_ROLLING_WINDOW_HOURS);

        // A ceiling of exactly one preset makes the monetary release visible:
        // if a failed attempt still held its money, the second attempt could
        // not be admitted at all.
        $this->setWallet($business, ['monthly_recharge_cap_micro' => 5_000_000]);

        // 1/4. Two declined (failed) attempts: both slots consumed, all money released.
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->assertSame(1, $this->autoRechargeAttempts($business, 'failed'));
        $this->assertSame(1, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, $since()));
        $this->assertSame(0, $repository->outstandingAutoRechargeAmountMicroForBusinesses([(int) $business->id]), 'A failed attempt reserves no money.');
        $this->assertSame('5000000', $manager->autoRechargeCeilingAdmission($business, 5_000_000)->remainingHeadroomMicro, 'Its monetary headroom is released …');

        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business, 'failed'));
        $this->assertSame(2, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, $since()), '… but its slot is not.');

        // A third automatic attempt is refused on frequency, not on money —
        // and refused before the provider, so no third attempt row appears.
        $refused = $manager->autoRechargeCeilingAdmission($business, 5_000_000);
        $this->assertFalse($refused->allowed);
        $this->assertSame(UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY, $refused->denialReason);
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business), '14. No provider call after the limit refuses admission.');
        $this->assertSame('0', (string) $this->walletRow($business)->recharged_this_period_micro);
        $this->assertSame(2, (int) $this->walletRow($business)->consecutive_recharge_failures, 'The refusal is a policy outcome, not a payment failure.');
    }

    /**
     * Correction Round 2 §2 item 2 — one failed plus one successful attempt
     * fills the window just as two failures do.
     */
    public function test_one_failed_and_one_successful_attempt_block_a_third(): void
    {
        [, $business] = $this->configured();
        $repository = app(BusinessFundingAttemptRepository::class);

        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->gateway->paymentIntentOutcomes = [];
        $this->evaluate($business);

        $this->assertSame(1, $this->autoRechargeAttempts($business, 'failed'));
        $this->assertSame(1, $this->autoRechargeAttempts($business, 'succeeded'));
        $this->assertSame(2, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, now()->subDay()));

        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business));
        $this->assertSame(UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY, app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000)->denialReason);
    }

    /**
     * Correction Round 2 §2 item 3 — two cancelled attempts block a third.
     * Cancellation is a terminal state reached out of band (no production
     * path writes it today), so it is written directly here.
     */
    public function test_two_cancelled_attempts_block_a_third(): void
    {
        [, $business] = $this->configured();
        $repository = app(BusinessFundingAttemptRepository::class);
        $manager = app(UsageWalletManager::class);

        // RFC-005 §19's one-outstanding-attempt rule means a second attempt can
        // only be created once the first has left the outstanding states, so
        // each pending attempt is cancelled before the next is initiated.
        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];
        $this->evaluate($business);
        DB::table('business_funding_attempts')->where('business_id', $business->id)->where('purpose', 'auto_recharge')->update(['state' => 'canceled']);
        $this->evaluate($business);
        DB::table('business_funding_attempts')->where('business_id', $business->id)->where('purpose', 'auto_recharge')->update(['state' => 'canceled']);
        $this->assertSame(2, $this->autoRechargeAttempts($business, 'canceled'));

        // 5. The money is released …
        $this->assertSame(0, $repository->outstandingAutoRechargeAmountMicroForBusinesses([(int) $business->id]));
        $this->assertSame('0', (string) $this->walletRow($business)->recharged_this_period_micro);
        // … and the two slots are still held.
        $this->assertSame(2, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, now()->subDay()));
        $refused = $manager->autoRechargeCeilingAdmission($business, 5_000_000);
        $this->assertFalse($refused->allowed);
        $this->assertSame(UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY, $refused->denialReason);

        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business));
    }

    /**
     * Correction Round 2 §2 item 7 — a policy refusal that happens before any
     * AutoRecharge row is created (here: no deliberately chosen Business
     * ceiling) creates no row and therefore consumes no slot.
     */
    public function test_a_pre_attempt_policy_refusal_creates_no_row_and_consumes_no_slot(): void
    {
        [, $business] = $this->configured();
        $repository = app(BusinessFundingAttemptRepository::class);

        $this->setWallet($business, ['monthly_recharge_cap_micro' => null]);
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->evaluate($business);
        $this->evaluate($business);

        $this->assertSame(0, $this->autoRechargeAttempts($business));
        $this->assertSame(0, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, now()->subDay()));

        // Both slots are still available once the ceiling is chosen.
        $this->setWallet($business, ['monthly_recharge_cap_micro' => UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO]);
        $this->gateway->paymentIntentOutcomes = [];
        $this->evaluate($business);
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business, 'succeeded'));
        $this->assertSame(2, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, now()->subDay()));
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

        // Correction Round 2 §1.2 / §2 item 9 — the administrator retry path
        // was traced mechanically, not inferred from its name: for an
        // AutoRecharge attempt it calls retrievePaymentIntent() on the
        // attempt's own frozen provider reference and confirms THAT row. It
        // creates no funding-attempt row and no second provider charge
        // operation, so it stays one slot.
        $reference = (string) $repository->findById($attemptId)->provider_session_or_intent_reference;
        $idempotencyKey = (string) $repository->findById($attemptId)->local_idempotency_key;
        $retrievalsBefore = count($this->gateway->retrievePaymentIntentCalls);
        $checkoutSessionsBefore = count($this->gateway->createCheckoutSessionCalls);

        app(UsageBillingCheckoutManager::class)->retryFundingAttemptAsAdministrator($repository->findById($attemptId), $this->platformAdminUserId(), 'Retry.');

        $retried = $repository->findById($attemptId);
        $this->assertDatabaseHas('business_funding_attempts', ['id' => $attemptId, 'state' => 'succeeded']);
        $this->assertSame($reference, (string) $retried->provider_session_or_intent_reference, 'The retry re-reads the same provider operation — no new charge.');
        $this->assertSame($idempotencyKey, (string) $retried->local_idempotency_key);
        // It only READS the existing intent (here, and once more from the receipt
        // job that follows a successful credit); it creates no charge operation.
        $this->assertGreaterThan($retrievalsBefore, count($this->gateway->retrievePaymentIntentCalls));
        $this->assertSame([$reference], array_values(array_unique(array_slice($this->gateway->retrievePaymentIntentCalls, $retrievalsBefore))), 'Every retrieval targets the same existing intent.');
        $this->assertSame($checkoutSessionsBefore, count($this->gateway->createCheckoutSessionCalls));
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

        // §2 item 10 — a genuinely new automatic provider operation is a new
        // row with its own idempotency key and provider reference, and it
        // must claim the second slot; the one after that is refused.
        $this->gateway->paymentIntentOutcomes = [];
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business, 'succeeded'));
        $fresh = DB::table('business_funding_attempts')->where('business_id', $business->id)->where('id', '!=', $attemptId)->first();
        $this->assertNotSame($idempotencyKey, (string) $fresh->local_idempotency_key, 'A genuinely new automatic operation, not a replay.');
        $this->assertNotSame($reference, (string) $fresh->provider_session_or_intent_reference);
        $this->assertSame(2, $repository->countAutoRechargeAttemptsCreatedAfter((int) $business->id, $since()));

        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        $this->evaluate($business);
        $this->assertSame(2, $this->autoRechargeAttempts($business));
        $this->assertSame(UsageWalletManager::DENIAL_AUTO_RECHARGE_FREQUENCY, app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000)->denialReason);
    }
}
