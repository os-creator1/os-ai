<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outreach -> Unanswered questions: one row per prospect question that no approved answer
 * covered. Unique per (inbound message, normalised wording), so reprocessing the same
 * inbound never adds a second item. Resolving an item (answer added, alias linked,
 * dismissed) never sends anything to the prospect.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agency_prospect_unanswered_questions')) {
            return;
        }

        Schema::create('agency_prospect_unanswered_questions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('campaign_member_id')->nullable();
            $table->unsignedBigInteger('inbound_message_id');
            $table->string('question', 500);
            $table->char('question_hash', 64);
            $table->string('status', 16)->default('open');               // open | resolved | dismissed
            $table->string('resolution', 24)->nullable();                // answer_added | alias_linked | dismissed
            $table->string('resolved_faq_id', 64)->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('asked_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['inbound_message_id', 'question_hash'], 'apuq_inbound_question_unique');
            $table->index(['workspace_id', 'status', 'id'], 'apuq_workspace_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_prospect_unanswered_questions');
    }
};
