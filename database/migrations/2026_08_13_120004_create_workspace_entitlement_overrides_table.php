<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-004 §10.5 — an explicit, audited, per-feature admin exception
     * (allow/deny) layered on top of a Workspace's plan-derived entitlement.
     * No "inherit" state is ever stored — absence of a row already means
     * inherit; reverting deletes the row.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspace_entitlement_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->string('feature_key', 64);
                $table->string('state', 10);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'feature_key']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspace_entitlement_overrides');
        }
    };
