<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Agency AI Prospecting foundation — an external business the Agency
     * Workspace itself is trying to acquire as a client. Deliberately a
     * separate table from Business/Contacts/CRM: a prospect must never be
     * confused with, or silently promoted into, a Business-scoped CRM
     * Contact or Outreach ContactGroup member. `status` is this prospect's
     * single, Workspace-scoped suppression flag — since every row here
     * already belongs to exactly one Workspace, an opt-out recorded here
     * can never leak into another Workspace's prospecting or into any
     * Business's own Blacklist (a completely separate table this schema
     * never touches). No automatic transition to `stopped`/`booked` is
     * wired in this foundation pass — both are set only by an explicit,
     * human-initiated action.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('agency_prospects', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->string('company_name');
                $table->string('contact_name')->nullable();
                $table->string('phone', 32);
                $table->string('email')->nullable();
                $table->string('website', 2048)->nullable();
                $table->string('source')->nullable();
                $table->string('location')->nullable();
                $table->string('status', 32)->default('active');
                $table->timestamp('stopped_at')->nullable();
                $table->timestamp('booked_at')->nullable();
                $table->timestamps();

                $table->index('workspace_id');
                $table->index(['workspace_id', 'phone']);
                $table->index(['workspace_id', 'status']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('agency_prospects');
        }
    };
