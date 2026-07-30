<?php

    use App\Library\Workspace\Migration\WorkspaceBackfillV1;
    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {
        public function up(): void
        {
            (new WorkspaceBackfillV1())->run();
        }

        public function down(): void
        {
            // Intentionally a no-op (RFC-003 §10.1). Once this migration has
            // run, Workspaces it created and Businesses it assigned cannot be
            // safely distinguished from legitimate Workspaces/assignments
            // created afterward by ordinary application use — nulling
            // workspace_id or deleting Workspaces here could destroy real
            // data. Reverting a completed backfill is a separate, explicitly
            // reviewed operation, never an automatic migration rollback.
        }
    };
