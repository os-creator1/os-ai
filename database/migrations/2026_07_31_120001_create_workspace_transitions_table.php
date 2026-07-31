<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Durable audit table for RFC-003 Milestone 2 (corrected M2-0 domain
     * contract §5/§7/§11) — limited to exactly two operations: Workspace
     * ownership transfer and cross-Workspace Business reassignment. The
     * other 12 domain events never write a row here; audit persistence and
     * event dispatch are decoupled concerns.
     *
     * actor_user_id/from_owner_user_id/to_owner_user_id are deliberately
     * immutable scalar IDs with no foreign key: unlike workspaces/
     * businesses (never hard-deleted per RFC-003 §17), users are not
     * governed by that rule, and this audit table must never block a
     * legitimate user-deletion feature elsewhere in the system.
     *
     * The (workspace_id, created_at) composite index serves both the
     * per-Workspace lookup pattern and InnoDB's FK-requires-a-leftmost-
     * index requirement for workspace_id — no separate bare workspace_id
     * index exists.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspace_transitions', function (Blueprint $table) {
                $table->id();

                $table->foreignId('workspace_id')
                    ->constrained('workspaces', 'id', 'workspace_transitions_workspace_id_foreign')
                    ->restrictOnDelete();

                $table->string('transition_type', 40);

                $table->unsignedBigInteger('actor_user_id');

                $table->unsignedBigInteger('from_owner_user_id')->nullable();
                $table->unsignedBigInteger('to_owner_user_id')->nullable();
                $table->string('previous_owner_disposition', 32)->nullable();

                $table->foreignId('business_id')
                    ->nullable()
                    ->constrained('businesses', 'id', 'workspace_transitions_business_id_foreign')
                    ->restrictOnDelete();

                $table->foreignId('from_workspace_id')
                    ->nullable()
                    ->constrained('workspaces', 'id', 'workspace_transitions_from_workspace_id_foreign')
                    ->restrictOnDelete();

                $table->timestamp('created_at');

                $table->index(['workspace_id', 'created_at'], 'workspace_transitions_workspace_id_created_at_index');
                $table->index('business_id', 'workspace_transitions_business_id_index');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspace_transitions');
        }
    };
