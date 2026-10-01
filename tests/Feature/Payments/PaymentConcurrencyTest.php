<?php

namespace Tests\Feature\Payments;

use App\Library\Documents\DocumentContentHasher;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentVersion;
use App\Models\BusinessPaymentEvent;
use App\Models\BusinessStripeConnection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Payments & Invoices V1 — REAL database concurrency for the money paths.
 *
 * Every race runs in separate OS processes on separate database connections
 * (see Support/concurrent_payment_runner.php); nothing mocks a lock. Only
 * Stripe is faked, by a file-backed gateway shared by the children, which also
 * refuses to be called inside a DB transaction (Contract 17 §7).
 *
 * Each race is SYNCHRONIZED (a shared epoch start) and PROVEN to have
 * overlapped (`ENTERED` timestamps), so a green run cannot be two sequential
 * calls in disguise. Assertions are on INVARIANTS that must hold for every
 * interleaving, because the interleaving itself is the thing that varies.
 *
 * Deliberately not RefreshDatabase: a separate process needs COMMITTED rows,
 * which an open per-test transaction would hide. A fresh schema is migrated
 * once before the class and again after its last test (the same isolation
 * as UsesFreshSchema, shared across the class because migrating is the
 * expensive part). Every test builds its OWN tenant with globally unique
 * identifiers and asserts only on rows it created, so the tests do not
 * depend on each other or on their order.
 */
class PaymentConcurrencyTest extends TestCase
{
    use CreatesPayableDocuments;

    /** One fresh schema is shared by the whole class: migrating costs far more than any race. */
    private static bool $schemaPrepared = false;

    /** Tests of this class still to finish; the last one restores a clean schema. */
    private static int $remaining = 0;

    /** How far ahead the shared start instant is set, to absorb child boot time. */
    private const START_GATE_MICROSECONDS = 4_000_000;

    /** Two children that genuinely raced entered within this of each other. */
    private const MAX_ENTRY_SKEW_MICROSECONDS = 1_500_000;

    private string $callsFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(TestDatabaseSafety::activeTestDatabase(), DB::connection()->getDatabaseName());
        $this->prepareSharedSchema();
        $this->bindFakeGateway();
        // The schema is shared across the class, and provider intent ids are
        // globally unique: each test's fake therefore counts from its own range.
        $this->gateway->intentSequence = random_int(10_000, 800_000);
        Notification::fake();

