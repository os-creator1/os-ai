<?php

    use App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1;
    use Illuminate\Database\Migrations\Migration;

    /**
     * RFC-004 §25.2-§25.4, step H — invokes the versioned backfill action
     * directly, never the console-command wrapper, mirroring RFC-003
     * migration 5's exact precedent.
     */
    return new class extends Migration {
        public function up(): void
        {
            (new WorkspaceEntitlementBackfillV1())->run();
        }

        public function down(): void
        {
            // Intentionally a no-op (RFC-004 §25.2, mirroring RFC-003
            // migration 5's identical rationale). Once this migration has
            // run, Workspace plan assignments and entitlement transitions it
            // created cannot be safely distinguished from legitimate ones
            // created afterward by ordinary application use — deleting them
            // here could destroy real data. Reverting a completed backfill
            // is a separate, explicitly reviewed operation, never an
            // automatic migration rollback.
        }
    };
