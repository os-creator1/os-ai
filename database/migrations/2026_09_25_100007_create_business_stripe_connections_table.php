<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.7, Sub-slice A — lane-B connected-account
 * records. Lane B only (§4): nothing here relates to payment_provider_customers,
 * business_payment_instruments or any other lane-A/lane-D table.
 *
 * Connections are HISTORICAL RECORDS. An earlier design put unique(business_id)
 * here; that would allow exactly one row for a Business's whole lifetime and
 * force rewriting stripe_account_id in place, corrupting the attribution of
 * every payment that FKs to the row. So:
 *
 *   - `business_id` is a plain FK (NOT unique);
 *   - `stripe_account_id` is UNIQUE and immutable once provider identity is
 *     established (never rewritten to a different acct_ — enforced by the
 *     later manager);
 *   - `active_business_id` is a STORED generated column that yields the
 *     business_id only while the connection is in a LIVE state
 *     (pending/onboarding/active/restricted), and is UNIQUE — so a Business
 *     has at most ONE live connection while any number of terminal
 *     `disconnected` rows coexist as history.
 *
 * "1 Business = 1 connected Stripe account in V1" (Addendum §12) therefore
 * means one LIVE connected relationship, not one lifetime row.
 *
 * NO secret key is ever stored (§5.7): direct charges use the platform key
 * plus the Stripe-Account header naming stripe_account_id, so this table has
 * no token/secret column of any kind.
 *
 * `business_id` is RESTRICT — required in any case, because it is the base
 * column of a stored generated column (MySQL forbids CASCADE/SET NULL there).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_stripe_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->string('stripe_account_id', 64);
            $table->string('status', 24)->default('pending');
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->string('requirements_disabled_reason', 120)->nullable();
            $table->char('default_currency', 3)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->foreign('business_id', 'bsc_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();

            $table->unique('stripe_account_id', 'bsc_stripe_account_unique');
            $table->index(['business_id', 'status'], 'bsc_business_status_index');
        });

        Schema::table('business_stripe_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('active_business_id')
                ->nullable()
                ->storedAs("CASE WHEN status IN ('pending','onboarding','active','restricted') THEN business_id ELSE NULL END")
                ->after('status');
        });

        Schema::table('business_stripe_connections', function (Blueprint $table) {
            $table->unique('active_business_id', 'bsc_active_business_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_stripe_connections');
    }
};