        $this->callsFile = tempnam(sys_get_temp_dir(), 'pay-calls-');
        $this->beforeApplicationDestroyed(function (): void {
            @unlink($this->callsFile);
        });
    }

    private function prepareSharedSchema(): void
    {
        if (! self::$schemaPrepared) {
            $this->freshSchema();
            self::$schemaPrepared = true;
            self::$remaining = count(array_filter(get_class_methods(static::class), static fn (string $m) => str_starts_with($m, 'test_')));
        }

        $this->beforeApplicationDestroyed(function (): void {
            if (--self::$remaining <= 0) {
                $this->freshSchema();
                RefreshDatabaseState::$migrated = true;
                self::$schemaPrepared = false;
            }
        });
    }

    private function freshSchema(): void
    {
        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    // =================================================================
    // Payment initiation
    // =================================================================

    public function test_concurrent_and_duplicate_payment_starts_yield_one_attempt_under_one_provider_key(): void
    {
        $fixture = $this->payableDocument(null, true, 'acct_' . Str::random(14));
        $uid = (string) $fixture['document']->uid;

        $results = $this->race([
            ['start', $uid, $fixture['token']],
            ['start', $uid, $fixture['token']],
            ['start', $uid, $fixture['token']],
        ]);

        $this->assertGenuinelyRaced($results);
        $this->assertSame([0, 0, 0], array_column($results, 'exitCode'), 'A duplicate click is re-driven, never refused or errored.');

        $this->assertSame(1, BusinessDocumentPayment::query()->where('business_document_id', $fixture['document']->id)->count(), 'unique(active_schedule_item_id): one live attempt.');
        $payment = BusinessDocumentPayment::query()->where('business_document_id', $fixture['document']->id)->sole();

        $uids = array_unique(array_map(fn ($r) => $this->field($r['stdout'], 'payment_uid'), $results));
        $this->assertSame([(string) $payment->uid], array_values($uids), 'Every browser got the SAME attempt back.');

        // However many provider calls were made (a re-drive repeats creation
        // under the same key), they all carry ONE idempotency key — so Stripe
        // can only ever have created one intent.
        $keys = array_unique($this->providerCreateKeys());
        $this->assertSame(['document-payment:' . $payment->uid], array_values($keys));
        $this->assertNotNull($payment->provider_payment_intent_id);
        $this->assertSame(50000, (int) $payment->amount_minor, 'The amount is the persisted schedule item, not a request value.');
    }

    public function test_concurrent_retries_after_payment_failed_converge_on_the_same_row_and_intent(): void
    {
        [$payment, $document, $token] = $this->inFlightPaymentWithLink();
        $this->declineInParent($payment);
        $this->assertSame('failed', $payment->refresh()->status->value);
        $this->truncateCalls();
        $intent = (string) $payment->provider_payment_intent_id;

        $results = $this->race([
            ['start', (string) $document->uid, $token],
            ['start', (string) $document->uid, $token],
            ['start', (string) $document->uid, $token],
        ]);

        $this->assertGenuinelyRaced($results);
        $this->assertSame([0, 0, 0], array_column($results, 'exitCode'), 'A retry after a decline is re-driven, never refused or errored.');
        $this->assertSame(
            [(string) $payment->uid],
            array_values(array_unique(array_map(fn ($r) => $this->field($r['stdout'], 'payment_uid'), $results))),
            'Every browser got the SAME attempt back.'
        );

        $this->assertSame(1, BusinessDocumentPayment::query()->where('business_document_id', $document->id)->count(), 'No second payment row, however the retries interleave.');
        $this->assertSame([], $this->providerCreateKeys(), 'No PaymentIntent was created for a retry.');
        $retrieved = array_filter($this->callLines(), fn ($line) => str_starts_with($line, 'provider|retrieve|'));
        $this->assertNotEmpty($retrieved);
        foreach ($retrieved as $line) {
            $this->assertSame('provider|retrieve|' . $intent, $line, 'Only the original intent was ever touched.');
        }

        $payment->refresh();
        $this->assertSame('failed', $payment->status->value);
        $this->assertSame($intent, (string) $payment->provider_payment_intent_id);
    }

    public function test_a_success_racing_retries_on_a_failed_attempt_settles_the_one_row_once(): void
    {
        foreach (range(1, 2) as $round) {
            [$payment, $document, $token] = $this->inFlightPaymentWithLink();
            $this->declineInParent($payment);
            $this->truncateCalls();

            $results = $this->race([
                ['finalize', (string) $payment->id, 'succeeded'],
                ['start', (string) $document->uid, $token],
                ['start', (string) $document->uid, $token],
            ]);

            $this->assertGenuinelyRaced($results);
            $this->assertSame(0, $results[0]['exitCode']);
            foreach ([1, 2] as $i) {
                $this->assertContains($results[$i]['exitCode'], [0, 4], 'A start either re-drives the row or finds nothing left to pay.');
            }

            $this->assertSame(1, BusinessDocumentPayment::query()->where('business_document_id', $document->id)->count(), "Round {$round}: still one row.");
            $this->assertSame('succeeded', $payment->refresh()->status->value);
            $this->assertSame('paid', $document->refresh()->status->value);
            $this->assertSame(1, $this->eventCount('DocumentPaymentSucceeded'));
            $this->assertSame(1, $this->eventCount('DocumentFullyPaid'));
            $this->assertSame([], $this->providerCreateKeys());
        }
    }

    // =================================================================
    // Payment versus void
    // =================================================================

    public function test_payment_success_versus_void_never_yields_a_paid_and_voided_invoice_and_never_loses_the_money(): void
    {
        foreach (range(1, 3) as $round) {
            $payment = $this->inFlightPayment();
            $document = BusinessDocument::query()->findOrFail($payment->business_document_id);
            $this->truncateCalls();

            $results = $this->race([
                ['finalize', (string) $payment->id, 'succeeded'],
                ['void', (string) $document->id],
            ]);

            $this->assertGenuinelyRaced($results);
            $this->assertSame(0, $results[0]['exitCode'], 'The provider-confirmed payment is always accepted.');
            $this->assertContains($results[1]['exitCode'], [0, 4], 'Void either wins or is refused by a DOMAIN rule — never a driver error.');

            $document->refresh();
            $item = BusinessDocumentPaymentScheduleItem::query()->findOrFail($payment->schedule_item_id);

            $this->assertSame('succeeded', $payment->refresh()->status->value, "Round {$round}: real money is ALWAYS on the ledger.");
            $this->assertContains($document->status->value, ['paid', 'void'], "Round {$round}: exactly one terminal outcome.");

            if ($document->status->value === 'paid') {
                $this->assertSame('paid', $item->status->value);
                $this->assertSame(4, $results[1]['exitCode'], 'A paid invoice cannot then be voided.');
                $this->assertSame(1, $this->eventCount('DocumentFullyPaid'));
                $this->assertSame(0, $this->eventCount('DocumentVoided'));
            } else {
                $this->assertNotSame('paid', $item->status->value, 'A void invoice never has a paid schedule item.');
                $this->assertNull($document->paid_at);
                $this->assertSame(0, $this->eventCount('DocumentFullyPaid'));
                $this->assertSame(1, $this->eventCount('DocumentVoided'));
            }

            $this->assertSame(1, $this->eventCount('DocumentPaymentSucceeded'), "Round {$round}: exactly one payment event.");
        }
    }

    // =================================================================
    // Duplicate and out-of-order webhooks
    // =================================================================

    public function test_two_workers_on_one_webhook_event_claim_it_once(): void
    {
        $payment = $this->inFlightPayment();
        $event = $this->storeEvent($payment, 'payment_intent.succeeded', 'evt_claim_once');
        $this->truncateCalls();

        $results = $this->race([['job', (string) $event->id], ['job', (string) $event->id]]);

        $this->assertGenuinelyRaced($results);
        $this->assertSame([0, 0], array_column($results, 'exitCode'));

        $event->refresh();
        $this->assertSame('processed', $event->state->value);
        $this->assertSame(1, (int) $event->attempts, 'The losing claim does not even count an attempt.');
        $this->assertSame('succeeded', $payment->refresh()->status->value);
        $this->assertSame('paid', BusinessDocument::query()->findOrFail($payment->business_document_id)->status->value);
        $this->assertSame(1, $this->eventCount('DocumentPaymentSucceeded'));
        $this->assertSame(1, $this->eventCount('DocumentFullyPaid'));
    }

    public function test_the_same_provider_event_delivered_twice_at_once_is_stored_and_applied_once(): void
    {
        $payment = $this->inFlightPayment();
        $body = $this->body($payment, 'payment_intent.succeeded', 'evt_dup_http');
        $this->truncateCalls();

        $results = $this->race([['webhook', base64_encode($body)], ['webhook', base64_encode($body)]]);

        $this->assertGenuinelyRaced($results);
        $this->assertSame(['http=200', 'http=200'], array_map(fn ($r) => $this->field($r['stdout'], 'http', 'http='), $results));
        $this->assertSame(1, BusinessPaymentEvent::query()->where('provider_event_id', 'evt_dup_http')->count(), 'unique(stripe_account_id, provider_event_id)');
        $this->assertSame('succeeded', $payment->refresh()->status->value);
        $this->assertSame(1, $this->eventCount('DocumentPaymentSucceeded'));
        $this->assertSame(1, $this->eventCount('DocumentFullyPaid'));
    }

    public function test_two_different_success_events_for_one_intent_pay_the_invoice_once(): void
    {
        $payment = $this->inFlightPayment();
        $this->truncateCalls();

        $results = $this->race([
            ['webhook', base64_encode($this->body($payment, 'payment_intent.succeeded', 'evt_s1'))],
            ['webhook', base64_encode($this->body($payment, 'payment_intent.succeeded', 'evt_s2'))],
        ]);

        $this->assertGenuinelyRaced($results);
        $ours = BusinessPaymentEvent::query()->whereIn('provider_event_id', ['evt_s1', 'evt_s2']);
        $this->assertSame(2, (clone $ours)->count());
        $this->assertSame('succeeded', $payment->refresh()->status->value);
        $this->assertSame(1, $this->eventCount('DocumentPaymentSucceeded'), 'One transition into succeeded, one event.');
        $this->assertSame(1, $this->eventCount('DocumentFullyPaid'));
        $this->assertSame(1, (clone $ours)->where('state', 'processed')->count());
        $this->assertSame(1, (clone $ours)->where('state', 'ignored')->count());
    }

    public function test_a_failure_racing_a_success_for_one_intent_always_ends_paid(): void
    {
        foreach (range(1, 2) as $round) {
            $payment = $this->inFlightPayment();
            $this->truncateCalls();

            $results = $this->race([
                ['webhook', base64_encode($this->body($payment, 'payment_intent.payment_failed', "evt_f{$round}"))],
                ['webhook', base64_encode($this->body($payment, 'payment_intent.succeeded', "evt_ok{$round}"))],
            ]);

            $this->assertGenuinelyRaced($results);
            $this->assertSame('succeeded', $payment->refresh()->status->value, "Round {$round}: out-of-order delivery cannot leave a paid customer's attempt failed.");
            $this->assertSame('paid', BusinessDocument::query()->findOrFail($payment->business_document_id)->status->value);
            $this->assertSame(1, $this->eventCount('DocumentPaymentSucceeded'));
            $this->assertSame(1, $this->eventCount('DocumentFullyPaid'));
            $this->assertLessThanOrEqual(1, $this->eventCount('DocumentPaymentFailed'));
        }
    }

    // =================================================================
    // Send versus edit
    // =================================================================

    public function test_send_versus_edit_never_issues_content_that_differs_from_its_hash(): void
    {
        foreach (range(1, 3) as $round) {
            $tenant = $this->sendableTenant();
            $document = $this->draftDocument($tenant, ['kind' => 'invoice']);

            $results = $this->race([['send', (string) $document->id], ['edit', (string) $document->id]]);

            $this->assertGenuinelyRaced($results);
            $this->assertSame(0, $results[0]['exitCode'], 'Send always succeeds: the edit either lands before it or is refused.');
            $this->assertContains($results[1]['exitCode'], [0, 4]);

            $document->refresh();
            $this->assertSame('sent', $document->status->value);
            $issued = BusinessDocumentVersion::query()->where('business_document_id', $document->id)->where('state', 'issued')->sole();

            $this->assertSame(
                (string) $issued->content_hash,
                app(DocumentContentHasher::class)->hash($issued->fresh()),
                "Round {$round}: the frozen content must equal what was hashed — an edit may never slip in after the freeze."
            );

            $edited = ($issued->content['body'] ?? null) === 'Edited concurrently';
            $this->assertSame($results[1]['exitCode'] === 0, $edited, 'Edit succeeded exactly when it is in the issued content.');
            $this->assertSame(0, BusinessDocumentVersion::query()->where('business_document_id', $document->id)->where('state', 'draft')->count());
        }
    }

    public function test_send_versus_a_new_line_never_issues_a_total_the_schedule_does_not_cover(): void
    {
        foreach (range(1, 3) as $round) {
            $tenant = $this->sendableTenant();
            $document = $this->draftDocument($tenant, ['kind' => 'invoice']);

            $results = $this->race([['send', (string) $document->id], ['addline', (string) $document->id]]);

            $this->assertGenuinelyRaced($results);
            $this->assertContains($results[0]['exitCode'], [0, 4]);
            $this->assertContains($results[1]['exitCode'], [0, 4]);
            $this->assertNotSame([4, 4], array_column($results, 'exitCode'), 'One of the two must win.');

            $document->refresh();
            $version = BusinessDocumentVersion::query()->where('business_document_id', $document->id)->orderByDesc('id')->firstOrFail();
            $scheduleSum = (int) $version->paymentScheduleItems()->sum('amount_minor');

            if ($document->status->value === 'sent') {
                $this->assertSame('issued', $version->state->value);
                $this->assertSame((int) $version->total_minor, $scheduleSum, "Round {$round}: a sent invoice's total is exactly covered by its schedule.");
                $this->assertSame(50000, (int) $version->total_minor, 'The late line did not slip into the issued version.');
                $this->assertSame(1, $version->lineItems()->count());
            } else {
                $this->assertSame('draft', $document->status->value);
                $this->assertSame(2, $version->lineItems()->count(), 'The line landed first, so send was refused (schedule no longer covers the total).');
                $this->assertNull($document->access_token_hash);
            }
        }
    }

    public function test_resend_versus_void_never_leaves_a_live_link_on_a_void_invoice(): void
    {
        foreach (range(1, 3) as $round) {
            $tenant = $this->sendableTenant();
            [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant, ['kind' => 'invoice']));
            $this->truncateCalls();

            $results = $this->race([['resend', (string) $document->id], ['void', (string) $document->id]]);

            $this->assertGenuinelyRaced($results);
            $this->assertSame(0, $results[1]['exitCode'], 'Void always wins eventually: resend never blocks it.');
            $this->assertContains($results[0]['exitCode'], [0, 4]);

            $document->refresh();
            $this->assertSame('void', $document->status->value);
            $this->assertNull($document->access_token_hash, "Round {$round}: a void invoice has no live link, whichever order ran.");
            $this->assertSame(1, $this->eventCount('DocumentVoided'));
        }
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /** A real, started (provider intent persisted, status `created`) attempt on a fresh payable invoice. */
    private function inFlightPayment(): BusinessDocumentPayment
    {
        return $this->inFlightPaymentWithLink()[0];
    }

    /** @return array{0: BusinessDocumentPayment, 1: BusinessDocument, 2: string} the attempt, its document and the customer's link token */
    private function inFlightPaymentWithLink(): array
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business'], 'acct_' . Str::random(14));
        $draft = $this->draftDocument($tenant, ['kind' => 'invoice']);
        [$document, $token] = $this->sendAndCaptureToken($draft);

        app(PaymentManager::class)->start($this->accessFor($document, $token));

        return [BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail(), $document, $token];
    }

    /** Records a provider-confirmed `failed` on the row, exactly as the webhook path would. */
    private function declineInParent(BusinessDocumentPayment $payment): void
    {
        $connection = BusinessStripeConnection::query()->findOrFail($payment->business_stripe_connection_id);

        app(\App\Library\Payments\PaymentFinalizer::class)->apply($payment, new \App\Library\Payments\PaymentIntentSnapshot(
            providerPaymentIntentId: (string) $payment->provider_payment_intent_id,
            status: \App\Enums\Documents\BusinessDocumentPaymentStatus::Failed,
            amountMinor: (int) $payment->amount_minor,
            currencyCode: (string) $payment->currency_code,
            connectedAccountId: (string) $connection->stripe_account_id,
            operationId: (string) $payment->local_idempotency_key,
            failureCode: 'card_declined',
        ));
    }

    private function body(BusinessDocumentPayment $payment, string $type, string $eventId): string
    {
        $connection = BusinessStripeConnection::query()->findOrFail($payment->business_stripe_connection_id);

        return json_encode([
            'id' => $eventId,
            'type' => $type,
            'account' => $connection->stripe_account_id,
            'data' => ['object' => [
                'id' => $payment->provider_payment_intent_id,
                'object' => 'payment_intent',
                'amount' => (int) $payment->amount_minor,
                'currency' => strtolower((string) $payment->currency_code),
                'metadata' => ['app_operation_id' => $payment->local_idempotency_key],
            ]],
        ]);
    }

    private function storeEvent(BusinessDocumentPayment $payment, string $type, string $eventId): BusinessPaymentEvent
    {
        $connection = BusinessStripeConnection::query()->findOrFail($payment->business_stripe_connection_id);
        $payload = $this->body($payment, $type, $eventId);

        return BusinessPaymentEvent::create([
            'business_stripe_connection_id' => $connection->id,
            'stripe_account_id' => $connection->stripe_account_id,
            'provider_event_id' => $eventId,
            'event_type' => $type,
            'payload_encrypted' => $payload,
            'payload_hash' => hash('sha256', $payload),
        ]);
    }

    // =================================================================
    // Race harness
    // =================================================================

    /** @return array<string, string> */
    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database];
    }

    /**
     * @param  array<int, array<int, string>>  $operations  each: [op, ...args]
     * @return array<int, array{exitCode: int, stdout: string, stderr: string, enteredUs: int}>
     */
    private function race(array $operations): array
    {
        $runner = __DIR__ . '/Support/concurrent_payment_runner.php';
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $startAt = (int) (microtime(true) * 1_000_000) + self::START_GATE_MICROSECONDS;

        $processes = [];

        foreach ($operations as $operation) {
            $command = array_merge([$php, $runner, array_shift($operation), (string) $startAt, $this->callsFile], $operation);
            $processes[] = new Process($command, null, $this->childEnvironment(), null, 90.0);
        }

        foreach ($processes as $process) {
            $process->start();
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            $stdout = $process->getOutput();

            $results[] = [
                'exitCode' => (int) $process->getExitCode(),
                'stdout' => $stdout,
                'stderr' => $process->getErrorOutput(),
                'enteredUs' => preg_match('/ENTERED (\d+)/', $stdout, $m) === 1 ? (int) $m[1] : 0,
            ];
        }

        foreach ($results as $index => $result) {
            $this->assertNotSame(3, $result['exitCode'], "Child {$index} refused to run against an unexpected database: {$result['stderr']}");
            $this->assertNotSame(1, $result['exitCode'], "Child {$index} failed unexpectedly: {$result['stderr']}");
        }

        return $results;
    }

    /** Proves the critical sections overlapped rather than ran one after the other. */
    private function assertGenuinelyRaced(array $results): void
    {
        $entries = array_column($results, 'enteredUs');

        foreach ($entries as $index => $entry) {
            $this->assertGreaterThan(0, $entry, "Child {$index} never reported entering the engine.");
        }

        $this->assertLessThan(
            self::MAX_ENTRY_SKEW_MICROSECONDS,
            max($entries) - min($entries),
            'The children did not enter together, so this was not a race.'
        );
    }

    private function truncateCalls(): void
    {
        file_put_contents($this->callsFile, '');
    }

    /** @return array<int, string> */
    private function callLines(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($this->callsFile)))));
    }

    /** @return array<int, string> */
    private function providerCreateKeys(): array
    {
        $keys = [];

        foreach ($this->callLines() as $line) {
            if (str_starts_with($line, 'provider|create|')) {
                $keys[] = substr($line, strlen('provider|create|'));
            }
        }

        return $keys;
    }

    private function eventCount(string $event): int
    {
        return count(array_filter($this->callLines(), fn ($line) => str_starts_with($line, 'event|' . $event . '|')));
    }

    private function field(string $stdout, string $name, string $prefix = ''): string
    {
        $pattern = $prefix !== '' ? '/(' . preg_quote($prefix, '/') . '\S+)/' : '/' . preg_quote($name, '/') . '=(\S+)/';

        return preg_match($pattern, $stdout, $m) === 1 ? $m[1] : '';
    }
}
