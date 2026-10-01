<?php

use App\Library\Crm\Migration\LegacyContactTagsBackfillV1;
use Illuminate\Database\Migrations\Migration;

/**
 * Contact Tags foundation §5 — run the legacy JSON backfill once, as a
 * migration, exactly like `090004_backfill_automation_enrollments_business
 * _location_id.php` already does for a comparable historical-data
 * attribution problem. See `LegacyContactTagsBackfillV1`'s own docblock for
 * the full schema-ambiguity and determinism reasoning; this file only
 * reports the result.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = (new LegacyContactTagsBackfillV1())->run();

        if (! $result['ran']) {
            logger()->info("contacts.tags legacy backfill: skipped — {$result['reason']}.");

            return;
        }

        logger()->info(sprintf(
            'contacts.tags legacy backfill: %d contact(s) processed, %d tag(s) created, %d membership(s) created, '
                . '%d unmappable contact(s) (no business_id), %d malformed tags value(s), %d malformed entr(y/ies).',
            $result['contacts_processed'],
            $result['tags_created'],
            $result['memberships_created'],
            $result['unmappable_contacts'],
            $result['malformed_contacts'],
            $result['malformed_entries'],
        ));
    }

    public function down(): void
    {
        // Historical attribution, not a schema change — nothing to reverse
        // here beyond dropping the tables themselves, which the two
        // preceding migrations' own down() methods already do.
    }
};
