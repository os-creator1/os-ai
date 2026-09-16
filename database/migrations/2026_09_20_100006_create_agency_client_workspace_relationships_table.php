<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1 Architecture Decision Addendum §2 / Implementation Contract 01 §5 — the
 * canonical, explicit Agency -> Client Workspace management relationship.
 *
 * Purely additive: no existing table is touched, and nothing reads these rows
 * for authorization yet (Contract 04 is the first consumer). The table starts
 * empty; Contract 10 is the first real writer of historical data.
 *
 * WHY THE GENERATED COLUMN. Addendum §2 allows a Client Workspace 0 or 1
 * ACTIVE managing Agency, while requiring that terminated relationships stay
 * queryable forever — so the uniqueness is conditional, and MySQL 8 has no
 * partial unique index to express it. `active_client_workspace_id` is
 * `client_workspace_id` only while the row is active and NULL once it is
 * terminated; MySQL's unique index ignores NULLs, so the database itself
 * guarantees at most one active managing Agency per Client Workspace while
 * placing no limit at all on terminated history rows for that same client.
 * The application layer (AgencyClientRelationshipManager, under row locks)
 * is what normally prevents a duplicate; this index is the hard backstop
 * that cannot be bypassed by any future writer.
 *
 * established_by_user_id/terminated_by_user_id are deliberately immutable
 * scalar IDs with no foreign key, exactly as workspace_transitions.actor_user_id
 * already is: this audit record must never block a legitimate user-deletion
 * feature elsewhere in the system. Both Workspace sides DO carry a foreign
 * key and restrictOnDelete(), matching workspace_transitions — Workspaces are
 * never hard-deleted (RFC-003 §17), so the constraint costs nothing and keeps
 * the relationship from ever dangling.
 *
 * Index shapes follow workspace_transitions' own no-redundant-index
 * discipline: the composite (client_workspace_id, status) also satisfies
 * InnoDB's FK-requires-a-leftmost-index rule for client_workspace_id, so no
 * bare index on that column is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_client_workspace_relationships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->unsignedBigInteger('agency_workspace_id');
            $table->unsignedBigInteger('client_workspace_id');

            $table->string('status', 16);

            $table->unsignedBigInteger('established_by_user_id');
            $table->timestamp('established_at');

            $table->unsignedBigInteger('terminated_by_user_id')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();

            $table->timestamps();

            // Written by MySQL, never by the application — it carries no
            // information of its own, only the conditional uniqueness above.
            $table->unsignedBigInteger('active_client_workspace_id')
                ->storedAs("case when `status` = 'active' then `client_workspace_id` end");

            $table->index('agency_workspace_id', 'agency_client_relationships_agency_index');
            $table->index(['client_workspace_id', 'status'], 'agency_client_relationships_client_status_index');

            $table->unique('active_client_workspace_id', 'agency_client_relationships_active_client_unique');

            $table->foreign('agency_workspace_id', 'agency_client_relationships_agency_workspace_id_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();

            $table->foreign('client_workspace_id', 'agency_client_relationships_client_workspace_id_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_client_workspace_relationships');
    }
};
