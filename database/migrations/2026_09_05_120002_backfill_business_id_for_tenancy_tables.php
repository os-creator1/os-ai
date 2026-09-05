<?php

use App\Library\Business\Migration\BusinessDataTenancyBackfillV1;
use Illuminate\Database\Migrations\Migration;

/**
 * Business Data Tenancy Foundation, Pass 1 — data operations only, no DDL.
 * Delegates to the versioned, immutable BusinessDataTenancyBackfillV1.
 *
 * Deliberately does not fail the migration when historical rows cannot be
 * deterministically resolved — those rows are left NULL by design (see
 * the resolver's own never-guess rules) and reported via the logged
 * per-table summary plus BusinessDataTenancyBackfillV1::unresolvedCounts().
 */
return new class extends Migration {
    public function up(): void
    {
        $backfill = new BusinessDataTenancyBackfillV1();
        $summary = $backfill->run();

        foreach ($summary as $table => $counts) {
            logger()->info("business_id backfill [{$table}]: resolved={$counts['resolved']}, unresolved={$counts['unresolved']}");
        }

        foreach ($backfill->unresolvedCounts() as $table => $count) {
            if ($count > 0) {
                logger()->info("business_id backfill [{$table}]: {$count} row(s) remain NULL (unresolvable owner — expected for legacy data with no matching Business).");
            }
        }
    }

    public function down(): void
    {
        // Intentionally a non-destructive no-op (matching this repo's own
        // WorkspaceBackfillV1/UsageWalletBackfillV1 convention). Once this
        // migration has run, backfilled rows cannot be safely distinguished
        // from rows populated afterward by ordinary dual-write application
        // code — nulling business_id here could destroy real data.
        // Reverting a completed backfill is a separate, explicitly
        // reviewed operation, never an automatic migration rollback.
    }
};
