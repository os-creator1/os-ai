<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §5.2 — per-day TYPED results. Purpose:
 * Meta's `actions` / `action_values` are typed arrays and there is no
 * universal "conversions" number, so each stored row is one
 * (entity, day, action_type) result. Cost per result is computed for the
 * owner-chosen type (meta_ads_accounts.result_action_type) by reading only
 * that type's rows; switching the type re-reads stored rows and never
 * reinterprets another type.
 *
 * Only action types present in config('meta_ads.result_types') are written
 * by the sync. A row exists only when Meta reported that type for that
 * entity-day, so "no row" = unavailable, never 0. entity_id is the LOCAL
 * campaign / ad set / ad id selected by `level` (not a FK, polymorphic by
 * level, same as meta_ads_daily_insights). Account results sum level =
 * campaign ONLY.
 *
 * results is DECIMAL(20,6) NOT NULL (Meta returns counts as strings).
 * result_value is NULLABLE DECIMAL(20,6): Meta-reported action value in the
 * account currency, NULL when none (NULL is not 0); it is shown only as
 * "Meta-reported value", never as CRM revenue.
 *
 * Unique (account, level, entity_id, metric_date, action_type) is the
 * idempotent upsert key. index (account, level, action_type, metric_date)
 * serves the period-total reader for the chosen type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_daily_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('meta_ads_account_id');
            $table->string('level', 16);
            $table->unsignedBigInteger('entity_id');
            $table->date('metric_date');
            $table->string('action_type', 64);
            $table->decimal('results', 20, 6)->default(0);
            $table->decimal('result_value', 20, 6)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['meta_ads_account_id', 'business_id'], 'mads_res_account_business_fk')
                ->references(['id', 'business_id'])->on('meta_ads_accounts')->cascadeOnDelete();

            $table->unique(['meta_ads_account_id', 'level', 'entity_id', 'metric_date', 'action_type'], 'mads_res_natural_unique');
            $table->index(['meta_ads_account_id', 'business_id'], 'mads_res_account_business_idx');
            $table->index(['meta_ads_account_id', 'level', 'action_type', 'metric_date'], 'mads_res_account_type_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_daily_results');
    }
};
