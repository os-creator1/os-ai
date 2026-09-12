<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V2 slice V2-0 §4.6 — one row per node executed, and the index
 * that carries the at-most-once guarantee.
 *
 * MIGRATION 6 OF 6.
 *
 *   UNIQUE(enrollment_id, node_id)
 *
 * Because the graph is a tree with no loops and no merges, a node is visited at
 * most once per enrollment. So this single index is simultaneously the step's
 * idempotency key AND the database-enforced at-most-once rule inherited from B4
 * §5.1: the row is inserted, already `started`, inside the same short
 * transaction that verified the enrollment's cursor under a row lock, and
 * BEFORE any provider call. A duplicated job loses the insert and returns
 * without doing anything.
 *
 * Only bounded, human-safe summaries are stored (B4 §4.3): never a provider
 * response body, never a credential, never the full outbound message text.
 *
 * Rollback drops the table, which is destructive of v2 run history by
 * definition — this table is the only record that a step ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_step_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('node_id');

            // Denormalised so the execution log renders without loading the
            // version's graph, and still reads correctly for a superseded
            // version.
            $table->string('node_type', 32);

            // started | waiting | succeeded | failed | skipped
            $table->string('status', 16)->default('started');

            // yes | no, for an If/Else step only.
            $table->string('branch_taken', 8)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('safe_result_summary', 255)->nullable();
            $table->string('safe_error_summary', 255)->nullable();
            $table->timestamps();

            $table->unique('uid', 'asr_uid_unique');
            $table->unique(['enrollment_id', 'node_id'], 'asr_enrollment_node_unique');

            $table->foreign('business_id', 'asr_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('enrollment_id', 'asr_enrollment_foreign')
                ->references('id')->on('automation_enrollments')->cascadeOnDelete();
            $table->foreign('node_id', 'asr_node_foreign')
                ->references('id')->on('automation_workflow_nodes')->restrictOnDelete();

            $table->index(['enrollment_id', 'created_at'], 'asr_enrollment_created_index');
            $table->index(['business_id', 'created_at'], 'asr_business_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_step_runs');
    }
};
