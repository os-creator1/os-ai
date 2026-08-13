<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-004 §10.4/§21 — durable audit table for commercially significant
     * entitlement mutations only (nine transition types, §11). created_at
     * only — no updated_at, immutable row, matching workspace_transitions
     * (RFC-003) exactly.
     *
     * actor_user_id/created_by-style identity columns are deliberately
     * plain scalars with no foreign key — must never block a legitimate
     * user-deletion feature elsewhere in the system.
     *
     * The (workspace_id, created_at) composite index serves both the
     * per-Workspace lookup pattern and InnoDB's FK-requires-a-leftmost-
     * index requirement for workspace_id — no separate bare workspace_id
     * index exists. from_plan_catalog_id/to_plan_catalog_id each receive
     * InnoDB's automatic implicit index for their own FK constraint.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspace_entitlement_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->string('transition_type', 48);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->foreignId('from_plan_catalog_id')->nullable()->constrained('workspace_plan_catalog')->restrictOnDelete();
                $table->foreignId('to_plan_catalog_id')->nullable()->constrained('workspace_plan_catalog')->restrictOnDelete();
                $table->string('feature_key', 64)->nullable();
                $table->string('from_override_state', 10)->nullable();
                $table->string('to_override_state', 10)->nullable();
                $table->unsignedTinyInteger('from_additional_business_slots')->nullable();
                $table->unsignedTinyInteger('to_additional_business_slots')->nullable();
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20)->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('created_at');

                $table->index(['workspace_id', 'created_at']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspace_entitlement_transitions');
        }
    };
