<?php

namespace Tests\Feature\Documents;

use App\Library\Documents\DocumentContentHasher;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentSignature;
use App\Models\BusinessDocumentVersion;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Support\Documents\ShownVersion;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * TRUE concurrency for the document lifecycle: separate OS processes, separate
 * database connections, no mocked lock.
 *
 * The overlap is made deterministic, not hoped for. The parent holds the
 * document's row lock (`SELECT ... FOR UPDATE`) on a probe connection that
 * every contender must pass through first, starts all of them, and only
 * releases it after proving each is still running — i.e. every contender is
 * genuinely waiting at the same moment. They then race for real, and the
 * database must show exactly one logical outcome:
 *
 *   - two Sends of one draft  -> one issued version, one DocumentSent, one
 *     queued email; the loser replays;
 *   - two identical Signs     -> one signature row, one DocumentSigned, both
 *     callers told the same signature;
 *   - two DIFFERENT signers   -> exactly one wins, the other is refused;
 *   - a draft edit racing Send-> the issued version is internally consistent
 *     whichever won, and an edit after Send is refused.
 *
 * Deliberately does NOT use RefreshDatabase (a child process can only see
 * committed rows). The database is the lane's own TestDatabaseSafety-approved
 * sibling, rebuilt with migrate:fresh around the tests, which is only
 * permitted after TestDatabaseSafety has approved the name.
 */
class DocumentConcurrencyTest extends TestCase
{
    use SendsDocuments;

    private const PROBE_CONNECTION = 'mysql_documents_race_probe';

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        TestDatabaseSafety::activeTestDatabase();

