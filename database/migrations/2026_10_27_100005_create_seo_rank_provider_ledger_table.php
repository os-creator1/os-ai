<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Keyword Rank Tracking V1 — INTERNAL platform provider-cost ledger. This
 * is what the platform pays the SERP vendor, NOT customer-billable usage, so it
 * is deliberately separate from the wallet/usage ledger. One row per check run
 * (unique), written in the same transaction that creates the run and BEFORE any
 * network call: the budget authority sums this table to decide whether another
 * paid task may be submitted. status: reserved -> committed (actual known) or
 * released (provider definitively never accepted it); `held` keeps counting.
 * Amounts are integer micro-USD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_rank_provider_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('provider', 16);
            $table->string('operation', 24);
            $table->foreignId('seo_rank_check_run_id')->unique()->constrained('seo_rank_check_runs')->restrictOnDelete();
            $table->char('usage_month', 7);
            $table->date('usage_day');
            $table->unsignedInteger('reserved_micros');
            $table->unsignedInteger('actual_micros')->nullable();
            $table->string('status', 12)->default('reserved');
            $table->timestamps();

            $table->index(['business_id', 'created_at'], 'seo_rank_ledger_business_index');
            $table->index(['workspace_id', 'usage_month'], 'seo_rank_ledger_workspace_month_index');
            $table->index(['usage_month'], 'seo_rank_ledger_month_index');
            $table->index(['usage_day'], 'seo_rank_ledger_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rank_provider_ledger');
    }
};
