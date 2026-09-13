<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Business Home and COO Decision Engine contract §9.1 (slice AI-3) —
 * the cached, validated output of a COO insight.
 *
 * One row is one paid answer about one Business's facts. It is written only by
 * the queued GenerateCooInsight job, read by the Business Home as at most one
 * row (§16), and never deleted because the facts moved: invalidation is soft
 * (§9.3), so the audit trail of what was said, on which facts, at what cost,
 * survives.
 *
 * The unique identity is the contract's own: identical bucketed facts for the
 * same subject and prompt are never paid for twice.
 *
 * Two columns beyond §9.1's table, both needed to honour its own rules:
 *  - `policy_version` — §9.3 requires rows from an older policy version to be
 *    "never selected". The version is part of the fingerprint, but the Home
 *    read must be able to exclude old rows without recomputing one, so it is a
 *    real column on the selection path.
 *  - `period_key` — an insight explains one Business performance window
 *    (§2.5, E-4 "one period's movement"). Home shows it only for the window it
 *    describes, never under a different one. Same key vocabulary as the B5
 *    caches (AnalyticsDateRange::cacheKey()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coo_insights', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('subject_type', 24)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('period_key', 64);
            $table->char('signal_fingerprint', 64);
            $table->json('facts_snapshot');
            $table->json('output');
            $table->unsignedSmallInteger('prompt_version');
            $table->unsignedSmallInteger('policy_version');
            $table->string('model_route', 16);
            $table->string('provider_model', 64)->nullable();
            $table->foreignId('ai_usage_ledger_entry_id')->nullable()->constrained('ai_usage_ledger')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('expires_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason', 32)->nullable();
            $table->timestamps();

            $table->unique(
                ['business_id', 'kind', 'subject_type', 'subject_id', 'signal_fingerprint', 'prompt_version'],
                'coo_insights_identity_unique',
            );

            // The Home read: this Business, this kind and window, newest first.
            $table->index(['business_id', 'kind', 'period_key', 'generated_at'], 'coo_insights_display_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coo_insights');
    }
};
