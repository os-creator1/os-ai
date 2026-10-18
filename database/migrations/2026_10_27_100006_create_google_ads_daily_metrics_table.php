<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 contract 23 §4 / §9 — per-day performance facts at
 * `campaign` or `keyword` level. Purpose: every KPI, chart, period total,
 * pacing and CPL figure is computed from this table, so changing a date
 * range never calls Google.
 *
 * entity_key is the campaign's external id (level = campaign) or the keyword
 * criterion's resource-name tail (level = keyword); it is deliberately not a
 * FK so a day's fact survives a sync that has not yet seen the entity.
 * Account KPIs sum level = campaign ONLY, never keyword rows (no double
 * counting). Unique (account, level, entity_key, metric_date) is the
 * idempotent upsert key.
 *
 * Money is BIGINT micros in the ACCOUNT currency. impressions / clicks /
 * interactions / cost_micros are NOT NULL (a returned row always carries
 * them). conversions and conversions_value are NULLABLE DECIMAL(20,6): NULL
 * means Google returned no conversion data, which is NOT 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('google_ads_account_id');
            $table->string('level', 16);
            $table->string('entity_key', 64);
            $table->date('metric_date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('interactions')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->decimal('conversions', 20, 6)->nullable();
            $table->decimal('conversions_value', 20, 6)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['google_ads_account_id', 'business_id'], 'gads_dm_account_business_fk')
                ->references(['id', 'business_id'])->on('google_ads_accounts')->cascadeOnDelete();

            $table->unique(['google_ads_account_id', 'level', 'entity_key', 'metric_date'], 'gads_dm_natural_unique');
            $table->index(['google_ads_account_id', 'business_id'], 'gads_dm_account_business_idx');
            $table->index(['google_ads_account_id', 'level', 'metric_date'], 'gads_dm_account_level_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_daily_metrics');
    }
};
