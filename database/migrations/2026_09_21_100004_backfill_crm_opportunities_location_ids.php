<?php

use App\Library\Crm\Migration\CrmOpportunityLocationBackfillV1;
use Illuminate\Database\Migrations\Migration;

/**
 * Implementation Contract 08B §8: data only, no DDL. Delegates to the
 * versioned, immutable CrmOpportunityLocationBackfillV1.
 *
 * Never fails the migration for a deal whose Location cannot be proven —
 * those rows stay NULL by design. Only aggregate counts are logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $backfill = new CrmOpportunityLocationBackfillV1();
        $summary = $backfill->run();

        logger()->info(sprintf(
            'crm_opportunities location_id backfill: resolved=%d, unresolved=%d (of which ambiguous across active Locations=%d)',
            $summary['resolved'],
            $summary['unresolved'],
            $summary['ambiguous'],
        ));

        $remaining = $backfill->unresolvedCount();

        if ($remaining > 0) {
            logger()->info("crm_opportunities location_id backfill: {$remaining} deal(s) remain NULL — expected where the Business is unknown, has no active Location, or has several; they stay unattributed rather than misfiled.");
        }
    }

    public function down(): void
    {
        // A non-destructive no-op, matching this repository's backfill
        // convention.
    }
};
