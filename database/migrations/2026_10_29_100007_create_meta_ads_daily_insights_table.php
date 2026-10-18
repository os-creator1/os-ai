<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §5 — per-day delivery facts at `campaign`,
 * `ad_set` or `ad` level. Purpose: every spend / impression / click KPI,
 * chart, period total, pacing figure and CTR/CPC/CPM is computed from this
 * table, so changing a date range never calls Meta.
 *
 * entity_id is the LOCAL campaign / ad set / ad id selected by `level`. It is
 * deliberately not a FK (polymorphic by level) so a day's fact is not tied to
 * one parent table; the account FK still scopes it to the Business, and
 * entity rows are re-resolved inside the account by the readers. Account KPIs
 * sum level = campaign ONLY, never ad-set / ad rows (no double counting).
 * Unique (account, level, entity_id, metric_date) is the idempotent upsert
 * key. Reach and frequency are NOT stored per day (not additive).
 *
 * spend_micros is BIGINT micros in the ACCOUNT currency, parsed from Meta's
 * decimal string without floats. spend / impressions / clicks are NOT NULL
 * (a returned insights row always carries them). link_clicks is NULLABLE:
 * NULL means Meta returned no link-click figure, which is NOT 0.
 *
 * index (account, level, metric_date) serves the period-total reader;
 * the unique key serves per-entity series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_daily_insights', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('meta_ads_account_id');
            $table->string('level', 16);
            $table->unsignedBigInteger('entity_id');
            $table->date('metric_date');
            $table->unsignedBigInteger('spend_micros')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('link_clicks')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['meta_ads_account_id', 'business_id'], 'mads_ins_account_business_fk')
                ->references(['id', 'business_id'])->on('meta_ads_accounts')->cascadeOnDelete();

            $table->unique(['meta_ads_account_id', 'level', 'entity_id', 'metric_date'], 'mads_ins_natural_unique');
            $table->index(['meta_ads_account_id', 'business_id'], 'mads_ins_account_business_idx');
            $table->index(['meta_ads_account_id', 'level', 'metric_date'], 'mads_ins_account_level_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_daily_insights');
    }
};