        if (! self::$schemaReady) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$schemaReady = true;
        }
    }

    protected function tearDown(): void
    {
        // Committed fixtures must not leak: leave the disposable database as
        // RefreshDatabase expects to find it (migrated, empty of fixtures).
        TestDatabaseSafety::activeTestDatabase();
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = true;

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        // The children read the queue from .env (the parent's phpunit.xml says
        // `sync`): a durable queue lets the test COUNT the link emails queued.
        return ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database, 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array'];
    }

    private function runner(string ...$arguments): Process
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        return new Process([$php, __DIR__ . '/Support/concurrent_document_runner.php', ...$arguments], null, $this->childEnvironment());
    }

    private function probe(): Connection
    {
        config(['database.connections.' . self::PROBE_CONNECTION => config('database.connections.mysql')]);
        DB::purge(self::PROBE_CONNECTION);
        $probe = DB::connection(self::PROBE_CONNECTION);
        $this->assertNotSame(DB::connection()->getPdo(), $probe->getPdo());

        return $probe;
    }

    /**
     * Holds the document's row lock on a second connection, starts every
     * runner (optionally staggered so their arrival order is known), proves
     * they are all blocked behind it, then releases.
     *
     * @param  list<Process>  $processes
     * @return list<array<string, mixed>> each runner's decoded result line
     */
    private function raceForDocument(int $documentId, array $processes, int $staggerMicroseconds = 0): array
    {
        $probe = $this->probe();
        $probe->beginTransaction();

        try {
            $probe->select('select id from business_documents where id = ? for update', [$documentId]);

            foreach ($processes as $process) {
                $process->start();
                if ($staggerMicroseconds > 0) {
                    usleep($staggerMicroseconds);
                }
            }

            // Long enough for every runner to boot, connect and queue on the held row.
            usleep(3_000_000);

            foreach ($processes as $i => $process) {
                $this->assertTrue($process->isRunning(), 'runner ' . $i . ' finished while the row was held — it never waited: ' . $process->getErrorOutput() . $process->getOutput());
            }
        } finally {
            $probe->rollBack();
            DB::purge(self::PROBE_CONNECTION);
            config(['database.connections.' . self::PROBE_CONNECTION => null]);
        }

        $results = [];
        foreach ($processes as $i => $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'runner ' . $i . ' crashed: ' . $process->getErrorOutput() . $process->getOutput());
            $decoded = json_decode(trim($process->getOutput()), true);
            $this->assertIsArray($decoded, 'runner ' . $i . ' produced no result line: ' . $process->getOutput());
            $results[] = $decoded;
        }

        return $results;
    }

    private function sentDocument(): BusinessDocument
    {
        [$document] = $this->sendAndCaptureToken($this->draftDocument($this->sendableTenant()));

        return $document->refresh();
    }

    // -----------------------------------------------------------------
    // Send
    // -----------------------------------------------------------------

    public function test_concurrent_sends_issue_one_version_emit_one_event_and_queue_one_email(): void
    {
        $document = $this->draftDocument($this->sendableTenant());
        $this->assertSame(0, DB::table('jobs')->count());

        $results = $this->raceForDocument((int) $document->id, [
            $this->runner('send', (string) $document->id),
            $this->runner('send', (string) $document->id),
        ]);

        // Both callers are told "sent" — the loser replays instead of failing.
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        // Exactly ONE lifecycle event across both processes ...
        $this->assertSame(1, $results[0]['events']['sent'] + $results[1]['events']['sent'], json_encode($results));
        // ... one queued link email ...
        $this->assertSame(1, DB::table('jobs')->count(), 'Exactly one link email may be queued.');
        // ... and one issued version, no draft left, one live link.
        $versions = BusinessDocumentVersion::query()->where('business_document_id', $document->id)->get();
        $this->assertCount(1, $versions);
        $this->assertSame('issued', $versions->first()->state->value);
        $fresh = $document->fresh();
        $this->assertSame('sent', $fresh->status->value);
        $this->assertSame((int) $versions->first()->id, (int) $fresh->current_version_id);
        $this->assertNotNull($fresh->access_token_hash);
    }

    // -----------------------------------------------------------------
    // Sign
    // -----------------------------------------------------------------

    public function test_concurrent_identical_signs_produce_one_signature_one_event_and_one_shared_result(): void
    {
        $document = $this->sentDocument();
        $uid = ShownVersion::uid($document);

        $results = $this->raceForDocument((int) $document->id, [
            $this->runner('sign', (string) $document->id, $uid, 'Pat Rivera', 'pat@example.test'),
            $this->runner('sign', (string) $document->id, $uid, 'Pat Rivera', 'pat@example.test'),
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame($results[0]['signature_id'], $results[1]['signature_id'], 'A replay must report the SAME signature.');
        $this->assertSame(1, $results[0]['events']['signed'] + $results[1]['events']['signed'], json_encode($results));
        $this->assertSame(1, BusinessDocumentSignature::query()->where('business_document_id', $document->id)->count());
        $this->assertSame('signed', $document->fresh()->status->value);
    }

    public function test_concurrent_signs_by_two_different_signers_have_exactly_one_winner(): void
    {
        $document = $this->sentDocument();
        $uid = ShownVersion::uid($document);

        $results = $this->raceForDocument((int) $document->id, [
            $this->runner('sign', (string) $document->id, $uid, 'First Signer', 'first@example.test'),
            $this->runner('sign', (string) $document->id, $uid, 'Second Signer', 'second@example.test'),
        ]);

        $winners = array_values(array_filter($results, fn ($r) => $r['ok']));
        $losers = array_values(array_filter($results, fn ($r) => ! $r['ok']));
        $this->assertCount(1, $winners, json_encode($results));
        $this->assertCount(1, $losers, json_encode($results));
        $this->assertStringContainsString('already been signed', (string) $losers[0]['error']);
        $this->assertSame(1, $results[0]['events']['signed'] + $results[1]['events']['signed']);

        $signature = BusinessDocumentSignature::query()->where('business_document_id', $document->id)->sole();
        $this->assertSame((int) $winners[0]['signature_id'], (int) $signature->id);
        $this->assertContains($signature->typed_name, ['First Signer', 'Second Signer']);
    }

    // -----------------------------------------------------------------
    // A stale draft edit racing Send
    // -----------------------------------------------------------------

    public function test_a_stale_draft_edit_racing_send_never_leaves_an_inconsistent_issued_version(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        app(\App\Library\Documents\DocumentManager::class)->edit($document, ['content' => ['body' => 'ORIGINAL']]);

        // The edit arrives first, the send a moment later; whichever the
        // database serialises first, the outcome must be self-consistent.
        $results = $this->raceForDocument((int) $document->id, [
            $this->runner('edit', (string) $document->id, 'EDITED'),
            $this->runner('send', (string) $document->id),
        ], 1_000_000);

        [$edit, $send] = $results;
        $this->assertTrue($send['ok'], json_encode($results));
        $this->assertSame(1, $send['events']['sent']);

        $version = BusinessDocumentVersion::query()->where('business_document_id', $document->id)->sole();
        $this->assertSame('issued', $version->state->value);
        $this->assertSame(0, BusinessDocumentVersion::query()->where('business_document_id', $document->id)->where('state', 'draft')->count());

        // The frozen hash is exactly what the stored rows hash to — the edit is
        // either wholly in the issued version, or wholly refused, never half.
        $this->assertSame($version->content_hash, app(DocumentContentHasher::class)->hash($version->fresh()));
        $expectedBody = $edit['ok'] ? 'EDITED' : 'ORIGINAL';
        $this->assertSame($expectedBody, $version->fresh()->content['body'], json_encode($results));
        if (! $edit['ok']) {
            $this->assertStringContainsString('open draft', (string) $edit['error']);
        }

        // And once sent, a late edit is ALWAYS refused and changes nothing.
        $hashBefore = $version->content_hash;
        $late = $this->runner('edit', (string) $document->id, 'TOO LATE');
        $late->run();
        $decoded = json_decode(trim($late->getOutput()), true);
        $this->assertFalse($decoded['ok'], $late->getOutput());
        $this->assertSame($expectedBody, $version->fresh()->content['body']);
        $this->assertSame($hashBefore, $version->fresh()->content_hash);
    }
}
