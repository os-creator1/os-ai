<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §5 — normalised ad sets. Purpose: the
 * pause/resume target between a campaign and its ads, the owner of the
 * trailing-7-day reach / frequency figures and the delivery-issue fact.
 *
 * Unique (account, external_ad_set_id) is the idempotent upsert key. The
 * composite FK (meta_ads_campaign_id, meta_ads_account_id) guarantees the
 * campaign belongs to the SAME account.
 *
 * reach_7d / frequency_7d / frequency_window_end are the ad set's OWN
 * trailing-7-day figures from one non-daily insights call. Reach and
 * frequency are not additive, so they are never stored per day and never
 * summed; NULL = not fetched / not available. targeting_summary is a
 * server-built string (<= 255), never raw targeting JSON. Budgets are minor
 * units, NULL when Meta did not return them.
 *
 * unique (id, meta_ads_account_id) exists solely so ads can composite-FK to
 * an ad set of the same account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_ad_sets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('meta_ads_account_id');
            $table->unsignedBigInteger('meta_ads_campaign_id');
            $table->string('external_ad_set_id', 32);
            $table->string('name', 255);
            $table->string('status', 16);
            $table->string('effective_status', 40)->nullable();
            $table->unsignedBigInteger('daily_budget_minor')->nullable();
            $table->unsignedBigInteger('lifetime_budget_minor')->nullable();
            $table->string('optimization_goal', 64)->nullable();
            $table->string('bid_strategy', 64)->nullable();
            $table->string('targeting_summary', 255)->nullable();
            $table->unsignedBigInteger('reach_7d')->nullable();
            $table->decimal('frequency_7d', 10, 4)->nullable();
            $table->date('frequency_window_end')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['meta_ads_account_id', 'business_id'], 'mads_adset_account_business_fk')
                ->references(['id', 'business_id'])->on('meta_ads_accounts')->cascadeOnDelete();
            $table->foreign(['meta_ads_campaign_id', 'meta_ads_account_id'], 'mads_adset_campaign_account_fk')
                ->references(['id', 'meta_ads_account_id'])->on('meta_ads_campaigns')->cascadeOnDelete();

            $table->unique(['meta_ads_account_id', 'external_ad_set_id'], 'mads_adset_account_external_unique');
            $table->unique(['id', 'meta_ads_account_id'], 'mads_adset_id_account_unique');
            $table->index(['meta_ads_account_id', 'business_id'], 'mads_adset_account_business_idx');
            $table->index(['meta_ads_campaign_id', 'meta_ads_account_id'], 'mads_adset_campaign_account_idx');
            $table->index(['meta_ads_account_id', 'status'], 'mads_adset_account_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_ad_sets');
    }
};
