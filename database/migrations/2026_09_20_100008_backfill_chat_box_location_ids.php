<?php

use App\Library\Business\Migration\ChatBoxLocationBackfillV1;
use Illuminate\Database\Migrations\Migration;

/**
 * Implementation Contract 06 §8: data only, no DDL. Delegates to the
 * versioned, immutable ChatBoxLocationBackfillV1.
 *
 * Never fails the migration for a conversation whose Location cannot be
 * proven — those rows stay NULL by design, exactly as rows whose Business
 * cannot be proven already do. Only aggregate counts are logged; no phone
 * number, name or message ever reaches a log line.
 */
return new class extends Migration
{
    public function up(): void
    {
        $backfill = new ChatBoxLocationBackfillV1();
        $summary = $backfill->run();

        logger()->info(sprintf(
            'chat_boxes location_id backfill: resolved=%d, unresolved=%d (of which ambiguous across active Locations=%d)',
            $summary['resolved'],
            $summary['unresolved'],
            $summary['ambiguous'],
        ));

        $remaining = $backfill->unresolvedCount();

        if ($remaining > 0) {
            logger()->info("chat_boxes location_id backfill: {$remaining} conversation(s) remain NULL — expected where the Business is unknown, has no active Location, or has several; they stay unattributed rather than misfiled.");
        }
    }

    public function down(): void
    {
        // A non-destructive no-op, matching this repository's backfill
        // convention. Once live producers also write location_id, a
        // backfilled row cannot be told apart from one written afterwards,
        // so nulling the column here could destroy real attribution.
    }
};
