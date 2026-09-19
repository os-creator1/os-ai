<?php

/**
 * Standalone runner invoked as a separate OS process by
 * CatalogItemManagerConcurrencyTest (Implementation Contract 16 §7, §12.B,
 * §18.B), so two genuinely independent database connections race for the
 * same `catalog_items`/`businesses` row lock `CatalogItemManager` itself
 * takes. Boots the app in the testing environment and follows the merged
 * runners' exact database-guard/exit-code shape
 * (tests/Feature/Business/Support/concurrent_location_runner.php).
 *
 * Usage: php concurrent_catalog_item_runner.php <mode> <args...>
 *
 * Modes:
 *   create-item <businessId> <name>
 *   update-item <businessId> <itemId> <attributesJson>
 *   archive-item <businessId> <itemId>
 *   reactivate-item <businessId> <itemId>
 *
 *   hold-item-then <itemId> <holdSeconds> <delegateMode> <delegateArgs...>
 *     Opens one transaction, takes `SELECT ... FOR UPDATE` on the
 *     `catalog_items` row — the FIRST lock CatalogItemManager's update/
 *     archive/reactivate paths themselves take, so this is a faithful
 *     prefix of their real lock order — prints "LOCKED" (flushed), sleeps,
 *     then runs the real delegate inside the SAME transaction. The waiter
 *     the parent starts after "LOCKED" therefore genuinely blocks on the
 *     catalog-item row and then observes this holder's committed result.
 *
 *   hold-business-then <businessId> <holdSeconds> <delegateMode> <delegateArgs...>
 *     Same shape, but takes `SELECT ... FOR UPDATE` on the `businesses`
 *     row — the parent serialization point CatalogItemManager::create()
 *     itself takes before it computes the next position.
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
    $manager = $app->make(App\Library\Catalog\CatalogItemManager::class);

    switch ($mode) {
        case 'create-item':
            [, , $businessId, $name] = $argv;
            $item = $manager->create(App\Models\Business::findOrFail((int) $businessId), [
                'type' => 'product',
                'name' => $name,
            ]);
            fwrite(STDOUT, sprintf("OK item_id=%d position=%d\n", $item->id, $item->position));

            return;

        case 'update-item':
            [, , $businessId, $itemId, $attributesJson] = $argv;
            $attributes = json_decode((string) $attributesJson, true, 512, JSON_THROW_ON_ERROR);
            $item = $manager->update(
                App\Models\Business::findOrFail((int) $businessId),
                App\Models\CatalogItem::findOrFail((int) $itemId),
                $attributes,
            );
            fwrite(STDOUT, sprintf("OK item_id=%d\n", $item->id));

            return;

        case 'archive-item':
            [, , $businessId, $itemId] = $argv;
            $item = $manager->archive(
                App\Models\Business::findOrFail((int) $businessId),
                App\Models\CatalogItem::findOrFail((int) $itemId),
            );
            fwrite(STDOUT, sprintf("OK item_id=%d\n", $item->id));

            return;

        case 'reactivate-item':
            [, , $businessId, $itemId] = $argv;
            $item = $manager->reactivate(
                App\Models\Business::findOrFail((int) $businessId),
                App\Models\CatalogItem::findOrFail((int) $itemId),
            );
            fwrite(STDOUT, sprintf("OK item_id=%d\n", $item->id));

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

    if ($mode === 'hold-business-then') {
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
