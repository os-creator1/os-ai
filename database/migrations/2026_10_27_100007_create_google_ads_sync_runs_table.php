<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 contract 23 §4 / §5 — one row per sync attempt.
 * Purpose: the freshness and failure story the pages show ("Updated …",
 * "Data through …", partial / row-cap warnings) and the manual-refresh /
 * breaker decisions; the ledger operation row (ads_sync) carries the call
 * counts, linked here by business_google_operation_id.
 *
 * failure_code is a SAFE code only (closed vocabulary such as rate_limited or
 * row_cap). No provider payload, error text or token is ever stored.
 * data_through_date is NULL unless the run actually obtained data.
 * `trigger` is quoted by the schema grammar; the name is the contract's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('google_ads_account_id');
            $table->string('state', 16);
            $table->string('trigger', 16);
            $table->string('scope', 32)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('data_through_date')->nullable();
            $table->unsignedInteger('rows_counted')->default(0);
            $table->string('failure_code', 32)->nullable();
            $table->unsignedBigInteger('business_google_operation_id')->nullable();
            $table->timestamps();

            $table->foreign(['google_ads_account_id', 'business_id'], 'gads_run_account_business_fk')
                ->references(['id', 'business_id'])->on('google_ads_accounts')->cascadeOnDelete();
            $table->foreign('business_google_operation_id', 'gads_run_operation_fk')
                ->references('id')->on('business_google_operations')->nullOnDelete();

            $table->index(['google_ads_account_id', 'business_id'], 'gads_run_account_business_idx');
            $table->index(['google_ads_account_id', 'created_at'], 'gads_run_account_created_idx');
            $table->index(['state', 'created_at'], 'gads_run_state_created_idx');
            $table->index('business_google_operation_id', 'gads_run_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_sync_runs');
    }
};
