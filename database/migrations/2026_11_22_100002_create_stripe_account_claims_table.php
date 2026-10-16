<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Stripe account that is currently owned by a live connection in
 * ANY payment lane (Business lane B, Agency lane C), or is mid-revocation.
 *
 * WHY A TABLE. Each lane has its own connections table, and MySQL cannot
 * express a unique constraint across two tables, so "this account belongs to at
 * most one live connection" could only be checked, never enforced, and two
 * concurrent connects (or a connect racing a revoke) could both pass the check.
 * UNIQUE(stripe_account_id) here makes the database the arbiter. Connection
 * rows stay historical and untouched; only this ownership marker is released.
 *
 * `connection_id` is deliberately not a foreign key: it points into one of two
 * tables, selected by `lane`; StripeAccountClaims proves liveness before it
 * ever evicts a claim.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stripe_account_claims', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_account_id', 191)->unique();
            $table->string('lane', 16);
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['lane', 'connection_id'], 'sac_lane_connection_index');
        });

        // Existing live connections become claims (INSERT IGNORE: should two
        // legacy rows ever share an account, the first keeps it).
        DB::statement("INSERT IGNORE INTO stripe_account_claims (stripe_account_id, lane, connection_id, created_at, updated_at)
            SELECT stripe_account_id, 'business', id, NOW(), NOW() FROM business_stripe_connections
            WHERE status IN ('pending','onboarding','active','restricted')");
        DB::statement("INSERT IGNORE INTO stripe_account_claims (stripe_account_id, lane, connection_id, created_at, updated_at)
            SELECT stripe_account_id, 'agency', id, NOW(), NOW() FROM agency_stripe_connections
            WHERE status <> 'disconnected'");
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_account_claims');
    }
};
