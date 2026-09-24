<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane C §C3.1 — the Stripe account that receives ONE Agency's SaaS revenue.
 *
 * WHY NOT `business_stripe_connections`. That table is lane B: a BUSINESS's own
 * connected account, for that Business's own customer revenue. An Agency's
 * resale revenue is a different economic relationship (Addendum §12), and
 * making a lane-B row the implicit authority for lane-C subscriptions is
 * exactly the conflation §C1 forbids. The same physical Stripe account may
 * legitimately serve both purposes — that is the merchant's business — but the
 * RECORDS stay distinct so no lane-B document payment can ever be read as
 * Agency SaaS revenue, or the reverse.
 *
 * NO SECRET LIVES HERE. `stripe_account_id` is a provider identifier, not a
 * credential. There is deliberately no column that could hold a secret key, a
 * webhook secret or an OAuth token (Contract 21 §5.1).
 *
 * ONE ACTIVE CONNECTION PER AGENCY (V1). MySQL cannot express "unique where
 * status <> disconnected", so the invariant is enforced in
 * AgencyStripeConnectManager under a Workspace row lock; the index below makes
 * that check cheap and the intent legible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_stripe_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->unsignedBigInteger('agency_workspace_id');
            $table->string('stripe_account_id', 191);
            $table->string('status', 20)->default('pending');

            // Provider-confirmed readiness, re-read rather than assumed.
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->char('default_currency', 3)->nullable();
            $table->string('requirements_disabled_reason', 191)->nullable();

            $table->unsignedBigInteger('connected_by_user_id')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->unsignedBigInteger('disconnected_by_user_id')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->foreign('agency_workspace_id', 'asc_agency_workspace_foreign')
                ->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connected_by_user_id', 'asc_connected_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('disconnected_by_user_id', 'asc_disconnected_by_foreign')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['agency_workspace_id', 'status'], 'asc_agency_status_index');
            // A provider account id is resolved on every inbound Connect event,
            // so this lookup is on the hot path.
            $table->index('stripe_account_id', 'asc_stripe_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_stripe_connections');
    }
};
