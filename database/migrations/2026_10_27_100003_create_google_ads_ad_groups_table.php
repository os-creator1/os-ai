<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 contract 23 §4 — normalised ad groups. Purpose: the
 * parent of positive keywords and the scope of ad-group-level negatives and
 * search terms; without it neither can be addressed or mutated.
 *
 * Unique (account, external_ad_group_id) is the idempotent upsert key. The
 * composite FK (campaign_id, google_ads_account_id) guarantees the campaign
 * belongs to the SAME account. unique (id, google_ads_account_id) exists
 * solely so keywords and search terms can composite-FK to an ad group of the
 * same account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_ad_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('google_ads_account_id');
            $table->unsignedBigInteger('google_ads_campaign_id');
            $table->string('external_ad_group_id', 32);
            $table->string('name', 255);
            $table->string('status', 16);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['google_ads_account_id', 'business_id'], 'gads_adg_account_business_fk')
                ->references(['id', 'business_id'])->on('google_ads_accounts')->cascadeOnDelete();
            $table->foreign(['google_ads_campaign_id', 'google_ads_account_id'], 'gads_adg_campaign_account_fk')
                ->references(['id', 'google_ads_account_id'])->on('google_ads_campaigns')->cascadeOnDelete();

            $table->unique(['google_ads_account_id', 'external_ad_group_id'], 'gads_adg_account_external_unique');
            $table->unique(['id', 'google_ads_account_id'], 'gads_adg_id_account_unique');
            $table->index(['google_ads_account_id', 'business_id'], 'gads_adg_account_business_idx');
            $table->index(['google_ads_campaign_id', 'google_ads_account_id'], 'gads_adg_campaign_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_ad_groups');
    }
};
