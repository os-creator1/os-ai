<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 contract 23 §6 / D8 — the 1:1 Ads-domain DETAIL of a
 * mutation whose durable operation lives in business_google_operations.
 * Purpose: the ledger has no place for the target, requested state or
 * negative-keyword parameters, and the dedupe key must be queryable.
 *
 * It stores NO status, failure or idempotency truth of its own: the ledger
 * row (unique business_google_operation_id) owns operation key, status,
 * ambiguity (`unknown`), deferral and actor. Duplicating that here would
 * create two sources of truth.
 *
 * target_local_id is the local campaign / keyword / ad group id the request
 * resolved INSIDE the Business's selected account; target_resource_name is
 * what was sent to Google. requested_state (ENABLED|PAUSED) is NULL for a
 * negative keyword. params (negative text / match type / scope) is NULL for
 * a status change. dedupe_key is indexed so "same target + same requested
 * state already pending/unknown" is a lookup, not a scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_mutations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('google_ads_account_id');
            $table->unsignedBigInteger('business_google_operation_id');
            $table->string('kind', 24);
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_local_id');
            $table->string('target_resource_name', 191);
            $table->string('requested_state', 16)->nullable();
            $table->json('params')->nullable();
            $table->string('dedupe_key', 64);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamps();

            $table->foreign(['google_ads_account_id', 'business_id'], 'gads_mut_account_business_fk')
                ->references(['id', 'business_id'])->on('google_ads_accounts')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'gads_mut_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('business_google_operation_id', 'gads_mut_operation_fk')
                ->references('id')->on('business_google_operations')->cascadeOnDelete();

            $table->unique('business_google_operation_id', 'gads_mut_operation_unique');
            $table->index(['google_ads_account_id', 'business_id'], 'gads_mut_account_business_idx');
            $table->index(['google_ads_account_id', 'dedupe_key'], 'gads_mut_account_dedupe_idx');
            $table->index(['kind', 'target_local_id'], 'gads_mut_kind_target_idx');
            $table->index('actor_user_id', 'gads_mut_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_mutations');
    }
};
