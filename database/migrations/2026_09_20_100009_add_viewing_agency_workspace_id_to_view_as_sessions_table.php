<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1 Implementation Contract 04 §5 — cross-Workspace Agency View As.
 *
 * view_as_sessions.workspace_id has always meant "the Workspace being
 * viewed". In the old same-Workspace model that was also the Workspace whose
 * membership authorized the actor; in the V1 Agency model the two are
 * different Workspaces. That conflation is resolved by this new column, never
 * by redefining workspace_id:
 *
 *   viewing_agency_workspace_id NULL      a same-Workspace session (every row
 *                                         that exists today — no backfill);
 *   viewing_agency_workspace_id = Agency  a cross-Workspace session, authorized
 *                                         through the Contract 01 relationship
 *                                         from that Agency Workspace.
 *
 * restrictOnDelete, deliberately unlike the table's other two cascading FKs:
 * an Agency Workspace being deleted must never silently cascade-delete the
 * audit history of who viewed its clients. Workspaces are never hard-deleted
 * (RFC-003 §17), so the restriction costs nothing in practice.
 *
 * Additive and reversible: one nullable column plus its FK; down() removes
 * exactly those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('view_as_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('viewing_agency_workspace_id')->nullable();

            $table->foreign('viewing_agency_workspace_id', 'view_as_sessions_viewing_agency_workspace_id_foreign')
                ->references('id')
                ->on('workspaces')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('view_as_sessions', function (Blueprint $table): void {
            $table->dropForeign('view_as_sessions_viewing_agency_workspace_id_foreign');
            $table->dropColumn('viewing_agency_workspace_id');
        });
    }
};
