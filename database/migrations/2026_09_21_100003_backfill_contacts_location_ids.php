<?php

use App\Library\Contacts\Migration\ContactsLocationBackfillV1;
use Illuminate\Database\Migrations\Migration;

/**
 * Implementation Contract 08B §8: data only, no DDL. Delegates to the
 * versioned, immutable ContactsLocationBackfillV1.
 *
 * Never fails the migration for a Contact whose Location cannot be proven —
 * those rows stay NULL by design. Only aggregate counts are logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $backfill = new ContactsLocationBackfillV1();
        $summary = $backfill->run();

        logger()->info(sprintf(
            'contacts location_id backfill: resolved=%d, unresolved=%d (of which ambiguous across active Locations=%d)',
            $summary['resolved'],
            $summary['unresolved'],
            $summary['ambiguous'],
        ));

        $remaining = $backfill->unresolvedCount();

        if ($remaining > 0) {
            logger()->info("contacts location_id backfill: {$remaining} contact(s) remain NULL — expected where the Business is unknown, has no active Location, or has several; they stay unattributed rather than misfiled.");
        }
    }

    public function down(): void
    {
        // A non-destructive no-op, matching this repository's backfill
        // convention (ChatBoxLocationBackfillV1's own migration). Once
        // live producers also write location_id, a backfilled row cannot
        // be told apart from one written afterwards, so nulling the
        // column here could destroy real attribution.
    }
};
