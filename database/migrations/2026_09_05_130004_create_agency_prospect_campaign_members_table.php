<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Agency AI Prospecting foundation — explicit prospect-to-campaign
     * enrollment. This is the ONLY path that associates a prospect with a
     * campaign; nothing enrolls a prospect implicitly. `workspace_id` is
     * carried directly on this table (not merely derived through
     * campaign_id/prospect_id) so every tenant-owned table in this schema
     * is independently workspace-scoped, matching every other table here.
     * `stage` is a typed, validated foundation for the later AI responder
     * engine (see App\Enums\AgencyProspecting\AgencyProspectStage) — no
     * automatic transition between stages is wired in this pass; only
     * enrollment sets the initial value.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('agency_prospect_campaign_members', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->foreignId('campaign_id')->constrained('agency_prospect_campaigns')->cascadeOnDelete();
                $table->foreignId('prospect_id')->constrained('agency_prospects')->cascadeOnDelete();
                $table->unsignedTinyInteger('stage')->default(1);
                $table->timestamp('enrolled_at');
                $table->timestamps();

                $table->unique(['campaign_id', 'prospect_id']);
                $table->index('workspace_id');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('agency_prospect_campaign_members');
        }
    };
