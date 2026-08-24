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
 * createdByThisInvocation = true.
 *
 * Usage: php concurrent_conversations_send_runner.php reserve <businessId> <meterKey> <idempotencyKey> <quantity> [<barrierFile> <expectedArrivals> <timeoutSeconds>]
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

        waitForBarrier($barrierFile, $expectedArrivals, $timeoutSeconds);

        $business = App\Models\Business::findOrFail((int) $businessId);
        $result = $app->make(App\Library\Usage\UsageWalletManager::class)->reserve($business, $meterKey, $idempotencyKey, $quantity);

        fwrite(STDOUT, sprintf(
            "OK granted=%s reservation_id=%s created=%s\n",
            $result->granted ? '1' : '0',
            $result->reservationId ?? 'null',
            $result->createdByThisInvocation ? '1' : '0',
        ));

        exit(0);
    }

    fwrite(STDERR, "Unknown mode [{$mode}].\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
