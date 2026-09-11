<?php

use App\Library\Business\Migration\ChatBoxBusinessBackfillV1;
use Illuminate\Database\Migrations\Migration;

/**
 * Customer Experience Redesign — Slice 2B §4: data only, no DDL. Delegates to
 * the versioned, immutable ChatBoxBusinessBackfillV1.
 *
 * Never fails the migration for a conversation whose Business cannot be
 * proven — those rows stay NULL by design and remain unreachable from every
 * Business route. Only aggregate counts are logged; no phone number, name or
 * message ever reaches a log line.
 */
return new class extends Migration
{
    public function up(): void
    {
        $backfill = new ChatBoxBusinessBackfillV1();
        $summary = $backfill->run();

        logger()->info(sprintf(
            'chat_boxes business_id backfill: resolved=%d, unresolved=%d (of which ambiguous across Businesses=%d)',
            $summary['resolved'],
            $summary['unresolved'],
            $summary['ambiguous'],
        ));

        $remaining = $backfill->unresolvedCount();

        if ($remaining > 0) {
            logger()->info("chat_boxes business_id backfill: {$remaining} conversation(s) remain NULL — expected for legacy data whose Business cannot be proven; they stay out of every Business inbox.");
        }
    }

    public function down(): void
    {
        // A non-destructive no-op, matching this repository's backfill
        // convention. Once live producers also write business_id, a
        // backfilled row cannot be told apart from one written afterwards,
        // so nulling the column here could destroy real attribution.
    }
};
