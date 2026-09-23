<?php

namespace Tests\Feature\Documents;

use App\Jobs\Documents\SendDocumentLinkEmail;
use App\Library\Documents\DocumentManager;
use App\Notifications\Documents\DocumentIssuedNotification;
use App\Providers\JobServiceProvider;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §11.3 / §7.1 / §6.3 — the secure link is
 * delivered by a Base-extending, ShouldQueueAfterCommit, ShouldBeEncrypted
 * job, and the one plaintext token it carries never reaches `jobs`,
 * `failed_jobs`, a log line or an exception message.
 */
class DocumentLinkDeliveryJobTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    // -----------------------------------------------------------------
    // The job's contract
    // -----------------------------------------------------------------

    public function test_the_delivery_job_is_a_base_job_queued_after_commit_and_encrypted(): void
    {
        $job = new SendDocumentLinkEmail(1, 'irrelevant');

        $this->assertInstanceOf(\App\Jobs\Base::class, $job, '§11.3 requires a Base-extending job.');
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job, 'A link must never be emailed for a rolled-back row.');
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job, '§6.3: the plaintext token must never be persisted readable.');
    }

    public function test_send_dispatches_exactly_one_delivery_job_for_the_document(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);

        Bus::fake();
        app(DocumentManager::class)->send($document);

        Bus::assertDispatchedTimes(SendDocumentLinkEmail::class, 1);
        Bus::assertDispatched(SendDocumentLinkEmail::class, function (SendDocumentLinkEmail $job) use ($document) {
            return (new \ReflectionProperty($job, 'documentId'))->getValue($job) === (int) $document->id;
        });
    }

    // -----------------------------------------------------------------
    // After-commit: a rollback delivers nothing
    // -----------------------------------------------------------------

    public function test_a_send_that_rolls_back_dispatches_no_job_and_sends_no_email(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        // Break the schedule so send() refuses inside its own transaction.
        DB::table('business_document_payment_schedule_items')
            ->where('business_document_version_id', $document->versions()->first()->id)
            ->update(['amount_minor' => 1]);

        Bus::fake();
        Notification::fake();

        try {
            app(DocumentManager::class)->send($document);
            $this->fail('Expected the send to be refused.');
        } catch (ValidationException) {
            // expected
        }

        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        $this->assertNull($document->refresh()->access_token_hash, 'No link may exist for a rolled-back send.');
    }

    public function test_an_outer_rollback_after_a_successful_send_still_delivers_nothing(): void
    {
        // The strongest form of the §7.1 rule: even when send() itself
        // succeeds, a FAILURE IN THE ENCLOSING transaction must leave the
        // recipient with no email. ShouldQueueAfterCommit is what makes that
        // structural rather than a property of one call site.
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);

        Bus::fake();

        try {
            DB::transaction(function () use ($document) {
                app(DocumentManager::class)->send($document);

                throw new \RuntimeException('Something later in the unit of work failed.');
            });
            $this->fail('Expected the enclosing transaction to fail.');
        } catch (\RuntimeException) {
            // expected
        }

        Bus::assertNothingDispatched();
        $this->assertNull($document->refresh()->access_token_hash);
    }

    // -----------------------------------------------------------------
    // The persisted payload is encrypted and token-free
    // -----------------------------------------------------------------

    public function test_the_queued_payload_is_encrypted_and_holds_no_plaintext_token(): void
    {
        $token = 'TOKENabcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUV';

        Queue::connection('database')->push(new SendDocumentLinkEmail(123, $token));

        $row = DB::table('jobs')->latest('id')->first();
        $this->assertNotNull($row, 'The job must really have been persisted to the database queue.');

        $raw = (string) $row->payload;
        $command = json_decode($raw, true)['data']['command'];

        // 1. Nothing anywhere in the stored row reveals the token.
        foreach ((array) $row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "[jobs.{$column}] must never hold the plaintext token.");
        }

        // 2. The command really is ciphertext, not a plain serialized object.
        $this->assertStringStartsNotWith('O:', $command, 'ShouldBeEncrypted must have encrypted the command.');
        $this->assertStringNotContainsString('SendDocumentLinkEmail', $command);

        // 3. ...and it is genuinely our job once decrypted, so the test is
        //    asserting encryption rather than merely a mangled payload.
        $decrypted = app(Encrypter::class)->decrypt($command);
        $this->assertStringContainsString('SendDocumentLinkEmail', $decrypted);
        $this->assertInstanceOf(SendDocumentLinkEmail::class, unserialize($decrypted));
    }

    public function test_a_failed_delivery_leaves_no_plaintext_token_in_failed_jobs(): void
    {
        $token = 'FAILEDtokenabcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNO';

        // failed_jobs stores the SAME payload the queue held, so proving the
        // payload is encrypted proves the failed row is too. Asserted against
        // a real row rather than by inference.
        Queue::connection('database')->push(new SendDocumentLinkEmail(999, $token));
        $row = DB::table('jobs')->latest('id')->first();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $row->payload,
            'exception' => 'Delivery failed.',
            'failed_at' => now(),
        ]);

        $failed = DB::table('failed_jobs')->latest('id')->first();

        foreach ((array) $failed as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "[failed_jobs.{$column}] must never hold the plaintext token.");
        }
    }

    // -----------------------------------------------------------------
    // The legacy job monitor can still read the payload
    // -----------------------------------------------------------------

    public function test_the_job_monitor_reads_an_encrypted_payload_without_failing(): void
    {
        // JobServiceProvider::getJobObject() raw-unserialize()d every payload
        // before this sub-slice, which fatals on ciphertext. This asserts the
        // monitor's own extraction directly.
        // Built exactly as the queue builds it, then handed to the monitor in
        // an event shaped like a real one.
        Queue::connection('database')->push(new SendDocumentLinkEmail(42, 'monitor-token-value'));
        $raw = json_decode((string) DB::table('jobs')->latest('id')->first()->payload, true);

        $event = new class($raw) {
            public object $job;

            public function __construct(array $payload)
            {
                $this->job = new class($payload) {
                    public function __construct(private readonly array $payload) {}

                    public function payload(): array
                    {
                        return $this->payload;
                    }
                };
            }
        };

        $method = new ReflectionMethod(JobServiceProvider::class, 'getJobObject');
        $method->setAccessible(true);
        $resolved = $method->invoke(app()->getProvider(JobServiceProvider::class), $event);

        $this->assertInstanceOf(SendDocumentLinkEmail::class, $resolved);
    }

    public function test_the_monitor_still_reads_an_ordinary_unencrypted_payload(): void
    {
        // The compatibility half: every pre-existing job must behave exactly
        // as before.
        Queue::connection('database')->push(new \Tests\Support\Documents\PlainMonitoredJob(7));
        $raw = json_decode((string) DB::table('jobs')->latest('id')->first()->payload, true);

        $this->assertStringStartsWith('O:', $raw['data']['command'], 'An ordinary job stays unencrypted.');

        $event = new class($raw) {
            public object $job;

            public function __construct(array $payload)
            {
                $this->job = new class($payload) {
                    public function __construct(private readonly array $payload) {}

                    public function payload(): array
                    {
                        return $this->payload;
                    }
                };
            }
        };

        $method = new ReflectionMethod(JobServiceProvider::class, 'getJobObject');
        $method->setAccessible(true);

        $this->assertIsObject($method->invoke(app()->getProvider(JobServiceProvider::class), $event));
    }

    public function test_an_unreadable_payload_yields_null_instead_of_taking_the_worker_down(): void
    {
        $event = new class() {
            public object $job;

            public function __construct()
            {
                $this->job = new class() {
                    public function payload(): array
                    {
                        return ['data' => ['command' => 'not-serialized-and-not-decryptable']];
                    }
                };
            }
        };

        $method = new ReflectionMethod(JobServiceProvider::class, 'getJobObject');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(app()->getProvider(JobServiceProvider::class), $event));
    }

    public function test_the_job_processes_end_to_end_through_the_real_monitor_events(): void
    {
        // QUEUE_CONNECTION is `sync` under test, so dispatching really runs
        // the job through the queue's JobProcessing/JobProcessed events —
        // the exact path JobServiceProvider listens on. If the monitor could
        // not read the encrypted payload, this would fatal rather than fail.
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant, ['recipient' => 'delivered@example.test']);

        $seen = [];
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$seen) {
            $seen[] = 'processing:' . $event->job->resolveName();
        });
        Event::listen(JobProcessed::class, function (JobProcessed $event) use (&$seen) {
            $seen[] = 'processed:' . $event->job->resolveName();
        });

        Notification::fake();
        app(DocumentManager::class)->send($document);

        $this->assertContains('processing:' . SendDocumentLinkEmail::class, $seen);
        $this->assertContains('processed:' . SendDocumentLinkEmail::class, $seen);

        Notification::assertSentOnDemand(
            DocumentIssuedNotification::class,
            fn ($notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'delivered@example.test'
        );
    }

    // -----------------------------------------------------------------
    // The token never reaches a log line or an exception
    // -----------------------------------------------------------------

    public function test_running_the_job_logs_nothing_containing_the_token(): void
    {
        $token = 'LOGGEDtokenabcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNO';
        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message . ' ' . json_encode($message->context);
        });

        Notification::fake();

        // A missing document and a missing recipient are the job's two logged
        // branches; neither may carry the token or the address.
        (new SendDocumentLinkEmail(987654, $token))->handle();

        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        DB::table('business_documents')->where('id', $document->id)->update(['recipient_email_snapshot' => null]);
        (new SendDocumentLinkEmail((int) $document->id, $token))->handle();

        $this->assertNotEmpty($lines, 'Both branches should have logged something.');

        foreach ($lines as $line) {
            $this->assertStringNotContainsString($token, $line, 'The plaintext token must never be logged.');
            $this->assertStringNotContainsString('client@example.test', $line, 'The recipient address must never be logged.');
        }
    }

    public function test_no_document_code_interpolates_the_token_into_an_exception_message(): void
    {
        $sources = [
            '/app/Jobs/Documents/SendDocumentLinkEmail.php',
            '/app/Library/Documents/DocumentManager.php',
            '/app/Library/Documents/PublicDocumentGuard.php',
            '/app/Exceptions/Documents/DocumentLinkException.php',
            '/app/Http/Controllers/Public/PublicDocumentController.php',
        ];

        foreach ($sources as $file) {
            // Comments stripped first: a docblock that SAYS "no exception
            // carries the token" must not itself trip the scan.
            $code = '';

            foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 3) . $file)) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            foreach (preg_split('/\R/', $code) as $line) {
                if (preg_match('/\bthrow\b|Exception\s*\(|withMessages\s*\(/', $line) !== 1) {
                    continue;
                }

                $this->assertSame(0, preg_match('/\$(plaintextToken|plaintext|token)\b/i', $line),
                    basename($file) . ' must never interpolate the token into a refusal or exception: ' . trim($line));
            }
        }
    }
}
