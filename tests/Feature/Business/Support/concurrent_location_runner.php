<?php

/**
 * Customer Experience Slice 1A — standalone runner invoked as a separate OS
 * process by BusinessLocationConcurrencyTest, so its scenarios exercise
 * genuinely independent database connections racing for the same Business
 * row lock. A single PHPUnit process cannot prove that on its own.
 *
 * Follows concurrent_business_slot_runner.php's exact bootstrap,
 * database-guard and exit-code shape, and always calls the REAL production
 * managers — never a test-only stand-in.
 *
 * Usage: php concurrent_location_runner.php <mode> <args...>
 *
 * Modes:
 *   create-location   <businessId>
 *   reactivate        <businessId> <locationUid>
 *   allocate          <businessId> <actorUserId>
 *
 * Exit codes: 0 success, 1 refused (capacity or lifecycle), 3 wrong database.
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

$mode = $argv[1] ?? '';

try {
    switch ($mode) {
        case 'create-location':
            $business = App\Models\Business::findOrFail((int) $argv[2]);
            $location = $app->make(App\Library\Business\BusinessLocationManager::class)->createLocation($business, [
                'name' => 'Race Branch ' . uniqid(),
                'service_mode' => App\Enums\Business\BusinessServiceMode::Storefront->value,
                'address_line_1' => '3 Race Street',
                'city' => 'New York',
                'country_code' => 'US',
                'public_address' => true,
            ]);
            fwrite(STDOUT, sprintf("OK location_id=%d\n", $location->id));

            exit(0);

        case 'reactivate':
            $business = App\Models\Business::findOrFail((int) $argv[2]);
            $manager = $app->make(App\Library\Business\BusinessLocationManager::class);
            $location = $manager->findLocation($business, (string) $argv[3]);

            if ($location === null) {
                fwrite(STDERR, "Location not found.\n");

                exit(1);
            }

            $manager->reactivateLocation($business, $location);
            fwrite(STDOUT, sprintf("OK location_id=%d\n", $location->id));

            exit(0);

        case 'allocate':
            // Correction round 1 — the seam takes an explicit authority, not
            // an actor id. $argv[3] is a platform administrator's id, which
            // EntitlementManager re-verifies against users.is_admin.
            $business = App\Models\Business::findOrFail((int) $argv[2]);
            $updated = $app->make(App\Library\Entitlement\EntitlementManager::class)
                ->allocateAdditionalLocationSlot(
                    $business,
                    App\Library\Entitlement\LocationSlotAllocationAuthority::fromPlatformOperator(
                        (int) $argv[3],
                        'Concurrency runner operator allocation.',
                    ),
                );
            fwrite(STDOUT, sprintf("OK additional_location_slots=%d\n", $updated->additional_location_slots));

            exit(0);

        default:
            fwrite(STDERR, "Unknown mode [{$mode}].\n");

            exit(2);
    }
} catch (App\Exceptions\Entitlement\LocationSlotAllocationRequiredException $e) {
    fwrite(STDERR, "REFUSED location_slot_allocation_required\n");

    exit(1);
} catch (App\Exceptions\Entitlement\LocationSlotLimitExceededException $e) {
    fwrite(STDERR, "REFUSED location_slot_limit_exceeded\n");

    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n");

    exit(4);
}
