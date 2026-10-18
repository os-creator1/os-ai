<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 contract 23 §4 — normalised campaigns (with the
 * budget facts read alongside). Purpose: the local, queryable copy the
 * Campaigns/Overview pages and every mutation target resolve against, so no
 * page calls Google.
 *
 * Unique (account, external_campaign_id) is the natural key every sync
 * upserts on, which makes re-running a window idempotent. budget_* are
 * display facts only: a campaign DAILY budget is not the Business MONTHLY
 * target, and a shared budget can span campaigns (budget_shared). They are
 * NULL when Google did not return them.
 *
 * uid is user-addressable (mutation requests name a campaign by uid and it
 * is re-resolved inside the Business's selected account; external ids from a
 * request are never trusted). The composite FK carries business_id so a row
 * can never point at another Business's account.
 *
 * unique (id, google_ads_account_id) exists solely so ad groups, keywords and
 * search terms can composite-FK to a campaign of the SAME account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('google_ads_account_id');
            $table->string('external_campaign_id', 32);
            $table->string('name', 255);
            $table->string('status', 16);
            $table->string('channel_type', 40)->nullable();
            $table->string('bidding_strategy_type', 60)->nullable();
            $table->string('budget_external_id', 32)->nullable();
            $table->unsignedBigInteger('budget_amount_micros')->nullable();
            $table->boolean('budget_shared')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['google_ads_account_id', 'business_id'], 'gads_camp_account_business_fk')
                ->references(['id', 'business_id'])->on('google_ads_accounts')->cascadeOnDelete();

            $table->unique(['google_ads_account_id', 'external_campaign_id'], 'gads_camp_account_external_unique');
            $table->unique(['id', 'google_ads_account_id'], 'gads_camp_id_account_unique');
            $table->index(['google_ads_account_id', 'business_id'], 'gads_camp_account_business_idx');
            $table->index(['google_ads_account_id', 'status'], 'gads_camp_account_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_campaigns');
    }
};
