<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §6 — one row per sync attempt. Purpose: the
 * freshness and failure story the pages show ("Updated ...", "Data through
 * ...", partial / row-cap / usage-high warnings) and the manual-refresh and
 * stale-claim decisions; the ledger operation (meta_ads_sync) carries the
 * call counts, linked here by business_meta_operation_id.
 *
 * failure_code is a SAFE code only (closed vocabulary such as rate_limited,
 * row_cap, usage_high). No provider payload, error text or token is ever
 * stored. data_through_date is NULL unless the run actually obtained data.
 * `trigger` is quoted by the schema grammar; the name is the contract's.
 * index (account, state) serves "is a sync live for this account".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('meta_ads_account_id');
            $table->string('state', 16);
            $table->string('trigger', 16);
            $table->string('scope', 32)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('data_through_date')->nullable();
            $table->unsignedInteger('rows_counted')->default(0);
            $table->string('failure_code', 32)->nullable();
            $table->unsignedBigInteger('business_meta_operation_id')->nullable();
            $table->timestamps();

            $table->foreign(['meta_ads_account_id', 'business_id'], 'mads_run_account_business_fk')
                ->references(['id', 'business_id'])->on('meta_ads_accounts')->cascadeOnDelete();
            $table->foreign('business_meta_operation_id', 'mads_run_operation_fk')
                ->references('id')->on('business_meta_operations')->nullOnDelete();

            $table->index(['meta_ads_account_id', 'business_id'], 'mads_run_account_business_idx');
            $table->index(['meta_ads_account_id', 'created_at'], 'mads_run_account_created_idx');
            $table->index(['meta_ads_account_id', 'state'], 'mads_run_account_state_idx');
            $table->index(['state', 'created_at'], 'mads_run_state_created_idx');
            $table->index('business_meta_operation_id', 'mads_run_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_sync_runs');
    }
};
