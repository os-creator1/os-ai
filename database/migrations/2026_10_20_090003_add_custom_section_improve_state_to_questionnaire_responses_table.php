<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round 3 — "Improve with AI" previously
 * deduplicated a submission with a Cache lock plus a 60-second Cache
 * marker: neither is durable (both can be evicted or simply differ
 * between two application servers behind a load balancer), a lock
 * contention returned a raw LockTimeoutException/HTTP 500 instead of a
 * friendly result, and nothing prevented an AI response for an older
 * submission from overwriting text the owner had already changed again
 * while that call was in flight.
 *
 * This durable state — one active/most-recent attempt per response, not
 * a full history table (WebsiteGuidedGenerationAttempt's own multi-row
 * table is unnecessary here: at most one Improve call is ever
 * legitimately in flight for one response at a time) — replaces the
 * cache-only marker:
 *  - `custom_section_improve_key`: a stable hash of the response, the
 *    answers_revision this attempt started from, and the exact submitted
 *    title/body/layout — a changed title with the same body is always a
 *    DIFFERENT key.
 *  - `custom_section_improve_status`: pending while the AI call is in
 *    flight, succeeded/failed once settled.
 *  - `custom_section_improve_started_revision`: the answers_revision at
 *    the moment this attempt began — compared against the CURRENT
 *    revision when the AI result comes back, so a newer edit that
 *    happened while the call was in flight is never silently clobbered
 *    (compare-and-swap).
 *  - `custom_section_improve_result`: the improved body once succeeded,
 *    so a duplicate/late request for the SAME key can return the real
 *    result without spending AI again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->string('custom_section_improve_key', 120)->nullable()->after('generation_started_at');
            $table->string('custom_section_improve_status', 20)->nullable()->after('custom_section_improve_key');
            $table->unsignedInteger('custom_section_improve_started_revision')->nullable()->after('custom_section_improve_status');
            $table->json('custom_section_improve_result')->nullable()->after('custom_section_improve_started_revision');
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->dropColumn([
                'custom_section_improve_key',
                'custom_section_improve_status',
                'custom_section_improve_started_revision',
                'custom_section_improve_result',
            ]);
        });
    }
};
