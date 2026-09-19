<?php

/**
 * Standalone runner invoked as a separate OS process by
 * PackageSnapshotConcurrencyTest (Implementation Contract 16 §7 / §12.D / §18.D),
 * so two genuinely independent database connections race for the same
 * `catalog_items` row lock that `PackageSnapshotService`, `CatalogItemManager`,
 * and `CatalogItemLocationOverrideManager` all take.
 *
 * Usage: php concurrent_package_snapshot_runner.php <mode> <args...>
 *
 * Modes:
 *   take-snapshot <itemId> <locationId> <actorUserId|null> <explicitPrice|null>
 *   update-item <businessId> <itemId> <attributesJson>
 *   archive-item <businessId> <itemId>
 *   set-override-price <businessId> <itemId> <locationId> <price|null>
 *   set-override-enabled <businessId> <itemId> <locationId> <0|1>
 *
 *   hold-item-then <itemId> <holdSeconds> <delegateMode> <delegateArgs...>
 *     Opens a transaction, takes SELECT ... FOR UPDATE on the catalog_items row,
 *     prints "LOCKED\n" (flushed), sleeps, then runs delegateMode inside the same
 *     transaction.
 *
 *   hold-snapshot-then <itemId> <locationId> <holdSeconds> <actorUserId|null> <explicitPrice|null>
 *     Opens a transaction, takes SELECT ... FOR UPDATE on the catalog_items row,
 *     prints "LOCKED\n" (flushed), sleeps, then calls PackageSnapshotService::snapshot().
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
    $snapshotService = $app->make(App\Library\Catalog\PackageSnapshotService::class);
    $itemManager = $app->make(App\Library\Catalog\CatalogItemManager::class);
    $overrideManager = $app->make(App\Library\Catalog\CatalogItemLocationOverrideManager::class);

    switch ($mode) {
        case 'take-snapshot':
            [, , $itemId, $locationId, $actorUserId, $explicitPriceStr] = $argv;
            $item = App\Models\CatalogItem::findOrFail((int) $itemId);
            $location = App\Models\BusinessLocation::findOrFail((int) $locationId);
            $actor = ($actorUserId !== 'null' && $actorUserId !== '') ? App\Models\User::find((int) $actorUserId) : null;
            $explicitPrice = ($explicitPriceStr !== 'null' && $explicitPriceStr !== '') ? (int) $explicitPriceStr : null;

            try {
                $snapshot = $snapshotService->snapshot($item, $location, $actor, $explicitPrice);
                fwrite(STDOUT, sprintf("OK snapshot_id=%d price=%d currency=%s\n", $snapshot->id, $snapshot->price_minor_at_snapshot, $snapshot->currency_code_at_snapshot));
                fflush(STDOUT);
            } catch (App\Library\Catalog\Exceptions\CatalogRuleException $e) {
                fwrite(STDOUT, sprintf("REFUSED message=%s\n", $e->getMessage()));
                fflush(STDOUT);
                exit(1);
            }

            return;

        case 'update-item':
            [, , $businessId, $itemId, $attributesJson] = $argv;
            $business = App\Models\Business::findOrFail((int) $businessId);
            $item = App\Models\CatalogItem::findOrFail((int) $itemId);
            $attributes = json_decode((string) $attributesJson, true, 512, JSON_THROW_ON_ERROR);

            $updated = $itemManager->update($business, $item, $attributes);
            fwrite(STDOUT, sprintf("OK item_id=%d price=%d\n", $updated->id, (int) $updated->price_minor));
            fflush(STDOUT);

            return;

        case 'archive-item':
            [, , $businessId, $itemId] = $argv;
            $business = App\Models\Business::findOrFail((int) $businessId);
            $item = App\Models\CatalogItem::findOrFail((int) $itemId);

            $archived = $itemManager->archive($business, $item);
            fwrite(STDOUT, sprintf("OK item_id=%d state=%s\n", $archived->id, $archived->lifecycle_state->value));
            fflush(STDOUT);

            return;

        case 'set-override-price':
            [, , $businessId, $itemId, $locationId, $priceStr] = $argv;
            $business = App\Models\Business::findOrFail((int) $businessId);
            $item = App\Models\CatalogItem::findOrFail((int) $itemId);
            $location = App\Models\BusinessLocation::findOrFail((int) $locationId);
            $price = ($priceStr === 'null' || $priceStr === '') ? null : (int) $priceStr;

            $override = $overrideManager->setPriceOverride($business, $item, $location, $price);
            fwrite(STDOUT, sprintf("OK override_id=%s\n", $override?->id ?? 'none'));
            fflush(STDOUT);

            return;

        case 'set-override-enabled':
            [, , $businessId, $itemId, $locationId, $isEnabledStr] = $argv;
            $business = App\Models\Business::findOrFail((int) $businessId);
            $item = App\Models\CatalogItem::findOrFail((int) $itemId);
            $location = App\Models\BusinessLocation::findOrFail((int) $locationId);

            $override = $overrideManager->setEnabled($business, $item, $location, $isEnabledStr === '1');
            fwrite(STDOUT, sprintf("OK override_id=%s\n", $override?->id ?? 'none'));
            fflush(STDOUT);

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

    if ($mode === 'hold-snapshot-then') {
        [, , $itemId, $locationId, $holdSeconds, $actorUserId, $explicitPriceStr] = $argv;

        Illuminate\Support\Facades\DB::transaction(function () use ($itemId, $locationId, $holdSeconds, $actorUserId, $explicitPriceStr, $app) {
            Illuminate\Support\Facades\DB::table('catalog_items')->where('id', (int) $itemId)->lockForUpdate()->first();

            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);

            if ((int) $holdSeconds > 0) {
                sleep((int) $holdSeconds);
            }

            $snapshotService = $app->make(App\Library\Catalog\PackageSnapshotService::class);
            $item = App\Models\CatalogItem::findOrFail((int) $itemId);
            $location = App\Models\BusinessLocation::findOrFail((int) $locationId);
            $actor = ($actorUserId !== 'null' && $actorUserId !== '') ? App\Models\User::find((int) $actorUserId) : null;
            $explicitPrice = ($explicitPriceStr !== 'null' && $explicitPriceStr !== '') ? (int) $explicitPriceStr : null;

            $snapshot = $snapshotService->snapshot($item, $location, $actor, $explicitPrice);
            fwrite(STDOUT, sprintf("OK snapshot_id=%d price=%d\n", $snapshot->id, $snapshot->price_minor_at_snapshot));
            fflush(STDOUT);
        });

        exit(0);
    }

    runMode((string) $mode, $argv, $app);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
