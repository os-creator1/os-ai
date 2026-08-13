<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-004 §10.3/§8 — at most one assignment per Workspace (unique
     * workspace_id). status has no default: every assignment-creation path
     * must supply it explicitly. complimentary_granted_by_user_id is a
     * plain scalar with no foreign key, mirroring
     * workspace_transitions.actor_user_id (RFC-003) — must never block a
     * legitimate user-deletion feature.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspace_plan_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->foreignId('workspace_plan_catalog_id')->constrained('workspace_plan_catalog')->restrictOnDelete();
                $table->string('status', 20);
                $table->boolean('is_complimentary')->default(false);
                $table->text('complimentary_reason')->nullable();
                $table->unsignedBigInteger('complimentary_granted_by_user_id')->nullable();
                $table->timestamp('complimentary_granted_at')->nullable();
                $table->unsignedTinyInteger('additional_business_slots')->default(0);
                $table->timestamps();

                $table->unique('workspace_id');
                $table->index('workspace_plan_catalog_id');
                $table->index('status');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspace_plan_assignments');
        }
    };
