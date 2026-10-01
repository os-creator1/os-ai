<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round 4 (item 1) — replaces the round-3
 * `questionnaire_responses.generation_started_at` freeze (a per-RESPONSE
 * flag) with a single durable per-WEBSITE lease: the real race this round
 * corrects spans wizard generation, Studio's own "Regenerate"/"Rebuild"
 * actions, and every setup mutation (answers, gallery, custom-section,
 * template), none of which share one row today. A Website, not a
 * QuestionnaireResponse, is the one row every one of those paths already
 * has in common.
 *
 * `generation_lease_token` is the fencing value: every writer that opens,
 * completes, fails, or commits a generation attempt compares its own copy
 * against the Website's CURRENT value before acting, so a worker whose
 * lease was already reclaimed as stale can never clear a newer lease or
 * commit pages out from under it (compare-and-swap, never a blind
 * timestamp check). `generation_lease_attempt_uid` names the EXACT
 * WebsiteGuidedGenerationAttempt this lease owns, so stale-lease recovery
 * only ever touches that one attempt, never every pending attempt for the
 * Website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->string('generation_lease_token', 36)->nullable()->after('presentation_changes_pending_at');
            $table->timestamp('generation_lease_started_at')->nullable()->after('generation_lease_token');
            $table->string('generation_lease_attempt_uid', 36)->nullable()->after('generation_lease_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn(['generation_lease_token', 'generation_lease_started_at', 'generation_lease_attempt_uid']);
        });
    }
};
