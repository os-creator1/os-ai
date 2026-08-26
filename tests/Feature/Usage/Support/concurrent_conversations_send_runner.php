<?php

/**
 * RFC-005 Milestone 5 §13 item 9/10 — standalone runner invoked as a
 * separate OS process, mirroring
 * tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php's own
 * bootstrap/database-guard/exit-code shape. Proves the same-idempotency-
 * key race behavior UsageWalletManager::reserve() gained for M5 (§3.8):
 * two genuinely independent processes racing the identical business-
 * namespaced idempotency key must resolve to exactly one created
 * reservation, with exactly one of the two getting
 * createdByThisInvocation = true — and, per §6 step 4's own locked
 * control flow (only createdByThisInvocation === true may ever proceed
 * past the atomic claim check toward the provider call), exactly that
 * one process records a "provider call" marker into a shared,
 * cross-process log file. reserve() has already fully returned (no open
 * transaction) before this marker is written, mirroring exactly where
 * quickSend() itself would set m5_conversations_usage_tracking and call
 * sendPlainSMS() — this is the money-critical "would the provider be
 * invoked" decision, proven without a live Twilio call.
 *
 * Usage: php concurrent_conversations_send_runner.php reserve <businessId> <meterKey> <idempotencyKey> <quantity> [<barrierFile> <expectedArrivals> <timeoutSeconds> <providerCallLogFile>]
 *
 * A second mode, `quicksend`, invokes the actual production
 * EloquentCampaignRepository::quickSend() method — not a hand-rolled
 * restatement of its post-reserve decision — against a fixture already
 * fully seeded (Business/wallet/meter/rate/entitlement/country/
 * SendingServer/Plan/Subscription/CustomerBasedPricingPlan) by the parent
 * process before either child starts. Only the external provider boundary
 * (Campaigns::sendPlainSMS()) is stood in for, via a minimal anonymous
 * subclass whose override writes the shared provider-call marker exactly
 * when the real, unmodified quickSend() control flow actually calls it —
 * never a duplicated if/then of reserve()'s own decision.
 *
 * Usage: php concurrent_conversations_send_runner.php quicksend <businessId> <countryId> <sendingServerId> <userId> <idempotencyToken> <barrierFile> <expectedArrivals> <timeoutSeconds> <providerCallLogFile>
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const EXPECTED_DATABASE = 'ultimatesms_testing';
const WRONG_DATABASE_EXIT_CODE = 3;

$resolvedDatabase = Illuminate\Support\Facades\DB::connection()->getDatabaseName();

if ($resolvedDatabase !== EXPECTED_DATABASE) {
    fwrite(STDERR, sprintf(
        "Refusing to run: resolved database is [%s], expected [%s]. Aborting before any database write.\n",
        $resolvedDatabase,
        EXPECTED_DATABASE
    ));
    exit(WRONG_DATABASE_EXIT_CODE);
}

function waitForBarrier(?string $barrierFile, ?int $expectedArrivals, ?float $timeoutSeconds): void
{
    if ($barrierFile === null) {
        return;
    }

    $handle = fopen($barrierFile, 'c+');
    flock($handle, LOCK_EX);
    fseek($handle, 0, SEEK_END);
    fwrite($handle, "1\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $handle = fopen($barrierFile, 'r');
        flock($handle, LOCK_SH);
        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if (count(array_filter(explode("\n", trim($contents)))) >= $expectedArrivals) {
            return;
        }

        usleep(20_000);
    }
}

$mode = $argv[1] ?? null;

try {
    if ($mode === 'reserve') {
        [, , $businessId, $meterKey, $idempotencyKey, $quantity] = $argv;

        $barrierFile = $argv[6] ?? null;
        $expectedArrivals = isset($argv[7]) ? (int) $argv[7] : null;
        $timeoutSeconds = isset($argv[8]) ? (float) $argv[8] : null;
        $providerCallLogFile = $argv[9] ?? null;

        waitForBarrier($barrierFile, $expectedArrivals, $timeoutSeconds);

        $business = App\Models\Business::findOrFail((int) $businessId);
        $result = $app->make(App\Library\Usage\UsageWalletManager::class)->reserve($business, $meterKey, $idempotencyKey, $quantity);

        // §6 step 4 — reserve() has fully returned (no transaction open);
        // only the atomic-claim winner may ever reach the point that
        // would set m5_conversations_usage_tracking and call the
        // provider. This mirrors that exact gate.
        if ($result->createdByThisInvocation && $providerCallLogFile !== null) {
            $handle = fopen($providerCallLogFile, 'c+');
            flock($handle, LOCK_EX);
            fseek($handle, 0, SEEK_END);
            fwrite($handle, getmypid() . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        fwrite(STDOUT, sprintf(
            "OK granted=%s reservation_id=%s created=%s\n",
            $result->granted ? '1' : '0',
            $result->reservationId ?? 'null',
            $result->createdByThisInvocation ? '1' : '0',
        ));

        exit(0);
    }

    if ($mode === 'quicksend') {
        [, , $businessId, $countryId, $sendingServerId, $userId, $idempotencyToken] = $argv;

        $barrierFile = $argv[7] ?? null;
        $expectedArrivals = isset($argv[8]) ? (int) $argv[8] : null;
        $timeoutSeconds = isset($argv[9]) ? (float) $argv[9] : null;
        $providerCallLogFile = $argv[10] ?? null;

        waitForBarrier($barrierFile, $expectedArrivals, $timeoutSeconds);

        $user = App\Models\User::findOrFail((int) $userId);
        $country = App\Models\Country::findOrFail((int) $countryId);
        $sendingServer = App\Models\SendingServer::findOrFail((int) $sendingServerId);

        // This is a fresh Laravel app instance in its own OS process — the
        // pilot config values the parent test process set via config()
        // never cross process boundaries and must be set again here.
        config([
            'usage_billing.conversations_metering.pilot_business_id' => (int) $businessId,
            'usage_billing.conversations_metering.pilot_country_id' => (int) $countryId,
            'usage_billing.conversations_metering.pilot_sending_server_id' => (int) $sendingServerId,
        ]);

        $input = [
            'user' => $user,
            'sms_type' => 'plain',
            'sender_id' => 'ConcurrencyTestSender',
            'region_code' => $country->iso_code,
            'country_code' => $country->country_code,
            'recipient' => '5551234567',
            'message' => 'Concurrency race fixture message.',
            'sending_server' => $sendingServer->id,
            'idempotency_token' => $idempotencyToken,
        ];

        // Minimal anonymous provider-boundary double: the real,
        // unmodified quickSend() runs in full — only sendPlainSMS() (the
        // provider boundary) is stood in for. The marker is written
        // exactly when quickSend()'s own real control flow decides to
        // call it — this is the actual production decision, not a
        // restatement of it.
        $campaign = new class($providerCallLogFile) extends App\Models\Campaigns {
            public function __construct(private ?string $providerCallLogFile = null)
            {
                parent::__construct();
            }

            public function sendPlainSMS($data)
            {
                if ($this->providerCallLogFile !== null) {
                    $handle = fopen($this->providerCallLogFile, 'c+');
                    flock($handle, LOCK_EX);
                    fseek($handle, 0, SEEK_END);
                    fwrite($handle, getmypid() . "\n");
                    fflush($handle);
                    flock($handle, LOCK_UN);
                    fclose($handle);
                }

                return (object) [
                    'status' => 'Delivered', 'customer_status' => 'Delivered',
                    'cost' => 0, 'sms_count' => 1, 'm5_outcome' => 'accepted',
                ];
            }
        };

        $response = $app->make(App\Repositories\Eloquent\EloquentCampaignRepository::class)->quickSend($campaign, $input, true);
        $payload = $response->getData();

        fwrite(STDOUT, sprintf(
            "OK status=%s action=%s\n",
            $payload->status ?? 'null',
            $payload->m5_token_action ?? 'null',
        ));

        exit(0);
    }

    fwrite(STDERR, "Unknown mode [{$mode}].\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
