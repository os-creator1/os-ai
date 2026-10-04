<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §3 — the Business-owned Meta OAuth
 * connection. Purpose: the single authority for "which Meta user authorised
 * this Business, with what token, until when". Nothing is added to
 * business_google_connections (contract 24 M2): that table is product-keyed
 * Google state and Meta has its own state machine and token lifecycle.
 *
 * One row per Business (unique business_id).
 *
 * ROLLBACK WARNING: rolling back DESTROYS every stored Meta authorisation.
 * The long-lived user tokens are encrypted at rest with the application key
 * and exist nowhere else; every connected Business must re-run the OAuth
 * consent flow. Meta has no refresh token, so re-authorising is also the
 * normal renewal path.
 *
 * access_token_encrypted holds the LONG-LIVED user token through Laravel's
 * `encrypted` cast on the model (same precedent as
 * business_google_connections.refresh_token_encrypted). It is NULL in every
 * state except `active`. token_expires_at is now + Meta's expires_in at
 * exchange time; the UI warns before it and the sync stops past it.
 *
 * oauth_state_nonce / oauth_state_expires_at carry the single-use signed
 * state nonce (the connection row is created `pending` at connect initiation,
 * so no extra table is needed). meta_user_id / meta_user_name come from
 * GET /me after the code exchange and let every sync refuse a connection
 * whose Meta identity changed.
 *
 * unique (id, business_id) exists solely so meta_ads_accounts can
 * composite-FK to this row and carry the same business_id.
 * index (state, token_expires_at) serves the expiry warning / sweep reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_meta_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->string('state', 16)->default('pending');
            $table->text('access_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('granted_scopes', 512)->nullable();
            $table->string('meta_user_id', 64)->nullable();
            $table->string('meta_user_name', 191)->nullable();
            $table->string('oauth_state_nonce', 64)->nullable();
            $table->timestamp('oauth_state_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('failure_classification', 32)->nullable();
            $table->unsignedBigInteger('connected_by_user_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->foreign('business_id', 'bmc_business_fk')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('connected_by_user_id', 'bmc_connected_by_fk')->references('id')->on('users')->nullOnDelete();

            $table->unique('business_id', 'bmc_business_unique');
            $table->unique(['id', 'business_id'], 'bmc_id_business_unique');
            $table->unique('oauth_state_nonce', 'bmc_oauth_state_nonce_unique');
            $table->index('state', 'bmc_state_index');
            $table->index('connected_by_user_id', 'bmc_connected_by_index');
            $table->index(['state', 'token_expires_at'], 'bmc_state_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_meta_connections');
    }
};
