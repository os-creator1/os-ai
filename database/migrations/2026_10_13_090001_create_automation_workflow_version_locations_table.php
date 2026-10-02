<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V1 completion — Location run-scope foundation.
 *
 * The normalised Selected/One Location association for a published (or
 * superseded) `automation_workflow_versions` row. `All` scope needs no rows
 * here at all — it is checked against `business_locations` directly, so a
 * newly added Location naturally becomes eligible without a backfill.
 *
 * `version_id` IS DELIBERATELY NOT AN ENFORCED FOREIGN KEY — mirroring
 * `2026_09_18_100001_add_automation_step_run_id_to_reports_table.php`'s own
 * documented precedent exactly. `automation_workflow_versions` is one of the
 * six V2-0 "foundation" migrations whose own rollback-and-replay-on-its-own
 * guarantee `MigrationIntegrityTest` (T-WF-28/29/30) enforces with a
 * PATH-SCOPED `migrate:rollback` naming exactly those six files — because
 * (per that test's own docblock) "this repository's global down() chain is
 * not rollback-clean," that scoped six-migration round trip is the only
 * rollback proof the V2 foundation has. A real foreign key from a LATER
 * migration (this one) into `automation_workflow_versions` would make that
 * scoped rollback impossible without first altering a migration outside its
 * own path — exactly the coupling the `reports` precedent exists to
 * prevent. (Confirmed by reproduction: with an enforced FK here, the
 * existing `MigrationIntegrityTest` fails with MySQL error 3730 —
 * "Cannot drop table ... referenced by a foreign key constraint.")
 *
 * This loses nothing in practice: per §4.7/§6.2, a version that has ANY row
 * here is by definition published or superseded, and a workflow that has
 * ever been published is never hard-deleted (archived instead) while any
 * version of it still exists — so no code path ever needs to cascade a
 * delete through this column. Every reader still re-verifies the id
 * resolves to a real, same-Business version, the same discipline the
 * `reports` mark's readers already follow for their own unenforced
 * reference.
 *
 * `business_location_id` RESTRICTS with a real FK, matching
 * `contacts.location_id`/`chat_boxes.location_id`'s own precedent (§19 of
 * the lane's contract) — `business_locations` is not part of the V2-0
 * foundation's own rollback cycle, so no such conflict applies to it.
 *
 * `UNIQUE(version_id, business_location_id)` — a Location cannot be listed
 * twice for one version.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automation_workflow_version_locations')) {
            return;
        }

        Schema::create('automation_workflow_version_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('business_location_id');
            $table->timestamps();

            $table->unique(['version_id', 'business_location_id'], 'awvl_version_location_unique');
            $table->index('version_id', 'awvl_version_index');
            $table->index('business_location_id', 'awvl_location_index');

            $table->foreign('business_location_id', 'awvl_location_foreign')
                ->references('id')->on('business_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_workflow_version_locations');
    }
};
