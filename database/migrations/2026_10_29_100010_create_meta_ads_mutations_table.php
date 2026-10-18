<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §5 / §7 — the 1:1 Ads-domain DETAIL of a
 * pause/resume whose durable operation lives in business_meta_operations.
 * Purpose: the ledger has no place for the target and requested state, and
 * the dedupe key must be queryable.
 *
 * It stores NO status, failure or idempotency truth of its own: the ledger
 * row (unique business_meta_operation_id) owns operation key, status,
 * ambiguity (`unknown`), deferral and actor. A second copy here would be a
 * second source of truth.
 *
 * target_type is campaign | ad_set | ad; target_local_id is the local row id
 * the request resolved INSIDE the Business's selected account (the provider
 * object id is re-derived from that row, never trusted from a request).
 * requested_state (PAUSED | ACTIVE as paused|active) is always present in V1.
 * dedupe_key is indexed so "same target + same requested state already
 * pending/unknown" is a lookup, not a scan. actor_user_id is a convenience
 * copy for display; the ledger actor is authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_mutations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('meta_ads_account_id');
            $table->unsignedBigInteger('business_meta_operation_id');
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_local_id');
            $table->string('requested_state', 16);
            $table->string('dedupe_key', 64);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamps();

            $table->foreign(['meta_ads_account_id', 'business_id'], 'mads_mut_account_business_fk')
                ->references(['id', 'business_id'])->on('meta_ads_accounts')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'mads_mut_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('business_meta_operation_id', 'mads_mut_operation_fk')
                ->references('id')->on('business_meta_operations')->cascadeOnDelete();

            $table->unique('business_meta_operation_id', 'mads_mut_operation_unique');
            $table->index(['meta_ads_account_id', 'business_id'], 'mads_mut_account_business_idx');
            $table->index(['meta_ads_account_id', 'dedupe_key'], 'mads_mut_account_dedupe_idx');
            $table->index(['target_type', 'target_local_id'], 'mads_mut_target_idx');
            $table->index('actor_user_id', 'mads_mut_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_mutations');
    }
};
