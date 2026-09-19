<?php

/**
 * Standalone runner invoked as a separate OS process by
 * CatalogItemLocationOverrideManagerConcurrencyTest (Implementation
 * Contract 16 §7/§12.C/§18.C), so two genuinely independent database
 * connections race for the same `catalog_items` row lock
 * `CatalogItemLocationOverrideManager` itself takes. Boots the app in the
 * testing environment and follows the merged runners' exact
 * database-guard/exit-code shape
 * (tests/Feature/Catalog/Support/concurrent_catalog_item_runner.php).
 *
 * Usage: php concurrent_catalog_override_runner.php <mode> <args...>
 *
 * Modes:
 *   set-enabled <businessId> <itemId> <locationId> <0|1>
 *   set-price <businessId> <itemId> <locationId> <price|null>
 *
 *   hold-item-then <itemId> <holdSeconds> <delegateMode> <delegateArgs...>
 *     Opens one transaction, takes `SELECT ... FOR UPDATE` on the
 *     `catalog_items` row — the FIRST lock
 *     CatalogItemLocationOverrideManager::setOverride() itself takes — prints
 *     "LOCKED" (flushed), sleeps, then runs the real delegate inside the
 *     SAME transaction. The waiter the parent starts after "LOCKED"
 *     therefore genuinely blocks on the catalog-item row and then observes
 *     this holder's committed result.
 *
 *   No production sleep: the sleep lives only in this test Support script.
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
    $manager = $app->make(App\Library\Catalog\CatalogItemLocationOverrideManager::class);

    switch ($mode) {
        case 'set-enabled':
            [, , $businessId, $itemId, $locationId, $isEnabled] = $argv;
            $override = $manager->setEnabled(
                App\Models\Business::findOrFail((int) $businessId),
                App\Models\CatalogItem::findOrFail((int) $itemId),
                App\Models\BusinessLocation::findOrFail((int) $locationId),
                $isEnabled === '1',
            );
            // null is the honest, expected result when the merged state is
            // the canonical sparse default (§5.2) -- not an error.
            fwrite(STDOUT, $override === null ? "OK override_id=none\n" : sprintf("OK override_id=%d\n", $override->id));

            return;

        case 'set-price':
            [, , $businessId, $itemId, $locationId, $price] = $argv;
            $override = $manager->setPriceOverride(
                App\Models\Business::findOrFail((int) $businessId),
                App\Models\CatalogItem::findOrFail((int) $itemId),
                App\Models\BusinessLocation::findOrFail((int) $locationId),
                $price === 'null' ? null : (int) $price,
            );
            fwrite(STDOUT, $override === null ? "OK override_id=none\n" : sprintf("OK override_id=%d\n", $override->id));

            return;
    }

    throw new InvalidArgumentException("Unknown mode [{$mode}].");
}

$mode = $argv[1] ?? null;

try {
    if ($mode === 'hold-item-then') {
        [, , $itemId, $holdSeconds, $delegateMode] = $argv;
        $delegateArgv = array_merge([$argv[0], $delegateMode], array_slice($argv, 5));

        Illuminate\Support\Facades\DB::transaction(function () use ($itemId, $holdSeconds, $delegateMode, $delegateArgv, $app) {
            Illuminate\Support\Facades\DB::table('catalog_items')->where('id', (int) $itemId)->lockForUpdate()->first();

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
