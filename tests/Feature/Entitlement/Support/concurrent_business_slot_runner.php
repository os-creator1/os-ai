<?php

/**
 * Standalone runner invoked as a separate OS process by
 * EntitlementManagerConcurrencyTest, so its real cross-process concurrency
 * scenarios exercise genuinely independent database connections racing for
 * the same row lock — something a single PHPUnit process cannot do on its
 * own. Boots the app in the testing environment so it shares the same
 * ultimatesms_testing database and .env.testing credentials as the parent
 * PHPUnit process, following concurrent_backfill_runner.php's exact
 * bootstrap/database-guard/exit-code shape.
 *
 * A single selectable-mode script, not one file per scenario — every M2
 * concurrency scenario (§6 scenarios 1-2, 6-11) is expressed as one of the
 * modes below, reusing this one authorized Support file.
 *
 * Usage: php concurrent_business_slot_runner.php <mode> <args...>
 *
 * Modes:
 *   create-business <workspaceId> <customerId> <actorUserId>
 *   reassign-business <businessId> <targetWorkspaceId> <actorUserId>
 *   legacy-create <customerId>
 *   transfer-ownership <workspaceId> <actorUserId> <newOwnerUserId>
 *   catalog-clear <catalogId> <actorUserId>
 *   assign-first-plan <workspaceId> <tier> <actorUserId> <isComplimentary(0|1)>
 *   revoke-complimentary <workspaceId> <actorUserId>
 *   toggle-disable <businessId> <featureKey> <actorUserId>
 *   toggle-enable <businessId> <featureKey> <actorUserId>
 *
 *   hold-then <lockSpecs> <holdSeconds> <delegateMode> <delegateArgs...>
 *     Test-only deterministic direction-forcing primitive: opens one real
 *     transaction, takes an explicit `SELECT ... FOR UPDATE` lock on EACH
 *     row named in <lockSpecs> (a '|'-delimited list of 'table:column:id'
 *     triples, acquired strictly in the order given), prints "LOCKED"
 *     (flushed) so the parent test can confirm every listed lock is
 *     genuinely held before starting the racing "waiter" process, sleeps
 *     for <holdSeconds>, then runs <delegateMode> (any mode above) inside
 *     that SAME transaction. <lockSpecs> must be the exact prefix of
 *     <delegateMode>'s own real production lock sequence, up to and
 *     including the row the waiter actually contends on — e.g. Workspace
 *     then catalog for a paid assignFirstPlan()/revokeComplimentaryStatus()
 *     holder, or the owner's User row then the Workspace row for a
 *     legacy-create holder — never a single later row locked in isolation,
 *     which would silently invert the production method's real order. The
 *     delegate's own internal re-acquisition of each already-listed row is
 *     therefore a no-op, so its real logic proceeds through whatever
 *     further locks/writes it genuinely performs next, completely
 *     undisturbed and in its own real order, and commits only after the
 *     deliberate hold — guaranteeing the waiter (started only after seeing
 *     "LOCKED") genuinely blocks on the row and observes this holder's
 *     committed result once released. No production sleep/backoff — the
 *     sleep lives entirely in this test Support script.
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

/**
 * Runs a single mode's real production logic. Shared by the top-level
 * dispatch below and by the `hold-then` wrapper, so both a plain
 * (unheld) invocation and a deliberately lock-held invocation execute the
 * exact same real manager/repository calls — never a test-only stand-in.
 */
