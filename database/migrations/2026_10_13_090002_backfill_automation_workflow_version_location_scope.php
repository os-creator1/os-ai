<?php

use App\Library\Automation\Migration\WorkflowVersionLocationScopeBackfillV1;
use Illuminate\Database\Migrations\Migration;

/**
 * Automations V1 completion — Location run-scope foundation, lane contract
 * §5C/§7: data only, no DDL. Delegates to the versioned, immutable
 * WorkflowVersionLocationScopeBackfillV1.
 *
 * Every existing published/superseded version becomes `all` — never a
 * silent narrowing of a workflow that already listened Business-wide.
 */
return new class extends Migration
{
    public function up(): void
    {
        $backfill = new WorkflowVersionLocationScopeBackfillV1();
        $updated = $backfill->run();

        logger()->info("automation_workflow_versions location_scope backfill: set 'all' on {$updated} published/superseded version(s).");

        $remaining = $backfill->unresolvedCount();

        if ($remaining > 0) {
            logger()->warning("automation_workflow_versions location_scope backfill: {$remaining} published/superseded version(s) still NULL after backfill — unexpected, investigate.");
        }
    }

    public function down(): void
    {
        // Non-destructive no-op, matching this repository's backfill
        // convention (ContactsLocationBackfillV1's own migration): once
        // WorkflowPublisher also writes location_scope for every new
        // publish, a backfilled 'all' cannot be told apart from a
        // genuinely chosen one, so clearing the column here could destroy
        // real, later-published scope decisions.
    }
};
