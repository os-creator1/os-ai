<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\BusinessPaymentEventState;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Jobs\BusinessPayments\ProcessBusinessPaymentEvent;
use App\Jobs\BusinessPayments\SendPaymentReceiptEmail;
use App\Library\Documents\DocumentManager;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessPaymentEvent;
use App\Models\BusinessStripeConnection;
use App\Notifications\Documents\PaymentReceiptNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §8.2 / §8.3 / §7.0 — the verified webhook
 * boundary, the whole replay table, the fail-closed cross-checks, and the
 * adversarial races. This is the sub-slice where concurrency correctness is
 * the deliverable.
 */
class PaymentWebhookAndRaceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
    }

    /**
     * @return array{fixture: array, payment: BusinessDocumentPayment}
     */
    private function startedPayment(?array $schedule = null): array
    {
        $fixture = $this->payableDocument($schedule);
        app(PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));

        return ['fixture' => $fixture, 'payment' => BusinessDocumentPayment::query()->orderByDesc('id')->first()];
    }

    private function succeedWebhook(BusinessDocumentPayment $payment, array $overrides = [])
    {
        [$body, $headers] = $this->webhookPayload(
            $overrides['type'] ?? 'payment_intent.succeeded',
            $overrides['intent'] ?? (string) $payment->provider_payment_intent_id,
            $overrides['account'] ?? 'acct_ready001',
            $overrides['amount'] ?? (int) $payment->amount_minor,
            $overrides['currency'] ?? (string) $payment->currency_code,
            array_key_exists('operation', $overrides) ? $overrides['operation'] : (string) $payment->local_idempotency_key,
            $overrides['event_id'] ?? null,
        );

        return $this->postWebhook($body, $headers);
    }

    // =================================================================
    // §8.2 — signature first, then idempotent intake
    // =================================================================

    public function test_an_invalid_signature_returns_400_and_writes_nothing(): void
    {
        $started = $this->startedPayment();
        $before = md5(DB::table('business_document_payments')->get()->toJson());

        [$body] = $this->webhookPayload('payment_intent.succeeded', (string) $started['payment']->provider_payment_intent_id,
            'acct_ready001', 50000, 'USD', (string) $started['payment']->local_idempotency_key);

        $this->postWebhook($body, ['Stripe-Signature' => 'v1=forged'])->assertStatus(400);

        $this->assertSame(0, BusinessPaymentEvent::query()->count(), 'Verification happens BEFORE any row is inserted.');
        $this->assertSame($before, md5(DB::table('business_document_payments')->get()->toJson()));
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $started['payment']->refresh()->status);
    }

    public function test_a_missing_signature_header_is_also_refused(): void
    {
        $started = $this->startedPayment();
        [$body] = $this->webhookPayload('payment_intent.succeeded', (string) $started['payment']->provider_payment_intent_id,
            'acct_ready001', 50000, 'USD', (string) $started['payment']->local_idempotency_key);

        $this->postWebhook($body, [])->assertStatus(400);
        $this->assertSame(0, BusinessPaymentEvent::query()->count());
    }

    public function test_a_duplicate_delivery_returns_200_and_re_processes_nothing(): void
    {
        Notification::fake();
        $started = $this->startedPayment();
        $eventId = 'evt_duplicate_001';

        $this->succeedWebhook($started['payment'], ['event_id' => $eventId])->assertOk();
        $this->succeedWebhook($started['payment'], ['event_id' => $eventId])->assertOk();
        $this->succeedWebhook($started['payment'], ['event_id' => $eventId])->assertOk();

        $this->assertSame(1, BusinessPaymentEvent::query()->count(), 'unique(stripe_account_id, provider_event_id) admits one.');
        $this->assertSame(1, (int) BusinessPaymentEvent::query()->sole()->attempts);
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $started['payment']->refresh()->status);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
    }

    public function test_the_same_event_id_for_a_different_account_is_a_distinct_event(): void
    {
        // Uniqueness is scoped by account, not global (§5.8).
        $started = $this->startedPayment();

        $this->succeedWebhook($started['payment'], ['event_id' => 'evt_shared'])->assertOk();
        $this->succeedWebhook($started['payment'], ['event_id' => 'evt_shared', 'account' => 'acct_other999'])->assertOk();

        $this->assertSame(2, BusinessPaymentEvent::query()->count());
    }

    public function test_an_event_for_an_unknown_account_is_recorded_and_ignored(): void
    {
        $started = $this->startedPayment();

        $this->succeedWebhook($started['payment'], ['account' => 'acct_never_seen'])->assertOk();

        $event = BusinessPaymentEvent::query()->sole();
        $this->assertNull($event->business_stripe_connection_id, 'Nullable by design, so intake never crashes.');
        $this->assertSame(BusinessPaymentEventState::Failed, $event->state);
        $this->assertSame('account_mismatch', $event->last_error);
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $started['payment']->refresh()->status);
    }

    public function test_an_unhandled_event_type_is_ignored_without_touching_a_payment(): void
    {
        $started = $this->startedPayment();

        $this->succeedWebhook($started['payment'], ['type' => 'charge.dispute.created'])->assertOk();

        $this->assertSame(BusinessPaymentEventState::Ignored, BusinessPaymentEvent::query()->sole()->state);
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $started['payment']->refresh()->status);
    }

    // =================================================================
    // §8.3 — the fail-closed cross-checks
    // =================================================================

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function crossCheckMismatches(): array
    {
        return [
            'amount' => [['amount' => 49999], 'amount_mismatch'],
            'currency' => [['currency' => 'EUR'], 'currency_mismatch'],
            'account' => [['account' => 'acct_someone_else'], 'account_mismatch'],
            'operation id' => [['operation' => 'document-payment:forged'], 'operation_id_mismatch'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('crossCheckMismatches')]
    public function test_every_cross_check_mismatch_fails_closed(array $overrides, string $expectedReason): void
    {
        $started = $this->startedPayment();

        // An account mismatch needs a real OTHER connection to exist, so the
        // event is routed yet still refused by the row's own connection.
        if (($overrides['account'] ?? null) === 'acct_someone_else') {
            $other = new BusinessStripeConnection([
                'business_id' => $started['fixture']['tenant']['business']->id,
                'stripe_account_id' => 'acct_someone_else',
            ]);
            $other->forceFill(['status' => 'disconnected', 'disconnected_at' => now()])->save();
        }

        $this->succeedWebhook($started['payment'], $overrides)->assertOk();

        $event = BusinessPaymentEvent::query()->sole();
        $this->assertSame(BusinessPaymentEventState::Failed, $event->state);
        $this->assertSame($expectedReason, $event->last_error);
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $started['payment']->refresh()->status,
            'A mismatch must never be reconciled by guessing.');
        $this->assertSame(DocumentStatus::Signed, $started['fixture']['document']->refresh()->status);
    }

    public function test_an_event_with_no_matching_local_record_fails_closed(): void
    {
        $this->startedPayment();

        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', 'pi_never_created',
            'acct_ready001', 50000, 'USD', 'document-payment:nothing');
        $this->postWebhook($body, $headers)->assertOk();

        $event = BusinessPaymentEvent::query()->sole();
        $this->assertSame(BusinessPaymentEventState::Failed, $event->state);
        $this->assertSame('no_matching_local_record', $event->last_error);
    }

    public function test_last_error_is_a_reason_code_never_a_provider_message(): void
    {
        $started = $this->startedPayment();
        $this->succeedWebhook($started['payment'], ['amount' => 1])->assertOk();

        $error = (string) BusinessPaymentEvent::query()->sole()->last_error;
        $this->assertMatchesRegularExpression('/\A[a-z_]+\z/', $error, '§8.2: a code or a class, never a message.');
    }

    // =================================================================
    // §5.7 — a historical, now-disconnected account still finalizes
    // =================================================================

    public function test_a_webhook_for_a_now_disconnected_account_still_finalizes_its_own_older_payment(): void
    {
        Notification::fake();
        $started = $this->startedPayment();
        $payment = $started['payment'];

        // The Business disconnects and connects an entirely different account.
        DB::table('business_stripe_connections')->where('id', $started['fixture']['connection']->id)
            ->update(['status' => 'disconnected', 'disconnected_at' => now()]);
        $this->chargeReadyConnection($started['fixture']['tenant']['business'], 'acct_brand_new');

        // The old account's event must still settle the old payment.
        $this->succeedWebhook($payment, ['account' => 'acct_ready001'])->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
        $this->assertSame(BusinessPaymentEventState::Processed, BusinessPaymentEvent::query()->sole()->state);
    }

    // =================================================================
    // §8.3 — the replay table
    // =================================================================

    public function test_replaying_a_success_produces_no_second_transition_or_receipt(): void
    {
        Notification::fake();
        $started = $this->startedPayment();

        $this->succeedWebhook($started['payment'], ['event_id' => 'evt_a'])->assertOk();
        $paidAt = $started['payment']->refresh()->succeeded_at;

        // A genuinely distinct delivery of the same outcome.
        $this->succeedWebhook($started['payment'], ['event_id' => 'evt_b'])->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $started['payment']->refresh()->status);
        $this->assertEquals($paidAt, $started['payment']->refresh()->succeeded_at, 'A terminal payment never moves.');
        $this->assertSame(BusinessPaymentEventState::Ignored, BusinessPaymentEvent::query()->orderByDesc('id')->first()->state);
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
    }

    public function test_a_terminal_document_accepts_no_inbound_transition(): void
    {
        $started = $this->startedPayment();
        app(DocumentManager::class)->void($started['fixture']['document'], 'Cancelled by owner.');

        $this->succeedWebhook($started['payment'])->assertOk();

        $this->assertSame(DocumentStatus::Void, $started['fixture']['document']->refresh()->status,
            'A void document never moves to paid.');
        $this->assertSame(BusinessPaymentEventState::Ignored, BusinessPaymentEvent::query()->sole()->state);
        $this->assertSame('ignored_document_terminal', BusinessPaymentEvent::query()->sole()->last_error);
    }

    public function test_concurrent_replay_of_one_event_is_claimed_exactly_once(): void
    {
        Notification::fake();
        $started = $this->startedPayment();
        $this->succeedWebhook($started['payment'])->assertOk();
        $event = BusinessPaymentEvent::query()->sole();

        $this->assertSame(BusinessPaymentEventState::Processed, $event->state);
        $attemptsAfterFirst = (int) $event->attempts;

        // A second worker picking up the same row finds it already processed:
        // the conditional claim admits only received / retryable-failed /
        // lease-expired, so this returns immediately.
        (new ProcessBusinessPaymentEvent((int) $event->id))->handle(app(\App\Library\Payments\PaymentFinalizer::class));

        $this->assertSame($attemptsAfterFirst, (int) $event->refresh()->attempts, 'A losing claim must not even count an attempt.');
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
    }

    // =================================================================
    // §7.0 — the adversarial races
    // =================================================================

    public function test_payment_success_versus_document_void(): void
    {
        $started = $this->startedPayment();

        // Void wins the race: the webhook arrives afterwards.
        app(DocumentManager::class)->void($started['fixture']['document'], 'Cancelled.');
        $this->succeedWebhook($started['payment'])->assertOk();

        $this->assertSame(DocumentStatus::Void, $started['fixture']['document']->refresh()->status);
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $started['payment']->refresh()->status);
    }

    public function test_payment_success_versus_resend_and_revision(): void
    {
        Notification::fake();

        // An INVOICE is payable while merely `sent`, which is the only way a
        // live attempt and a revision can genuinely race — a signed proposal
        // cannot be revised at all (§7.1), which is its own protection.
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);
        $manager = app(DocumentManager::class);

        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null,
            'invoice', 'Kitchen invoice', $tenant['customer']->user);
        $manager->addCustomLine($document, 'Work', null, 1, 50000);
        $manager->setSchedule($document, [['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD']]);
        $manager->edit($document, ['recipient_email_snapshot' => 'client@example.test']);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        // A payment is in flight against version 1's schedule item.
        app(PaymentManager::class)->start($this->accessFor($document, $token));
        $payment = BusinessDocumentPayment::query()->sole();
        $oldItemId = (int) $payment->schedule_item_id;

        // The owner revises and re-sends underneath it.
        $manager->revise($document->refresh(), $tenant['customer']->user);
        [$document, $newToken] = $this->sendAndCaptureToken($document->refresh());

        // The in-flight charge is REAL, so it still finalizes — money is
        // never lost — but it settles only the superseded item.
        $this->succeedWebhook($payment)->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(PaymentScheduleItemStatus::Paid, BusinessDocumentPaymentScheduleItem::query()->find($oldItemId)->status);

        // ...and it must NOT declare the document paid off a superseded
        // version's schedule.
        $this->assertSame(DocumentStatus::Sent, $document->refresh()->status);

        $currentItem = BusinessDocumentPaymentScheduleItem::query()
            ->where('business_document_version_id', $document->current_version_id)->sole();
        $this->assertNotSame($oldItemId, (int) $currentItem->id);
        $this->assertSame(PaymentScheduleItemStatus::Pending, $currentItem->status);

        // The current version's own item is what a new attempt targets.
        app(PaymentManager::class)->start($this->accessFor($document->refresh(), $newToken));
        $second = BusinessDocumentPayment::query()->orderByDesc('id')->first();
        $this->assertSame((int) $currentItem->id, (int) $second->schedule_item_id);
    }

    public function test_two_callbacks_for_one_payment_settle_it_once(): void
    {
        Notification::fake();
        $started = $this->startedPayment();

        // Two distinct events, same outcome, delivered back to back.
        $this->succeedWebhook($started['payment'], ['event_id' => 'evt_one'])->assertOk();
        $this->succeedWebhook($started['payment'], ['event_id' => 'evt_two'])->assertOk();

        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $started['payment']->refresh()->status);
        $this->assertSame(1, BusinessDocumentPaymentScheduleItem::query()->where('status', PaymentScheduleItemStatus::Paid->value)->count());
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
    }

    public function test_deposit_and_balance_racing_settle_independently_and_in_order(): void
    {
        Notification::fake();
        $started = $this->startedPayment($this->depositAndBalance());
        $deposit = $started['payment'];
        $this->assertSame(20000, (int) $deposit->amount_minor);

        // The deposit settles.
        $this->succeedWebhook($deposit)->assertOk();
        $this->assertSame(DocumentStatus::Signed, $started['fixture']['document']->refresh()->status,
            'One settled item is not a settled document.');

        // Only now can the balance start.
        app(PaymentManager::class)->start($this->accessFor($started['fixture']['document']->refresh(), $started['fixture']['token']));
        $balance = BusinessDocumentPayment::query()->orderByDesc('id')->first();
        $this->assertSame(30000, (int) $balance->amount_minor);

        $this->succeedWebhook($balance)->assertOk();

        $this->assertSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
        $this->assertSame(2, BusinessDocumentPaymentScheduleItem::query()->where('status', PaymentScheduleItemStatus::Paid->value)->count());
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 2);
    }

    // =================================================================
    // Blueprint §9 — a payment never advances an Opportunity
    // =================================================================

    public function test_paying_a_document_leaves_its_opportunity_stage_unchanged(): void
    {
        Notification::fake();
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);

        $pipeline = app(\App\Library\Crm\CrmPipelineService::class)->setUpStandardPipeline($tenant['business']);
        $opportunity = app(\App\Library\Crm\CrmOpportunityService::class)
            ->create($tenant['business'], $pipeline, $tenant['contact'], 'Kitchen job', null, null);
        DB::table('crm_opportunities')->where('id', $opportunity->id)->update(['location_id' => $tenant['location']->id]);

        $manager = app(DocumentManager::class);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'],
            $opportunity->fresh(), 'invoice', 'Kitchen invoice', $tenant['customer']->user);
        $manager->addCustomLine($document, 'Work', null, 1, 50000);
        $manager->setSchedule($document, [['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD']]);
        $manager->edit($document, ['recipient_email_snapshot' => 'client@example.test']);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        $stageBefore = $opportunity->refresh()->stage_id;

        app(PaymentManager::class)->start($this->accessFor($document, $token));
        $payment = BusinessDocumentPayment::query()->sole();
        $this->succeedWebhook($payment)->assertOk();

        $this->assertSame(DocumentStatus::Paid, $document->refresh()->status);
        $this->assertSame($stageBefore, $opportunity->refresh()->stage_id,
            'Blueprint §9 forbids payment progress advancing a pipeline stage.');
    }

    // =================================================================
    // Receipt dedupe (§8.4)
    // =================================================================

    public function test_the_receipt_marker_makes_a_replayed_job_send_exactly_one_email(): void
    {
        Notification::fake();
        $started = $this->startedPayment();
        $this->succeedWebhook($started['payment'])->assertOk();

        $this->assertNotNull($started['payment']->refresh()->receipt_sent_at);

        // Re-running the job directly, as a retry would.
        (new SendPaymentReceiptEmail((int) $started['payment']->id))->handle();
        (new SendPaymentReceiptEmail((int) $started['payment']->id))->handle();

        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
    }

}
