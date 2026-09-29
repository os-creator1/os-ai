<?php

use App\Library\Automation\Migration\AutomationEnrollmentLocationBackfillV1;
use Illuminate\Database\Migrations\Migration;

/**
 * Automations V1 completion — Location run-scope foundation, lane contract
 * §7: data only, no DDL. Delegates to the versioned, immutable
 * AutomationEnrollmentLocationBackfillV1.
 *
 * Never fails the migration for an enrollment whose Location cannot be
 * proven — those rows stay NULL by design (§7 of the lane's contract: "do
 * not fabricate historical facts"). Only aggregate counts are logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $backfill = new AutomationEnrollmentLocationBackfillV1();
        $updated = $backfill->run();

        logger()->info("automation_enrollments business_location_id backfill: resolved {$updated} row(s) from their Contact's own proven Location.");

        $remaining = $backfill->unresolvedCount();

        if ($remaining > 0) {
            logger()->info("automation_enrollments business_location_id backfill: {$remaining} enrollment(s) remain NULL — expected where the Contact's own Location was never proven either; they stay unattributed rather than guessed.");
        }
    }

    public function down(): void
    {
        // Non-destructive no-op, matching this repository's backfill
        // convention: once WorkflowEnrollmentService also writes
        // business_location_id for every new enrollment, a backfilled row
        // cannot be told apart from a live one, so nulling the column here
        // could destroy real attribution.
    }
};
