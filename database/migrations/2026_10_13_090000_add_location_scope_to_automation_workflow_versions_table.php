<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V1 completion — Location run-scope foundation.
 *
 * `location_scope` mirrors `enrollment_policy`/`failure_policy` (§4.2,
 * §7.5, §7.6) exactly: nullable, because a DRAFT's scope lives only in its
 * JSON `definition` document, and is denormalised onto this column by
 * `WorkflowPublisher::publish()` only once the version is actually
 * published. There is no DB-level NOT NULL enforcement for the same reason
 * those two sibling columns have none — application code is the single
 * writer, and it is guaranteed to set it at publish time.
 *
 * A following data migration backfills every EXISTING published/superseded
 * version to `'all'` (§5C of the lane's own contract): those workflows
 * already listened Business-wide before this feature existed, and this
 * column must never silently narrow one.
 *
 * Idempotent, guarded shape — mirrors
 * `2026_09_21_100001_add_location_id_to_contacts_table.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('automation_workflow_versions', 'location_scope')) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->string('location_scope', 16)->nullable()->after('failure_policy');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('automation_workflow_versions', 'location_scope')) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->dropColumn('location_scope');
            });
        }
    }
};
