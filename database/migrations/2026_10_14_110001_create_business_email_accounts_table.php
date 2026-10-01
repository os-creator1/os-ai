<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Email foundation — the Business-owned connected mailbox. One row
 * per Business (business_id UNIQUE): V1 permits exactly one connected email
 * identity per Business, which is therefore ALSO the default sender. There
 * is no `is_default` flag and no per-workflow account id; callers ask for
 * "the Business's sender" and the row answers.
 *
 * WHY A NEW TABLE. `external_calendar_connections` is per-USER with a
 * Calendar-only consent; `business_google_connections` is Business-scoped
 * but its consent is Business Profile. Neither grant may send mail, and
 * storing a mail grant in either would blur which consent a token carries.
 *
 * refresh_token_encrypted uses Laravel's `encrypted` cast on the model (the
 * repository convention, same as BusinessGoogleConnection). There is
 * deliberately NO access-token column: an access token is derived from the
 * refresh token per unit of work and never persisted.
 *
 * oauth_state_nonce / oauth_state_expires_at hold the single-use OAuth state
 * (§ the GBP/Calendar precedent) on the pending row itself — no extra table.
 *
 * ROLLBACK WARNING: rolling back DESTROYS every stored mail authorization.
 * Every connected Business must run consent again; the tokens exist nowhere
 * else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_email_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->string('provider', 16);
            $table->string('state', 16)->default('pending');
            // The connected mailbox (what recipients see as the sender).
            $table->string('mailbox_email', 191)->nullable();
            $table->string('external_account_id', 191)->nullable();
            $table->string('display_name', 191)->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->string('granted_scopes', 512)->nullable();
            $table->string('oauth_state_nonce', 64)->nullable();
            $table->timestamp('oauth_state_expires_at')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('failure_classification', 32)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique('business_id', 'bea_business_unique');
            $table->unique('oauth_state_nonce', 'bea_oauth_state_nonce_unique');
            $table->index('state', 'bea_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_email_accounts');
    }
};
