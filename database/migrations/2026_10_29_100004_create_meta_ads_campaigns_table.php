<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §5 — normalised campaigns with the budget
 * facts read alongside. Purpose: the local, queryable copy the Campaigns /
 * Overview pages and every pause/resume target resolve against, so no page
 * calls Meta.
 *
 * Unique (account, external_campaign_id) is the natural key every sync
 * upserts on, which makes re-running a window idempotent.
 * status is Meta's configured status (ACTIVE / PAUSED / DELETED / ARCHIVED)
 * and is what a mutation changes; effective_status is the provider-reported
 * delivery status (e.g. WITH_ISSUES) quoted as a fact. Budgets are integer
 * MINOR units of the account currency, NULL when Meta did not return them
 * (daily and lifetime budgets are mutually exclusive on Meta; absence is not
 * zero). start_time / stop_time are DATETIME (not TIMESTAMP) because Meta
 * allows far-future stop times.
 *
 * uid is user-addressable (a mutation request names a campaign by uid and it
 * is re-resolved inside the Business's selected account; external ids from a
 * request are never trusted). The composite FK carries business_id so a row
 * can never point at another Business's account.
 *
 * unique (id, meta_ads_account_id) exists solely so ad sets and ads can
 * composite-FK to a campaign of the SAME account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('meta_ads_account_id');
            $table->string('external_campaign_id', 32);
            $table->string('name', 255);
            $table->string('status', 16);
            $table->string('effective_status', 40)->nullable();
            $table->string('objective', 64)->nullable();
            $table->unsignedBigInteger('daily_budget_minor')->nullable();
            $table->unsignedBigInteger('lifetime_budget_minor')->nullable();
            $table->unsignedBigInteger('budget_remaining_minor')->nullable();
            $table->dateTime('start_time')->nullable();
            $table->dateTime('stop_time')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['meta_ads_account_id', 'business_id'], 'mads_camp_account_business_fk')
                ->references(['id', 'business_id'])->on('meta_ads_accounts')->cascadeOnDelete();

            $table->unique(['meta_ads_account_id', 'external_campaign_id'], 'mads_camp_account_external_unique');
            $table->unique(['id', 'meta_ads_account_id'], 'mads_camp_id_account_unique');
            $table->index(['meta_ads_account_id', 'business_id'], 'mads_camp_account_business_idx');
            $table->index(['meta_ads_account_id', 'status'], 'mads_camp_account_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_campaigns');
    }
};
