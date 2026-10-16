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
        $this->assertNoConflictingLiveOwnership();

        Schema::create('stripe_account_claims', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_account_id', 191)->unique();
            $table->string('lane', 16);
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Set while a disconnect is revoking the OAuth grant at Stripe: the
            // claim stays owned until that finishes, even if a deauthorisation
            // webhook has already made the connection row terminal.
            $table->timestamp('revoking_until')->nullable();
            $table->timestamps();

            $table->index(['lane', 'connection_id'], 'sac_lane_connection_index');
        });

        // Existing live connections become claims. The preflight above has
        // proven no account is live twice, so a plain INSERT is exact: any
        // duplicate here would be a bug and must fail loudly, never be skipped.
        DB::statement("INSERT INTO stripe_account_claims (stripe_account_id, lane, connection_id, created_at, updated_at)
            SELECT stripe_account_id, 'business', id, NOW(), NOW() FROM business_stripe_connections
            WHERE status IN ('pending','onboarding','active','restricted')");
        DB::statement("INSERT INTO stripe_account_claims (stripe_account_id, lane, connection_id, created_at, updated_at)
            SELECT stripe_account_id, 'agency', id, NOW(), NOW() FROM agency_stripe_connections
            WHERE status <> 'disconnected'");
    }

    /**
     * PREFLIGHT, before any schema change. If one Stripe account is already
     * live on more than one connection (two Businesses, two Agencies, or a
     * Business and an Agency) there is no safe automatic owner: picking one
     * would silently change who may charge through that account. Abort with
     * the evidence instead; nothing is altered, deleted or chosen.
     */
    private function assertNoConflictingLiveOwnership(): void
    {
        $conflicts = DB::select("
            SELECT stripe_account_id,
                   COUNT(*) AS owners,
                   GROUP_CONCAT(CONCAT(lane, '#', id) ORDER BY lane, id SEPARATOR ', ') AS connections
            FROM (
                SELECT stripe_account_id, 'business' AS lane, id FROM business_stripe_connections
                    WHERE status IN ('pending','onboarding','active','restricted')
                UNION ALL
                SELECT stripe_account_id, 'agency' AS lane, id FROM agency_stripe_connections
                    WHERE status <> 'disconnected'
            ) live
            GROUP BY stripe_account_id
            HAVING COUNT(*) > 1
            ORDER BY stripe_account_id
            LIMIT 20");

        if ($conflicts === []) {
            return;
        }

        $lines = array_map(
            static fn ($row) => "  {$row->stripe_account_id}: {$row->owners} live connections ({$row->connections})",
            $conflicts
        );

        throw new RuntimeException(
            "Cannot create stripe_account_claims: a Stripe account is already live on more than one connection.\n"
            . implode("\n", $lines) . "\n"
            . "Nothing has been changed. Decide which connection should keep each account, have its owner disconnect the "
            . "other(s) from the application (or set the unwanted row's status to 'disconnected' by hand after confirming "
            . "no payment is in flight), then run `php artisan migrate` again. Rows and payment history are not touched by this migration."
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_account_claims');
    }
};
