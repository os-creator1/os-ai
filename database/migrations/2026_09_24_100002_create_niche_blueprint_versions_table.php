<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 20 §5.2, Sub-slice A, migration 2 of 4 — the versioned,
 * publish-once snapshot a Blueprint installation is made from.
 *
 * It does two things, in this order:
 *
 *   (a) create `niche_blueprint_versions`, including the
 *       `UNIQUE (id, blueprint_id)` that migration 3's composite foreign key
 *       requires (InnoDB needs the referenced columns indexed in the
 *       referencing order; trivially unique because `id` is the primary key);
 *   (b) add the two STORED generated guard columns and their UNIQUE indexes,
 *       which is how MySQL enforces "at most one draft" and "at most one
 *       published version" per Blueprint — MySQL has no partial unique index,
 *       so this mirrors the already-merged, already-tested pattern in
 *       2026_09_15_100002_create_automation_workflow_versions_table.php.
 *
 * A draft row yields its `blueprint_id` in `draft_guard` and NULL in
 * `published_guard`, and vice versa; a superseded row yields NULL in both, so
 * any number of superseded versions coexist. `superseded` IS the archived/
 * deprecated state — there is no fourth state and no separate archive table,
 * because installation records cite a retired version's `version_number` as
 * provenance forever (§5.4).
 *
 * IMMUTABLE ONCE IT LEAVES DRAFT. This row and its component rows are never
 * written again after publish. That invariant is enforced the way
 * `WebsiteRevision` and `AutomationWorkflowVersion` enforce theirs — the
 * publishing service (Sub-slice B) is the only writer, proven by a
 * source-boundary test — and this migration deliberately claims no stronger
 * database-level guarantee than those precedents actually provide.
 *
 * `published_by_user_id` is a plain nullable scalar with NO foreign key, per
 * §5.2 and the `workspace_entitlement_transitions` convention: an actor-
 * identity column must never block a legitimate user-deletion feature
 * elsewhere in the system. This is a deliberate divergence from
 * `automation_workflow_versions`, which does constrain its equivalent column;
 * the contract chose the transitions-table convention for this slice and that
 * is what ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        // (a) The table.
        Schema::create('niche_blueprint_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid');
            $table->unsignedBigInteger('blueprint_id');
            $table->unsignedInteger('version_number');

            // draft | published | superseded
            $table->string('state', 16)->default('draft');

            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('uid', 'nbv_uid_unique');
            $table->unique(['blueprint_id', 'version_number'], 'nbv_blueprint_version_unique');

            // Required by migration 3's composite foreign key.
            $table->unique(['id', 'blueprint_id'], 'nbv_id_blueprint_unique');

            $table->foreign('blueprint_id', 'nbv_blueprint_foreign')
                ->references('id')->on('niche_blueprints')->restrictOnDelete();

            $table->index(['blueprint_id', 'state'], 'nbv_blueprint_state_index');
        });

        // (b) The two guard columns, then their unique indexes.
        Schema::table('niche_blueprint_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('draft_guard')
                ->nullable()
                ->storedAs("CASE WHEN state = 'draft' THEN blueprint_id ELSE NULL END")
                ->after('state');

            $table->unsignedBigInteger('published_guard')
                ->nullable()
                ->storedAs("CASE WHEN state = 'published' THEN blueprint_id ELSE NULL END")
                ->after('draft_guard');
        });

        Schema::table('niche_blueprint_versions', function (Blueprint $table): void {
            $table->unique('draft_guard', 'nbv_draft_guard_unique');
            $table->unique('published_guard', 'nbv_published_guard_unique');
        });
    }

    public function down(): void
    {
        // Dropping the table removes the generated columns and their indexes
        // with it; they were added by this same migration's up().
        Schema::dropIfExists('niche_blueprint_versions');
    }
};
