<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Growth Center lane — one row per Business per day per score-algorithm
 * version: the HISTORY of the Growth Score, and nothing else.
 *
 * WHY A TABLE. Opportunities are current-state rows (a resolved problem
 * reads "resolved", not "was there on Tuesday"), so "your score moved from 71
 * to 78 and here is why" has no other home. The score is a deterministic
 * function of rule outcomes, so what is stored is exactly enough to explain a
 * movement later without recomputing anything:
 *
 *   category_scores  {category: integer | null}   null = not scored (no data)
 *   breakdown        per category: score, the scored rules and each rule's
 *                    status / weight / health — the "why this score" table
 *   positives        a bounded list of "what's working" statements' facts
 *   metrics          the period-over-period figures the change digest reads
 *
 * It holds NO Opportunity truth (counts of open problems, evidence, copy):
 * those are read live from `opportunities`. It is Business-wide only; a
 * Location-restricted actor never sees a score (see GrowthScoreAccess).
 *
 * VERSIONING. `algorithm_version` is part of the unique key and is never
 * rewritten: a formula change writes new-version rows beside the old ones and
 * old rows are never recomputed, so history cannot silently change meaning.
 *
 * RETENTION. Rows are kept growth.score.retention_days (>= 13 months) and
 * pruned by growth:evaluate's housekeeping; `created_at` is not the pruning
 * key, `snapshot_date` is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_score_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedTinyInteger('algorithm_version');
            $table->unsignedTinyInteger('overall_score')->nullable();
            $table->unsignedTinyInteger('scored_category_count')->default(0);
            $table->unsignedTinyInteger('total_category_count')->default(9);
            $table->unsignedSmallInteger('applicable_rule_count')->default(0);
            $table->json('category_scores');
            $table->json('breakdown');
            $table->json('positives')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['business_id', 'snapshot_date', 'algorithm_version'], 'growth_score_snapshots_identity_unique');
            $table->index(['business_id', 'snapshot_date'], 'growth_score_snapshots_business_date_index');
            $table->index('snapshot_date', 'growth_score_snapshots_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_score_snapshots');
    }
};
