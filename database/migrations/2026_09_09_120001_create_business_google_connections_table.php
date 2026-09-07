<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GBP Slice A contract §11.1 / §12 (C-1) — the Business-owned Google OAuth
 * connection. One row per Business (business_id UNIQUE, C-1).
 *
 * ROLLBACK WARNING (contract §29.4, mandatory): rolling back this
 * migration DESTROYS every stored Google authorization. Every connected
 * Business must re-run the full OAuth consent flow. The refresh tokens are
 * not recoverable by any means — they are encrypted at rest with the
 * application key and exist nowhere else.
 *
 * refresh_token_encrypted uses Laravel's built-in `encrypted` cast on the
 * model (contract §9.7) — the same and only precedent in this repository
 * as PaymentProviderEvent.payload_encrypted. There is deliberately NO
 * access-token column: an access token is derived from the refresh token
 * per unit of work and never persisted (§9.7).
 *
 * The composite UNIQUE (id, business_id) exists solely to support the
 * composite foreign key C-5 on business_google_locations, mirroring
 * business_usage_wallets' own (id, business_id) support for
 * bfa_wallet_business_foreign in
 * 2026_08_16_140003_create_business_funding_attempts_table.php.
 *
 * oauth_state_nonce / oauth_state_expires_at / refresh_claimed_at are the
 * narrowest implementation-compatible home for three mechanisms the
 * contract requires without enumerating a column for them (deviation D-1,
 * reported with this slice):
 *   - §9.4's single-use, atomically consumed, expiring OAuth state nonce.
 *     §10.1 already creates the connection row in `pending` at connect
 *     initiation, so the nonce needs no fourth table (§12 caps GBP at
 *     three tables).
 *   - §24.3's per-connection concurrency of one, claimed with a
 *     conditional UPDATE in the B4 AutomationExecutionClaimService idiom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_google_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->string('state', 16)->default('pending');
            $table->text('refresh_token_encrypted')->nullable();
            $table->string('granted_scopes', 512)->nullable();
            $table->string('google_account_email', 191)->nullable();
            $table->string('oauth_state_nonce', 64)->nullable();
            $table->timestamp('oauth_state_expires_at')->nullable();
            $table->timestamp('refresh_claimed_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('failure_classification', 32)->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique('business_id', 'bgc_business_unique');
            $table->unique(['id', 'business_id'], 'bgc_id_business_unique');
            $table->unique('oauth_state_nonce', 'bgc_oauth_state_nonce_unique');
            $table->index('state', 'bgc_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_google_connections');
    }
};
