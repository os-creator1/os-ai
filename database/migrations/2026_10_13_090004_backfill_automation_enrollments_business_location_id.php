<?php

use App\Library\Automation\Migration\AutomationEnrollmentLocationBackfillV1;
use Illuminate\Database\Migrations\Migration;

/**
 * Automations V1 completion — Location run-scope foundation, lane contract
 * §7: data only, no DDL. Delegates to the versioned, immutable
 * AutomationEnrollmentLocationBackfillV1.
 *
 * CORRECTED (independent review, pre-merge): the backfill now resolves only
 * from immutable, trigger-appropriate evidence — the enrolled Contact's own
 * unambiguous conversation for message_received, the deal's own Location for
 * the four CRM triggers — and deliberately proves nothing at all for
 * contact_created/contact_date_reached/manual_enrollment, since no change
 * history exists to tell a Contact's Location today from its Location at
 * enrollment time. See the class's own docblock for the full reasoning.
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
        $result = $backfill->run();

        logger()->info(sprintf(
            'automation_enrollments business_location_id backfill: resolved %d row(s) — %d from their conversation (message_received), %d from their deal (CRM triggers).',
            $result['total'],
            $result['message_received'],
            $result['crm'],
        ));

        $remaining = $backfill->unresolvedCount();

        if ($remaining > 0) {
            logger()->info("automation_enrollments business_location_id backfill: {$remaining} enrollment(s) remain NULL — expected for contact_created/contact_date_reached/manual_enrollment (no provable historical Contact Location exists) and for any message/CRM run whose own evidence could not be proven either; they stay unattributed rather than guessed.");
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
