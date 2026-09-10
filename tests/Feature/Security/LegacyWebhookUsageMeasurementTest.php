<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\RecordLegacyWebhookUsage;
use App\Library\Messaging\LegacyWebhookRouteRegistry;
use App\Models\LegacyWebhookRouteUsage;
use App\Models\Reports;
use App\Models\SendingServer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Legacy Provider Webhook Measurement Contract §10 —
 * tests/Feature/Security/LegacyWebhookUsageMeasurementTest.php.
 *
 * Every numbered assertion below corresponds exactly to the contract's own
 * numbered assertion in §10's first table. None is weakened or skipped.
 */
class LegacyWebhookUsageMeasurementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    private function columns(): array
    {
        return Schema::getColumnListing('legacy_webhook_route_usage');
    }

    // =================================================================
    // Assertion 1 — each legacy route increments its own aggregate.
    // =================================================================

    public function test_a_legacy_route_increments_the_aggregate_for_its_own_route_and_provider(): void
    {
        $this->post(route('dlr.twilio'), ['MessageSid' => 'AGG_1', 'MessageStatus' => 'delivered']);

        $row = DB::table('legacy_webhook_route_usage')
            ->where('route_name', 'dlr.twilio')
            ->where('provider_slug', 'twilio')
            ->first();

        $this->assertNotNull($row, 'A row must exist for dlr.twilio/twilio after one hit.');
        $this->assertSame(1, (int) $row->hit_count);
    }

    // =================================================================
    // Assertion 2 — a repeated hit increments hit_count, moves
    // last_seen_at, and never changes first_seen_at.
    // =================================================================

    public function test_a_repeated_hit_increments_count_and_moves_last_seen_but_never_first_seen(): void
    {
        $this->post(route('dlr.twilio'), ['MessageSid' => 'AGG_2A', 'MessageStatus' => 'delivered']);

        $first = DB::table('legacy_webhook_route_usage')
            ->where('route_name', 'dlr.twilio')->where('provider_slug', 'twilio')->first();

        $this->travel(5)->minutes();

        $this->post(route('dlr.twilio'), ['MessageSid' => 'AGG_2B', 'MessageStatus' => 'delivered']);

        $second = DB::table('legacy_webhook_route_usage')
            ->where('route_name', 'dlr.twilio')->where('provider_slug', 'twilio')->first();

        $this->assertSame(2, (int) $second->hit_count);
        $this->assertSame($first->first_seen_at, $second->first_seen_at, 'first_seen_at must never change.');
        $this->assertNotSame($first->last_seen_at, $second->last_seen_at, 'last_seen_at must move.');
    }

    // =================================================================
    // Assertion 3 — two different routes/providers never cross-
    // contaminate a counter.
    // =================================================================

    public function test_two_different_routes_and_providers_never_cross_contaminate(): void
    {
        $this->post(route('dlr.twilio'), ['MessageSid' => 'X_1', 'MessageStatus' => 'delivered']);
        $this->post(route('dlr.vonage'), ['messageId' => 'X_2', 'status' => 'delivered', 'msisdn' => '14155550002', 'to' => 'SENDER']);

        $twilioRow = DB::table('legacy_webhook_route_usage')->where('route_name', 'dlr.twilio')->first();
        $vonageRow = DB::table('legacy_webhook_route_usage')->where('route_name', 'dlr.vonage')->first();

        $this->assertSame(1, (int) $twilioRow->hit_count);
        $this->assertSame(1, (int) $vonageRow->hit_count);
        $this->assertSame('twilio', $twilioRow->provider_slug);
        $this->assertSame('vonage', $vonageRow->provider_slug);
        $this->assertSame(2, DB::table('legacy_webhook_route_usage')->count());
    }

    // =================================================================
    // Assertion 4 — no payload, message body, or phone number is stored.
    // =================================================================

    public function test_no_payload_message_body_or_phone_number_is_stored(): void
    {
        $secretPhone = '+15551234567';
        $secretMessage = 'This is a private SMS body that must never be persisted.';

        $this->post(route('dlr.vonage'), [
            'messageId' => 'PRIVACY_1',
            'status' => 'delivered',
            'msisdn' => $secretPhone,
            'to' => $secretPhone,
            'text' => $secretMessage,
        ]);

        $expectedColumns = ['id', 'route_name', 'provider_slug', 'hit_count', 'first_seen_at', 'last_seen_at', 'created_at', 'updated_at'];
        $this->assertEqualsCanonicalizing($expectedColumns, $this->columns(), 'The table must have exactly the contracted columns — nothing more.');

        $row = DB::table('legacy_webhook_route_usage')->where('route_name', 'dlr.vonage')->first();
        $stored = (array) $row;

        foreach ($stored as $column => $value) {
            $this->assertStringNotContainsString($secretPhone, (string) $value, "Column [{$column}] must never contain the phone number.");
            $this->assertStringNotContainsString($secretMessage, (string) $value, "Column [{$column}] must never contain message content.");
        }

        foreach (['route_name', 'provider_slug', 'hit_count', 'first_seen_at', 'last_seen_at'] as $expectedField) {
            $this->assertArrayHasKey($expectedField, $stored);
        }
    }

    // =================================================================
    // Assertion 5 — no header, signature, token or credential is stored.
    // =================================================================

    public function test_no_request_header_signature_token_or_credential_is_stored(): void
    {
        $fixtureCredential = 'sk_live_FIXTURE_SECRET_TOKEN_abc123';

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $fixtureCredential,
            'X-Twilio-Signature' => $fixtureCredential,
        ])->post(route('dlr.twilio'), [
            'MessageSid' => 'CRED_1',
            'MessageStatus' => 'delivered',
            'ApiKey' => $fixtureCredential,
        ]);

        $this->assertEqualsCanonicalizing(
            ['id', 'route_name', 'provider_slug', 'hit_count', 'first_seen_at', 'last_seen_at', 'created_at', 'updated_at'],
            $this->columns(),
        );

        $row = (array) DB::table('legacy_webhook_route_usage')->where('route_name', 'dlr.twilio')->first();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($fixtureCredential, (string) $value, "Column [{$column}] must never contain the fixture credential.");
        }
    }

    // =================================================================
    // Assertion 6 — response semantics are invariant under measurement
    // failure.
    // =================================================================

    public function test_response_semantics_are_invariant_under_measurement_failure(): void
    {
        $payload = ['MessageSid' => 'FAIL_SAFE_1', 'MessageStatus' => 'delivered'];

        $baseline = $this->post(route('dlr.twilio'), $payload);

        Schema::dropIfExists('legacy_webhook_route_usage');
        Exceptions::fake();

        $duringFailure = $this->post(route('dlr.twilio'), $payload);

        $this->assertSame($baseline->getStatusCode(), $duringFailure->getStatusCode(), 'Status must be byte-identical whether or not measurement succeeds.');
        $this->assertSame($baseline->getContent(), $duringFailure->getContent(), 'Body must be byte-identical whether or not measurement succeeds.');
        $this->assertNull($duringFailure->exception, 'The telemetry failure must never surface as the response exception.');

        Exceptions::assertReported(QueryException::class);

        // Recreate the table so tearDown()'s RefreshDatabase migration
        // bookkeeping is undisturbed for the next test.
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_12_100007_create_legacy_webhook_route_usage_table.php', '--realpath' => false]);
    }

    // =================================================================
    // Assertion 6a — the recorder is bounded as §3.6 requires.
    // =================================================================

    public function test_the_recorder_performs_exactly_one_bounded_update_or_insert(): void
    {
        $usageStatements = [];
        DB::listen(function ($query) use (&$usageStatements): void {
            if (str_contains($query->sql, 'legacy_webhook_route_usage')) {
                $usageStatements[] = $query->sql;
            }
        });

        // First-ever hit for this route: UPDATE affects 0 rows, then INSERT.
        // At most two statements, exactly as §3.5/§3.6 bound it.
        $this->post(route('dlr.twilio'), ['MessageSid' => 'BOUND_1', 'MessageStatus' => 'delivered']);
        $this->assertLessThanOrEqual(2, count($usageStatements), 'A first-time hit must be at most two statements.');
        $this->assertGreaterThanOrEqual(1, count($usageStatements));

        foreach ($usageStatements as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('SAVEPOINT', $sql, 'The recorder must never open its own nested transaction.');
            $this->assertStringNotContainsStringIgnoringCase('LOCK IN SHARE MODE', $sql);
            $this->assertStringNotContainsStringIgnoringCase('FOR UPDATE', $sql, 'The recorder must never introduce a lock wait.');
        }

        // A repeated hit for the SAME route is exactly one UPDATE.
        $usageStatements = [];
        $this->post(route('dlr.twilio'), ['MessageSid' => 'BOUND_2', 'MessageStatus' => 'delivered']);
        $this->assertCount(1, $usageStatements, 'A repeat hit must be exactly one UPDATE statement.');
        $this->assertMatchesRegularExpression('/^update/i', trim($usageStatements[0]));

        Http::assertNothingSent();

        $middlewareSource = file_get_contents(app_path('Http/Middleware/RecordLegacyWebhookUsage.php'));
        foreach (['->all(', '->getContent(', '->input(', '->header('] as $forbiddenCall) {
            $this->assertStringNotContainsString($forbiddenCall, $middlewareSource, "The recorder must never call {$forbiddenCall} — it must not parse the payload.");
        }
    }

    // =================================================================
    // Assertion 7 — a non-webhook public route records nothing.
    // =================================================================

    public function test_a_non_webhook_public_route_records_nothing(): void
    {
        $this->get(route('privacy-policy'));
        $this->get(route('terms-of-use'));

        $this->assertSame(0, DB::table('legacy_webhook_route_usage')->count(), 'Unrelated public routes must never be measured.');
    }

    // =================================================================
    // Assertion 8 — the managed Telnyx route is treated exactly as §3.7
    // contracts: excluded from measurement by default.
    // =================================================================

    public function test_the_managed_telnyx_route_is_excluded_from_measurement_by_default(): void
    {
        $this->assertFalse(
            LegacyWebhookRouteRegistry::isMeasured('inbound.telnyx_managed'),
            'inbound.telnyx_managed must be excluded from the registry by §3.7 default.',
        );

        $this->post(route('inbound.telnyx_managed'), ['data' => ['event_type' => 'message.received']]);

        $this->assertSame(
            0,
            DB::table('legacy_webhook_route_usage')->where('route_name', 'inbound.telnyx_managed')->count(),
            'The managed Telnyx route must not be measured while excluded by default.',
        );

        // The legacy BYO Telnyx route IS measured — it is part of the
        // legacy family and its hit count is directly useful to the
        // retention decision.
        $this->post(route('inbound.telnyx'), ['data' => ['event_type' => 'message.received']]);
        $this->assertSame(1, DB::table('legacy_webhook_route_usage')->where('route_name', 'inbound.telnyx')->value('hit_count'));
    }

    // =================================================================
    // Assertion 9 — concurrent increments are not lost.
    // =================================================================

    public function test_concurrent_increments_are_not_lost(): void
    {
        $processCount = 5;
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $vendorAutoload = base_path('vendor/autoload.php');
        $bootstrapApp = base_path('bootstrap/app.php');
        $database = TestDatabaseSafety::activeTestDatabase();

        $runnerPath = sys_get_temp_dir() . '/legacy_webhook_race_runner_' . uniqid() . '.php';
        $signalPath = sys_get_temp_dir() . '/legacy_webhook_race_signal_' . uniqid() . '.flag';

        file_put_contents($runnerPath, <<<PHP
<?php
require '{$vendorAutoload}';
putenv('APP_ENV=testing');
\$_ENV['APP_ENV'] = 'testing';
\$_SERVER['APP_ENV'] = 'testing';
\$app = require '{$bootstrapApp}';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

\Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase(getenv('EXPECTED_TEST_DATABASE'));

\$signalPath = \$argv[1];
\$deadline = microtime(true) + 10.0;
fwrite(STDOUT, "WAITING\\n");
fflush(STDOUT);
while (! file_exists(\$signalPath)) {
    if (microtime(true) >= \$deadline) {
        fwrite(STDOUT, "TIMEOUT\\n");
        exit(1);
    }
    usleep(2000);
}

\$httpKernel = \$app->make(Illuminate\Contracts\Http\Kernel::class);
\$request = Illuminate\Http\Request::create('/dlr/smsto', 'POST', ['messageId' => 'RACE_' . uniqid(), 'status' => 'delivered']);
\$response = \$httpKernel->handle(\$request);
\$httpKernel->terminate(\$request, \$response);
fwrite(STDOUT, "DONE\\n");
PHP);

        $processes = [];
        $buffers = [];

        for ($i = 0; $i < $processCount; $i++) {
            $process = new Process([$phpBinary, $runnerPath, $signalPath], null, [
                'DB_DATABASE' => $database,
                'EXPECTED_TEST_DATABASE' => $database,
            ]);
            $process->setTimeout(20.0);
            $process->start();
            $processes[] = $process;
            $buffers[] = '';
        }

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $allWaiting = true;
            foreach ($processes as $index => $process) {
                $buffers[$index] .= $process->getIncrementalOutput();
                if (! str_contains($buffers[$index], 'WAITING')) {
                    $allWaiting = false;
                }
            }
            if ($allWaiting) {
                break;
            }
            usleep(2000);
        }

        foreach ($buffers as $index => $buffer) {
            $this->assertStringContainsString('WAITING', $buffer, "Process {$index} never announced readiness.");
        }

        file_put_contents($signalPath, '1');

        foreach ($processes as $index => $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), "Process {$index} did not complete: " . $process->getErrorOutput());
            $this->assertStringContainsString('DONE', $process->getOutput());
        }

        @unlink($runnerPath);
        @unlink($signalPath);

        // These 5 processes each open their own real DB connection and
        // commit for real — they are not part of this test's RefreshDatabase
        // transaction and will not be rolled back by it. Assert first, then
        // delete the row explicitly so later tests in this run never inherit
        // it, regardless of RefreshDatabase.
        $hitCount = DB::table('legacy_webhook_route_usage')->where('route_name', 'dlr.smsto')->value('hit_count');
        $this->assertSame($processCount, (int) $hitCount, 'N genuinely concurrent hits on one route must yield exactly hit_count = N.');

        DB::table('legacy_webhook_route_usage')->where('route_name', 'dlr.smsto')->delete();
    }

    // =================================================================
    // Assertion 10 — the recorder reads no request body.
    // =================================================================

    public function test_the_recorder_reads_no_request_body(): void
    {
        $middlewareSource = file_get_contents(app_path('Http/Middleware/RecordLegacyWebhookUsage.php'));

        foreach (['$request->all(', '$request->getContent(', '$request->input(', '$request->header(', '$request->json('] as $forbiddenCall) {
            $this->assertStringNotContainsString($forbiddenCall, $middlewareSource, "The recorder must never call {$forbiddenCall}.");
        }

        // A real request still measures correctly without the recorder
        // ever touching the body — proven by the row existing afterward.
        $this->post(route('dlr.twilio'), ['MessageSid' => 'NOBODY_1', 'MessageStatus' => 'delivered']);
        $this->assertSame(1, DB::table('legacy_webhook_route_usage')->where('route_name', 'dlr.twilio')->value('hit_count'));
    }

    // =================================================================
    // Assertion 11 — no provider network call occurs.
    // =================================================================

    public function test_no_provider_network_call_occurs(): void
    {
        $this->post(route('dlr.twilio'), ['MessageSid' => 'NONET_1', 'MessageStatus' => 'delivered']);
        $this->post(route('inbound.telnyx'), ['data' => ['event_type' => 'message.received']]);

        Http::assertNothingSent();
    }

    // =================================================================
    // Assertion 12 — active SendingServer rows are not mutated by
    // measurement or by the report.
    // =================================================================

    public function test_active_sending_server_rows_are_not_mutated_by_measurement_or_the_report(): void
    {
        $owner = $this->createCustomer();
        $server = SendingServer::create([
            'name' => 'Fixture Twilio Server',
            'user_id' => $owner->user_id,
            'type' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'settings' => json_encode(['account_sid' => 'AC_FIXTURE', 'auth_token' => 'FIXTURE_SECRET']),
        ]);

        $before = $server->fresh()->getAttributes();

        $this->post(route('dlr.twilio'), ['MessageSid' => 'NOMUTATE_1', 'MessageStatus' => 'delivered']);
        Artisan::call('messaging:legacy-provider-usage-report');

        $after = $server->fresh()->getAttributes();

        $this->assertSame($before, $after, 'Measurement and the operator report must never mutate a SendingServer row.');
    }

    // =================================================================
    // Assertion 13 — the operator report prints only safe fields.
    // =================================================================

    public function test_the_operator_report_prints_only_safe_fields(): void
    {
        $owner = $this->createCustomer();
        $fixtureSecret = 'FIXTURE_SECRET_TOKEN_zzz999';
        $fixturePhone = '+15559998888';

        SendingServer::create([
            'name' => 'Fixture Server For Report',
            'user_id' => $owner->user_id,
            'type' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'settings' => json_encode(['account_sid' => $fixtureSecret, 'auth_token' => $fixtureSecret, 'phone' => $fixturePhone]),
        ]);

        $this->post(route('dlr.twilio'), ['MessageSid' => 'REPORT_1', 'MessageStatus' => 'delivered']);

        Artisan::call('messaging:legacy-provider-usage-report');
        $output = Artisan::output();

        $this->assertStringNotContainsString($fixtureSecret, $output, 'The report must never print a credential-shaped fixture secret.');
        $this->assertDoesNotMatchRegularExpression('/\+?\d{10,15}/', $output, 'The report must never print a phone-number-shaped substring.');
        $this->assertStringNotContainsStringIgnoringCase('auth_token', $output);
        $this->assertStringNotContainsStringIgnoringCase('account_sid', $output);
    }

    // =================================================================
    // Assertion 14 — no customer-visible behaviour changes.
    // =================================================================

    public function test_no_customer_visible_behaviour_changes_with_the_middleware_present(): void
    {
        $owner = $this->createCustomer();

        $withMiddlewareReport = Reports::create([
            'user_id' => $owner->user_id, 'to' => '14155559991', 'message' => 'legacy',
            'sms_type' => 'plain', 'status' => 'Enroute|WITH_MW', 'customer_status' => 'Enroute',
            'direction' => Reports::DIRECTION_OUTGOING, 'cost' => 3, 'sms_count' => 1,
        ]);
        $withoutMiddlewareReport = Reports::create([
            'user_id' => $owner->user_id, 'to' => '14155559992', 'message' => 'legacy',
            'sms_type' => 'plain', 'status' => 'Enroute|WITHOUT_MW', 'customer_status' => 'Enroute',
            'direction' => Reports::DIRECTION_OUTGOING, 'cost' => 3, 'sms_count' => 1,
        ]);

        $withMiddleware = $this->post(route('dlr.twilio'), ['MessageSid' => 'WITH_MW', 'MessageStatus' => 'delivered']);

        $withoutMiddleware = $this->withoutMiddleware(RecordLegacyWebhookUsage::class)
            ->post(route('dlr.twilio'), ['MessageSid' => 'WITHOUT_MW', 'MessageStatus' => 'delivered']);

        $this->assertSame($withMiddleware->getStatusCode(), $withoutMiddleware->getStatusCode());
        $this->assertSame($withMiddleware->getContent(), $withoutMiddleware->getContent());

        $this->assertSame($withMiddlewareReport->fresh()->customer_status, $withoutMiddlewareReport->fresh()->customer_status);
        $this->assertStringContainsString('WITH_MW', (string) $withMiddlewareReport->fresh()->status);
        $this->assertStringContainsString('WITHOUT_MW', (string) $withoutMiddlewareReport->fresh()->status);
    }
}
