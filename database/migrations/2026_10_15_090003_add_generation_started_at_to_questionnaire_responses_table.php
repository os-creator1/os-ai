<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round 2 — a durable "generation in
 * progress" marker, checked AFTER acquiring the per-response generation
 * lock (WebsiteWizardController::generate()) so a second request that
 * resolved its response before the first one finished cannot re-enter
 * and re-run reconciliation, and so an autosave arriving mid-generation
 * (WebsiteSetupSessionManager::saveAnswer()/updateAnswerInPlace()) is
 * refused rather than silently mutating answers an in-flight AI call is
 * already reading. Cleared on both success (sessionManager::complete()/
 * completeEdit()) and failure (the response returns to a genuinely
 * retryable in_progress state) — it is never left set once generate()
 * returns, by any path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->timestamp('generation_started_at')->nullable()->after('edit_mode');
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->dropColumn('generation_started_at');
        });
    }
};
