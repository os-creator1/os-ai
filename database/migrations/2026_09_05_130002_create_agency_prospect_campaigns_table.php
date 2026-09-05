<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Agency AI Prospecting foundation — a Workspace-scoped organizational
     * bucket for enrolled prospects. `status` is a plain organizational
     * label in this foundation pass (draft/active/paused) — no automatic
     * sending is wired to it yet (the Workspace-level provider/sending
     * seam and the AI responder engine are both deferred; see the
     * foundation pass report). `context` is free text for this campaign's
     * own configurable framing, distinct from the Workspace-wide agent
     * settings in agency_prospecting_settings.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('agency_prospect_campaigns', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->string('name');
                $table->string('status', 32)->default('draft');
                $table->text('context')->nullable();
                $table->timestamps();

                $table->index('workspace_id');
                $table->index(['workspace_id', 'status']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('agency_prospect_campaigns');
        }
    };
