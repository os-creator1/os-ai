<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Opportunities — what happened to a deal, in order. Append-only.
 *
 * One row per change: created, moved to another stage, won, lost, reopened, or
 * its contact status corrected. Stage names are copied at the time of the
 * change, so "moved from Quote sent to Negotiating" stays true after either
 * stage is renamed. The stage ids stay too (nulled only if the Business itself
 * is deleted), so the history still joins to the live stage.
 *
 * A row's id is also the stable identity of that occurrence: the
 * `opportunity_stage_changed`, `opportunity_won` and `opportunity_lost` events
 * build their occurrence keys from it, so a replayed event can never enroll the
 * same deal twice for the same real-world change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_opportunity_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('crm_opportunities')->cascadeOnDelete();
            $table->string('event', 32);
            $table->foreignId('from_stage_id')->nullable()->constrained('crm_pipeline_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('crm_pipeline_stages')->nullOnDelete();
            $table->string('from_stage_name', 100)->nullable();
            $table->string('to_stage_name', 100)->nullable();
            $table->string('from_value', 32)->nullable();
            $table->string('to_value', 32)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['opportunity_id', 'id'], 'crm_opportunity_history_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunity_history');
    }
};
