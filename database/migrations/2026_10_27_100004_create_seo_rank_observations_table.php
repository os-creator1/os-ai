<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Keyword Rank Tracking V1 — one normalized fact per COMPLETED check run:
 * what the provider found for one target and check type at one time. Absence is
 * explicit and never a number: `status` is found | not_found | not_matched and
 * `position` is NULL unless found (never 0, never 101). No SERP payload is kept
 * — only the matched URL/domain/path, the depth searched and how the match was
 * made. Unique per run, so reprocessing a result cannot duplicate history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_rank_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('seo_rank_target_id')->constrained('seo_rank_targets')->restrictOnDelete();
            $table->foreignId('seo_rank_check_run_id')->unique()->constrained('seo_rank_check_runs')->restrictOnDelete();
            $table->string('check_type', 8);
            $table->string('status', 16);
            $table->unsignedSmallInteger('position')->nullable();
            $table->string('result_url', 2048)->nullable();
            $table->string('result_domain', 255)->nullable();
            $table->string('result_path', 512)->nullable();
            $table->string('match_basis', 16)->nullable();
            $table->unsignedSmallInteger('depth_checked');
            $table->string('provider', 16);
            $table->unsignedBigInteger('search_location_code');
            $table->string('device', 8);
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['seo_rank_target_id', 'check_type', 'checked_at'], 'seo_rank_obs_history_index');
            $table->index(['checked_at'], 'seo_rank_obs_retention_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rank_observations');
    }
};
