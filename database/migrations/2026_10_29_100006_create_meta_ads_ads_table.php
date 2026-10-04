<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §5 — normalised ads with a read-only
 * creative summary. Purpose: the Ads page and the ad-level pause/resume
 * target, plus the delivery-issue fact (effective_status, e.g. DISAPPROVED).
 *
 * Unique (account, external_ad_id) is the idempotent upsert key. Two
 * composite FKs keep the hierarchy inside ONE account: (campaign, account)
 * and (ad set, account). Meta's adset_id is immutable, so an ad never moves
 * between ad sets.
 *
 * creative_* are display facts copied for the list view (title, body,
 * thumbnail URL, object type); NULL when Meta returned none. No creative is
 * ever written back to Meta in V1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_ads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('meta_ads_account_id');
            $table->unsignedBigInteger('meta_ads_campaign_id');
            $table->unsignedBigInteger('meta_ads_ad_set_id');
            $table->string('external_ad_id', 32);
            $table->string('name', 255);
            $table->string('status', 16);
            $table->string('effective_status', 40)->nullable();
            $table->string('creative_title', 255)->nullable();
            $table->text('creative_body')->nullable();
            $table->text('creative_thumbnail_url')->nullable();
            $table->string('creative_object_type', 40)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['meta_ads_account_id', 'business_id'], 'mads_ad_account_business_fk')
                ->references(['id', 'business_id'])->on('meta_ads_accounts')->cascadeOnDelete();
            $table->foreign(['meta_ads_campaign_id', 'meta_ads_account_id'], 'mads_ad_campaign_account_fk')
                ->references(['id', 'meta_ads_account_id'])->on('meta_ads_campaigns')->cascadeOnDelete();
            $table->foreign(['meta_ads_ad_set_id', 'meta_ads_account_id'], 'mads_ad_adset_account_fk')
                ->references(['id', 'meta_ads_account_id'])->on('meta_ads_ad_sets')->cascadeOnDelete();

            $table->unique(['meta_ads_account_id', 'external_ad_id'], 'mads_ad_account_external_unique');
            $table->index(['meta_ads_account_id', 'business_id'], 'mads_ad_account_business_idx');
            $table->index(['meta_ads_campaign_id', 'meta_ads_account_id'], 'mads_ad_campaign_account_idx');
            $table->index(['meta_ads_ad_set_id', 'meta_ads_account_id'], 'mads_ad_adset_account_idx');
            $table->index(['meta_ads_account_id', 'status'], 'mads_ad_account_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_ads');
    }
};
