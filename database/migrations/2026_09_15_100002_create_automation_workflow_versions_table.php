<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V2 slice V2-0 §4.2/§4.7 — drafts and immutable published
 * versions, plus the back-reference that closes the workflow↔version cycle.
 *
 * MIGRATION 2 OF 6. It does three things, in this order:
 *
 *   (a) create `automation_workflow_versions`, whose `workflow_id` references
 *       `automation_workflows` (migration 1);
 *   (b) add the two STORED generated guard columns and their UNIQUE indexes,
 *       which is how MySQL enforces "at most one draft" and "at most one
 *       published version" per workflow — MySQL has no partial unique index,
 *       so this mirrors the already-merged, already-tested pattern in
 *       2026_08_16_140001_create_payment_provider_customers_table.php and
 *       2026_09_12_100001_create_business_messaging_identities_table.php;
 *   (c) add `automation_workflows.published_version_id`'s index and foreign
 *       key, which could not exist until this table did.
 *
 * `down()` reverses (c) BEFORE dropping this table, or the drop would fail on
 * a live foreign key.
 *
 * THE BACK-REFERENCE IS COMPOSITE, ON PURPOSE (§4.1). A plain foreign key on
 * `published_version_id` alone proves only that the version row exists — it
 * would happily accept another workflow's version, or another Business's.
 * Referencing `(id, workflow_id)` means a workflow can only ever point at a
 * version OF ITSELF, enforced by MySQL rather than by the publisher behaving.
 * With `published_version_id` NULL the constraint is not evaluated (InnoDB
 * does not check a foreign key while any referencing column is NULL), which is
 * exactly the never-published state.
 *
 * BOTH SIDES OF THE CYCLE ARE RESTRICT (§4.7). The first contract revision
 * paired cascade on one side with set-null on the other; deleting a workflow
 * would then cascade into its versions, and each version's deletion would try
 * to set a column NULL on the very workflow row being deleted in the same
 * statement. MySQL documents that a cascade recursing back into a table it has
 * already modified behaves as RESTRICT for ON UPDATE; how InnoDB resolves this
 * particular two-table ON DELETE cycle is not proven anywhere in this
 * repository, and live customer workflows are not the place to rely on unproven
 * cascade behaviour. RESTRICT never recurses, and turns a wrong deletion order
 * into an immediate error instead of a silent partial cascade. Deletion is
 * therefore application-ordered, and a workflow that has ever been published is
 * archived rather than deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflow_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid');
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedInteger('version_number');

            // draft | published | superseded
            $table->string('state', 16)->default('draft');

            // The editor document (§5.3). THE RUNTIME NEVER READS THIS: once
            // published, execution walks the compiled node and edge rows only.
            $table->json('definition');

            // Optimistic-concurrency counter for autosave (§14.4). A stale
            // revision loses the write rather than clobbering another tab.
            $table->unsignedInteger('definition_revision')->default(1);

            $table->char('definition_hash', 64)->nullable();

            // Denormalised from the root trigger node at publish, so trigger
            // ingestion can find published versions with one indexed lookup.
            $table->string('trigger_type', 32)->nullable();
            $table->unsignedSmallInteger('node_count')->nullable();

            // Behavioural policies live HERE, not on the workflow (§4.1 C2),
            // and are pinned with the version: an enrollment reads the policies
            // of the version it started on, so republishing with a different
            // policy affects only new enrollments.
            $table->string('enrollment_policy', 32)->nullable();
            $table->string('enrollment_policy_source', 8)->nullable();
            $table->string('failure_policy', 16)->nullable();

            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('uid', 'awv_uid_unique');
            $table->unique(['workflow_id', 'version_number'], 'awv_workflow_version_unique');

            // Required by the composite back-reference added below: MySQL needs
            // the referenced columns indexed in the referencing order. Trivially
            // unique because `id` is the primary key.
            $table->unique(['id', 'workflow_id'], 'awv_id_workflow_unique');

            $table->foreign('workflow_id', 'awv_workflow_foreign')
                ->references('id')->on('automation_workflows')->restrictOnDelete();
            $table->foreign('business_id', 'awv_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('published_by_user_id', 'awv_published_by_user_foreign')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['business_id', 'trigger_type', 'state'], 'awv_business_trigger_state_index');
        });

        // (b) The two guard columns. A draft row yields its workflow_id in
        // draft_guard and NULL in published_guard, and vice versa; a superseded
        // row yields NULL in both, so any number of superseded versions coexist.
        Schema::table('automation_workflow_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('draft_guard')
                ->nullable()
                ->storedAs("CASE WHEN state = 'draft' THEN workflow_id ELSE NULL END")
                ->after('state');

            $table->unsignedBigInteger('published_guard')
                ->nullable()
                ->storedAs("CASE WHEN state = 'published' THEN workflow_id ELSE NULL END")
                ->after('draft_guard');
        });

        Schema::table('automation_workflow_versions', function (Blueprint $table): void {
            $table->unique('draft_guard', 'awv_draft_guard_unique');
            $table->unique('published_guard', 'awv_published_guard_unique');
        });

        // (c) The back-reference, now that the referenced table exists.
        Schema::table('automation_workflows', function (Blueprint $table): void {
            $table->index(['published_version_id', 'id'], 'aw_published_version_index');

            $table->foreign(['published_version_id', 'id'], 'aw_published_version_foreign')
                ->references(['id', 'workflow_id'])->on('automation_workflow_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Reverse (c) first. Dropping the versions table while this foreign key
        // is live would fail.
        Schema::table('automation_workflows', function (Blueprint $table): void {
            $table->dropForeign('aw_published_version_foreign');
            $table->dropIndex('aw_published_version_index');
        });

        Schema::dropIfExists('automation_workflow_versions');
    }
};
