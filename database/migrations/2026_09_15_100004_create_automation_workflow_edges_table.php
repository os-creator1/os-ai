<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V2 slice V2-0 §4.4 — the compiled graph's edges, and the two
 * indexes that make "a workflow is a tree" a database fact rather than a
 * convention.
 *
 * MIGRATION 4 OF 6. Written once by the publisher, never updated.
 *
 *   UNIQUE(from_node_id, edge_kind)
 *     A node has at most one outgoing edge of each kind, so a step cannot have
 *     two "next" steps and an If/Else cannot have two "yes" lanes.
 *
 *   UNIQUE(to_node_id)
 *     Every node has at most one parent. In-degree ≤ 1 is what forbids a
 *     MERGE: two lanes can never rejoin. Together with the compiler's
 *     reachability proof (nodes = edges + 1, all reachable from the root) this
 *     makes a cycle impossible, which is why initial v2 needs no loop detection
 *     at runtime and no execution-depth guard inside a single workflow.
 *
 * A future "go to step" or branch-merge feature would drop UNIQUE(to_node_id)
 * in its own contract. It is named here so nobody mistakes it for an accident.
 *
 * The node references are COMPOSITE — `(from_node_id, version_id)` and
 * `(to_node_id, version_id)` against `automation_workflow_nodes (id,
 * version_id)` — so MySQL proves an edge can only ever join two nodes OF THE
 * SAME VERSION. A plain single-column reference would have allowed an edge from
 * version 3 to a node in version 4, which is exactly the kind of
 * cross-version reference this slice is required to make impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflow_edges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('from_node_id');
            $table->unsignedBigInteger('to_node_id');

            // next | yes | no — App\Enums\Automation\Workflow\WorkflowEdgeKind
            $table->string('edge_kind', 8);

            $table->timestamp('created_at')->nullable();

            $table->unique(['from_node_id', 'edge_kind'], 'awe_from_node_kind_unique');
            $table->unique('to_node_id', 'awe_to_node_unique');

            $table->foreign('version_id', 'awe_version_foreign')
                ->references('id')->on('automation_workflow_versions')->cascadeOnDelete();
            $table->foreign('business_id', 'awe_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();

            // Composite: an edge's endpoints must belong to the edge's version.
            $table->foreign(['from_node_id', 'version_id'], 'awe_from_node_foreign')
                ->references(['id', 'version_id'])->on('automation_workflow_nodes')->cascadeOnDelete();
            $table->foreign(['to_node_id', 'version_id'], 'awe_to_node_foreign')
                ->references(['id', 'version_id'])->on('automation_workflow_nodes')->cascadeOnDelete();

            $table->index('version_id', 'awe_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_workflow_edges');
    }
};