function runMode(string $mode, array $argv, $app): void
{
    switch ($mode) {
        case 'create-business':
            [, , $workspaceId, $customerId, $actorUserId] = $argv;
            $workspace = App\Models\Workspace::findOrFail((int) $workspaceId);
            $customer = App\Models\Customer::findOrFail((int) $customerId);
            $business = $app->make(App\Library\Workspace\WorkspaceManager::class)->createBusinessInWorkspace(
                (int) $actorUserId,
                $customer,
                $workspace,
                ['name' => 'Concurrency Business ' . uniqid(), 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD'],
            );
            fwrite(STDOUT, sprintf("OK business_id=%d\n", $business->id));

            return;

        case 'reassign-business':
            [, , $businessId, $targetWorkspaceId, $actorUserId] = $argv;
            $business = App\Models\Business::findOrFail((int) $businessId);
            $targetWorkspace = App\Models\Workspace::findOrFail((int) $targetWorkspaceId);
            $updated = $app->make(App\Library\Workspace\WorkspaceManager::class)->reassignBusiness(
                (int) $actorUserId,
                $business,
                $targetWorkspace,
            );
            fwrite(STDOUT, sprintf("OK business_id=%d workspace_id=%d\n", $updated->id, $updated->workspace_id));

            return;

        case 'legacy-create':
            [, , $customerId] = $argv;
            $customer = App\Models\Customer::findOrFail((int) $customerId);
            $business = $app->make(App\Library\Business\BusinessManager::class)->createOrUpdateOnboardingBusiness(
                $customer,
                null,
                ['name' => 'Legacy Concurrency Business ' . uniqid(), 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD'],
            );
            fwrite(STDOUT, sprintf("OK business_id=%d workspace_id=%d\n", $business->id, $business->workspace_id));

            return;

        case 'transfer-ownership':
            [, , $workspaceId, $actorUserId, $newOwnerUserId] = $argv;
            $workspace = App\Models\Workspace::findOrFail((int) $workspaceId);
            $disposition = App\DTO\Workspace\WorkspaceOwnershipTransferDisposition::deactivate();
            $updated = $app->make(App\Library\Workspace\WorkspaceManager::class)->transferOwnership(
                (int) $actorUserId,
                $workspace,
                (int) $newOwnerUserId,
                $disposition,
            );
            fwrite(STDOUT, sprintf("OK workspace_id=%d owner_user_id=%d\n", $updated->id, $updated->owner_user_id));

            return;

        case 'catalog-clear':
            [, , $catalogId, $actorUserId] = $argv;
            $catalog = App\Models\WorkspacePlanCatalog::findOrFail((int) $catalogId);
            $app->make(App\Library\Entitlement\EntitlementManager::class)->updateCatalogPricing(
                $catalog,
                null,
                null,
                null,
                (int) $actorUserId,
                'Concurrency runner fixture pricing clear.',
            );
            fwrite(STDOUT, sprintf("OK catalog_id=%d cleared\n", $catalog->id));

            return;

        case 'assign-first-plan':
            [, , $workspaceId, $tier, $actorUserId, $isComplimentary] = $argv;
            $workspace = App\Models\Workspace::findOrFail((int) $workspaceId);
            $assignment = $app->make(App\Library\Entitlement\EntitlementManager::class)->assignFirstPlan(
                $workspace,
                App\Enums\Entitlement\WorkspacePlanTier::from($tier),
                (int) $actorUserId,
                'Concurrency scenario fixture assignment.',
                $isComplimentary === '1',
                0,
            );
            fwrite(STDOUT, sprintf("OK assignment_id=%d\n", $assignment->id));

            return;

        case 'revoke-complimentary':
            [, , $workspaceId, $actorUserId] = $argv;
            $workspace = App\Models\Workspace::findOrFail((int) $workspaceId);
            $assignment = $app->make(App\Library\Entitlement\EntitlementManager::class)->revokeComplimentaryStatus(
                $workspace,
                (int) $actorUserId,
            );
            fwrite(STDOUT, sprintf("OK assignment_id=%d is_complimentary=%s\n", $assignment->id, $assignment->is_complimentary ? '1' : '0'));

            return;

        case 'toggle-disable':
            [, , $businessId, $featureKey, $actorUserId] = $argv;
            $business = App\Models\Business::findOrFail((int) $businessId);
            $toggle = $app->make(App\Library\Entitlement\EntitlementManager::class)->disableBusinessFeature(
                $business,
                App\Enums\Entitlement\PlatformFeature::from($featureKey),
                (int) $actorUserId,
            );
            fwrite(STDOUT, sprintf("OK toggle_id=%d\n", $toggle->id));

            return;

        case 'toggle-enable':
            [, , $businessId, $featureKey, $actorUserId] = $argv;
            $business = App\Models\Business::findOrFail((int) $businessId);
            $app->make(App\Library\Entitlement\EntitlementManager::class)->enableBusinessFeature(
                $business,
                App\Enums\Entitlement\PlatformFeature::from($featureKey),
                (int) $actorUserId,
            );
            fwrite(STDOUT, "OK toggle_removed\n");

            return;

        default:
            fwrite(STDERR, "Unknown mode [{$mode}].\n");
            exit(2);
    }
}

$mode = $argv[1] ?? null;

try {
    if ($mode === 'hold-then') {
        // hold-then <lockSpecs> <holdSeconds> <delegateMode> <delegateArgs...>
        // <lockSpecs> is a '|'-delimited list of 'table:column:id' triples,
        // acquired via SELECT ... FOR UPDATE strictly in the order given.
        // That order MUST be the exact prefix of the real production
        // lock sequence <delegateMode> itself would acquire up to and
        // including the row the racing "waiter" process contends on —
        // never a single later row acquired in isolation, which would
        // silently invert the production method's real lock order and
        // invalidate the proof. Once every listed row is locked, this
        // signals "LOCKED", sleeps, then runs the real delegate inside the
        // SAME transaction — its own internal re-acquisition of each
        // already-listed row is therefore a no-op, so it proceeds straight
        // into whatever further locks/writes it genuinely performs next,
        // completely undisturbed and in its own real order.
        [, , $lockSpecsRaw, $holdSeconds, $delegateMode] = $argv;
        $delegateArgv = array_merge([$argv[0], $delegateMode], array_slice($argv, 5));

        $lockSpecs = array_map(
            static fn (string $spec) => explode(':', $spec, 3),
            explode('|', $lockSpecsRaw)
        );

        Illuminate\Support\Facades\DB::transaction(function () use ($lockSpecs, $holdSeconds, $delegateMode, $delegateArgv, $app) {
            foreach ($lockSpecs as [$lockTable, $lockColumn, $lockId]) {
                Illuminate\Support\Facades\DB::table($lockTable)->where($lockColumn, $lockId)->lockForUpdate()->first();
            }

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
