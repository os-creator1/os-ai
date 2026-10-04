<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 contract 23 §4 / §12 — per-day search-term facts.
 * Purpose: the Search terms page and the deterministic waste / converting
 * classification; the only place the owner's per-term review_state lives.
 *
 * One row per (account, day, ad group, term). term_hash is sha256 of the
 * lower-cased, trimmed term and exists so the unique key and the review
 * lookup do not index a 255-char string. The unique key is the idempotent
 * upsert key, so re-syncing a window changes nothing; the sync must NOT
 * overwrite review_state, which is the owner's classification of the term,
 * not a recommendation lifecycle (§12).
 *
 * impressions / clicks / cost_micros are NOT NULL (a returned row always
 * carries them; a missing one is read as 0 by the mapper). conversions and
 * conversions_value are NULLABLE: NULL means Google gave no conversion data,
 * which is NOT the same as 0 conversions. matched_keyword_* are optional
 * extras (§2) and tolerated as absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_search_terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('google_ads_account_id');
            $table->unsignedBigInteger('google_ads_campaign_id');
            $table->unsignedBigInteger('google_ads_ad_group_id');
            $table->string('search_term', 255);
            $table->char('term_hash', 64);
            $table->date('metric_date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->decimal('conversions', 20, 6)->nullable();
            $table->decimal('conversions_value', 20, 6)->nullable();
            $table->string('targeting_status', 16)->nullable();
            $table->string('matched_keyword_text', 255)->nullable();
            $table->string('matched_keyword_match_type', 8)->nullable();
            $table->string('review_state', 16)->default('unreviewed');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['google_ads_account_id', 'business_id'], 'gads_st_account_business_fk')
                ->references(['id', 'business_id'])->on('google_ads_accounts')->cascadeOnDelete();
            $table->foreign(['google_ads_campaign_id', 'google_ads_account_id'], 'gads_st_campaign_account_fk')
                ->references(['id', 'google_ads_account_id'])->on('google_ads_campaigns')->cascadeOnDelete();
            $table->foreign(['google_ads_ad_group_id', 'google_ads_account_id'], 'gads_st_adgroup_account_fk')
                ->references(['id', 'google_ads_account_id'])->on('google_ads_ad_groups')->cascadeOnDelete();

            $table->unique(['google_ads_account_id', 'metric_date', 'google_ads_ad_group_id', 'term_hash'], 'gads_st_natural_unique');
            $table->index(['google_ads_account_id', 'business_id'], 'gads_st_account_business_idx');
            $table->index(['google_ads_account_id', 'term_hash'], 'gads_st_account_term_idx');
            $table->index(['google_ads_account_id', 'metric_date'], 'gads_st_account_date_idx');
            $table->index(['google_ads_campaign_id', 'google_ads_account_id'], 'gads_st_campaign_account_idx');
            $table->index(['google_ads_ad_group_id', 'google_ads_account_id'], 'gads_st_adgroup_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_search_terms');
    }
};
