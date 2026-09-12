<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V2 slice V2-0 §4.3 — the compiled graph's nodes.
 *
 * MIGRATION 3 OF 6.
 *
 * These rows are written ONCE, by the publisher, and never updated. That is
 * why there is no `updated_at`: an immutable row has no update time, and the
 * absence of the column makes an accidental `->update()` on a published
 * version fail loudly instead of silently rewriting history under a running
 * enrollment.
 *
 * `config` is JSON because each node type has its own shape, and it is
 * validated by the code-backed NodeTypeRegistry — the same arrangement B4
 * already uses for `automations.trigger_config`/`action_config`. The graph's
 * STRUCTURE is relational (this table plus `automation_workflow_edges`), so the
 * runtime never has to parse a document to know what to do next.
 *
 * `UNIQUE(id, version_id)` exists so sibling tables can reference a node
 * together with its version and have MySQL prove they agree — see
 * `automation_workflow_edges` and `automation_enrollments.current_node_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflow_nodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('business_id');

            // The editor's own stable key for this node, carried through
            // compilation so a validation error can point at the step the
            // customer is looking at, and so a re-publish keeps node identity.
            $table->string('node_key', 36);

            // App\Enums\Automation\Workflow\WorkflowNodeType
            $table->string('node_type', 32);

            $table->json('config');

            // If/Else nesting depth; the root trigger is 0. Bounded by
            // WorkflowLimits::MAX_BRANCH_DEPTH at compile time.
            $table->unsignedTinyInteger('depth')->default(0);

            // Created once. No updated_at: see the class docblock.
            $table->timestamp('created_at')->nullable();

            $table->unique(['version_id', 'node_key'], 'awn_version_node_key_unique');
            $table->unique(['id', 'version_id'], 'awn_id_version_unique');

            $table->foreign('version_id', 'awn_version_foreign')
                ->references('id')->on('automation_workflow_versions')->cascadeOnDelete();
            $table->foreign('business_id', 'awn_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();

            $table->index('version_id', 'awn_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_workflow_nodes');
    }
};
