<?php

/**
 * Standalone runner invoked as a separate OS process by
 * BusinessLocationConcurrencyTest (Customer Experience Slice 1A, T-LOC-6),
 * so two genuinely independent database connections race for the same
 * Business row lock. Boots the app in the testing environment and follows
 * the merged runners' exact database-guard/exit-code shape
 * (tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php).
 *
 * Usage: php concurrent_location_runner.php <mode> <args...>
 *
 * Modes:
 *   create-location <businessId> <actorUserId> <name>
 *   reactivate-location <locationId> <actorUserId>
 *
 *   hold-then <businessId> <holdSeconds> <delegateMode> <delegateArgs...>
 *     Opens one transaction, takes `SELECT ... FOR UPDATE` on the Business
 *     row — the FIRST lock BusinessLocationManager's create and reactivate
 *     paths themselves take, so this is a faithful prefix of their real
 *     lock order — prints "LOCKED" (flushed), sleeps, then runs the real
 *     delegate inside the SAME transaction. The waiter the parent starts
 *     after "LOCKED" therefore genuinely blocks on the Business row and
 *     then observes this holder's committed result. No production sleep:
 *     the sleep lives only in this test Support script.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run: ' . $e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

function runMode(string $mode, array $argv, $app): void
{
    $manager = $app->make(App\Library\Business\BusinessLocationManager::class);

    switch ($mode) {
        case 'create-location':
            [, , $businessId, $actorUserId, $name] = $argv;
            $location = $manager->createLocation(App\Models\Business::findOrFail((int) $businessId), [
                'name' => $name, 'service_mode' => 'storefront', 'address_line_1' => '1 Race Street',
                'city' => 'Springfield', 'region' => 'IL', 'country_code' => 'US', 'public_address' => false,
            ], (int) $actorUserId);
            fwrite(STDOUT, sprintf("OK location_id=%d\n", $location->id));

            return;

        case 'reactivate-location':
            [, , $locationId, $actorUserId] = $argv;
            $location = $manager->reactivateLocation(App\Models\BusinessLocation::findOrFail((int) $locationId), (int) $actorUserId);
            fwrite(STDOUT, sprintf("OK location_id=%d\n", $location->id));

            return;
    }

    throw new InvalidArgumentException("Unknown mode [{$mode}].");
}

$mode = $argv[1] ?? null;

try {
    if ($mode === 'hold-then') {
        [, , $businessId, $holdSeconds, $delegateMode] = $argv;
        $delegateArgv = array_merge([$argv[0], $delegateMode], array_slice($argv, 5));

        Illuminate\Support\Facades\DB::transaction(function () use ($businessId, $holdSeconds, $delegateMode, $delegateArgv, $app) {
            Illuminate\Support\Facades\DB::table('businesses')->where('id', (int) $businessId)->lockForUpdate()->first();

            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);

            if ((int) $holdSeconds > 0) {
                sleep((int) $holdSeconds);
            }

            runMode($delegateMode, $delegateArgv, $app);
        });

        exit(0);
    }

    runMode((string) $mode, $argv, $app);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
