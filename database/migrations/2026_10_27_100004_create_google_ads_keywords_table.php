<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 contract 23 §4 — positive AND negative keywords.
 * Purpose: the Keywords page, negative-keyword de-duplication before a
 * mutation (§6 step 3), and the pause/resume target.
 *
 * external_criterion_id holds the criterion's RESOURCE-NAME TAIL
 * (`{adGroupId}~{criterionId}` for ad-group criteria, `{campaignId}~{criterionId}`
 * for campaign criteria) because a bare criterion_id is only unique within
 * its parent. With `level` it is unique per account, and is the idempotent
 * upsert key. is_negative is immutable at Google (switching = remove + add),
 * so a changed value is a different row.
 *
 * google_ads_ad_group_id is NULL exactly for campaign-level negatives.
 * quality_score is NULL unless the API returned one (absence is not 0).
 * uid is user-addressable (pause/resume requests name a keyword by uid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_keywords', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('google_ads_account_id');
            $table->unsignedBigInteger('google_ads_campaign_id');
            $table->unsignedBigInteger('google_ads_ad_group_id')->nullable();
            $table->string('external_criterion_id', 64);
            $table->string('text', 255);
            $table->string('match_type', 8);
            $table->string('status', 16);
            $table->boolean('is_negative')->default(false);
            $table->string('level', 16);
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['google_ads_account_id', 'business_id'], 'gads_kw_account_business_fk')
                ->references(['id', 'business_id'])->on('google_ads_accounts')->cascadeOnDelete();
            $table->foreign(['google_ads_campaign_id', 'google_ads_account_id'], 'gads_kw_campaign_account_fk')
                ->references(['id', 'google_ads_account_id'])->on('google_ads_campaigns')->cascadeOnDelete();
            $table->foreign(['google_ads_ad_group_id', 'google_ads_account_id'], 'gads_kw_adgroup_account_fk')
                ->references(['id', 'google_ads_account_id'])->on('google_ads_ad_groups')->cascadeOnDelete();

            $table->unique(['google_ads_account_id', 'level', 'external_criterion_id'], 'gads_kw_account_level_external_unique');
            $table->index(['google_ads_account_id', 'business_id'], 'gads_kw_account_business_idx');
            $table->index(['google_ads_campaign_id', 'google_ads_account_id'], 'gads_kw_campaign_account_idx');
            $table->index(['google_ads_ad_group_id', 'google_ads_account_id'], 'gads_kw_adgroup_account_idx');
            $table->index(['google_ads_account_id', 'is_negative', 'status'], 'gads_kw_account_negative_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_keywords');
    }
};
