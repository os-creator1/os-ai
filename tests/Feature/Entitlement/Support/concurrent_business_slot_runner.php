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

$mode = $argv[1] ?? null;

try {
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
            exit(0);

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
            exit(0);

        case 'legacy-create':
            [, , $customerId] = $argv;
            $customer = App\Models\Customer::findOrFail((int) $customerId);
            $business = $app->make(App\Library\Business\BusinessManager::class)->createOrUpdateOnboardingBusiness(
                $customer,
                null,
                ['name' => 'Legacy Concurrency Business ' . uniqid(), 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD'],
            );
            fwrite(STDOUT, sprintf("OK business_id=%d workspace_id=%d\n", $business->id, $business->workspace_id));
            exit(0);

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
            exit(0);

        case 'catalog-clear':
            [, , $catalogId, $actorUserId] = $argv;
            $catalog = App\Models\WorkspacePlanCatalog::findOrFail((int) $catalogId);
            $app->make(App\Library\Entitlement\EntitlementManager::class)->updateCatalogPricing(
                $catalog,
                null,
                null,
                (int) $actorUserId,
            );
            fwrite(STDOUT, sprintf("OK catalog_id=%d cleared\n", $catalog->id));
            exit(0);

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
            exit(0);

        case 'revoke-complimentary':
            [, , $workspaceId, $actorUserId] = $argv;
            $workspace = App\Models\Workspace::findOrFail((int) $workspaceId);
            $assignment = $app->make(App\Library\Entitlement\EntitlementManager::class)->revokeComplimentaryStatus(
                $workspace,
                (int) $actorUserId,
            );
            fwrite(STDOUT, sprintf("OK assignment_id=%d is_complimentary=%s\n", $assignment->id, $assignment->is_complimentary ? '1' : '0'));
            exit(0);

        case 'toggle-disable':
            [, , $businessId, $featureKey, $actorUserId] = $argv;
            $business = App\Models\Business::findOrFail((int) $businessId);
            $toggle = $app->make(App\Library\Entitlement\EntitlementManager::class)->disableBusinessFeature(
                $business,
                App\Enums\Entitlement\PlatformFeature::from($featureKey),
                (int) $actorUserId,
            );
            fwrite(STDOUT, sprintf("OK toggle_id=%d\n", $toggle->id));
            exit(0);

        case 'toggle-enable':
            [, , $businessId, $featureKey, $actorUserId] = $argv;
            $business = App\Models\Business::findOrFail((int) $businessId);
            $app->make(App\Library\Entitlement\EntitlementManager::class)->enableBusinessFeature(
                $business,
                App\Enums\Entitlement\PlatformFeature::from($featureKey),
                (int) $actorUserId,
            );
            fwrite(STDOUT, "OK toggle_removed\n");
            exit(0);

        default:
            fwrite(STDERR, "Unknown mode [{$mode}].\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
