<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 21 §6 — THE canonical local lane-A subscription
 * identity: a Workspace owner's Core/Growth/Agency subscription to the
 * PLATFORM, paid into the Platform Owner's own Stripe account.
 *
 * LANE A ONLY (§1, §2). This table has no relationship of any kind to:
 *   - lane B's business_document_payments / business_stripe_connections
 *     (Contract 17 — the Business's OWN connected account, the Business's
 *     revenue), or
 *   - lane D's payment_provider_customers / business_usage_wallets (RFC-005 —
 *     usage FUNDING, scoped to an EffectivePayer, which has no meaning for a
 *     Workspace subscription), or
 *   - lane C's Agency SaaS rows once they exist.
 * Sharing one Stripe API credential across lanes is not sharing a commercial
 * identity, and this table is where that distinction is made concrete.
 *
 * ONE ROW PER WORKSPACE (`unique(workspace_id)`), mirroring
 * `workspace_plan_assignments`' own uniqueness. A cancelled subscriber who
 * comes back re-drives THIS row rather than accumulating parallel rows that
 * could disagree about which one is current; `platform_subscription_events`
 * is the audit trail.
 *
 * IT MUST ANSWER §6's QUESTIONS WITHOUT CALLING STRIPE. Which Workspace, which
 * tier, which commercial terms, which provider customer/subscription, current
 * period, trial end, cancel-at-period-end, provider state, last processed
 * event, and why the Workspace is currently Trial/Active/Grace/Locked.
 *
 * THE COMMERCIAL TERMS ARE SNAPSHOTTED (§6, §8, §10.1). price/currency/cycle/
 * trial-days are copied here at purchase, so a Platform Owner editing the
 * catalog price tomorrow cannot retroactively rewrite what an existing
 * subscriber agreed to. workspace_plan_catalog_pricing_changes remains the
 * only price-HISTORY authority; this is a point-in-time copy, not a history.
 *
 * `status` is OUR local vocabulary, mapped from the provider's in exactly one
 * seam. There is deliberately NO card column of any kind — no PAN, no last4,
 * no brand, no expiry. Card data is collected by Stripe's hosted Checkout and
 * never reaches this application.
 *
 * FKs are RESTRICT: a money record never silently disappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('workspace_plan_catalog_id');

            // §5 — derived from this row's own UID, so a repeat of an
            // uncertain provider call cannot originate a second subscription.
            $table->string('local_idempotency_key', 191)->unique();

            // ---- snapshotted commercial terms (§6) ----------------------
            $table->decimal('price_snapshot', 16, 2)->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('billing_cycle_snapshot', 20);
            $table->unsignedSmallInteger('trial_days_snapshot')->nullable();

            // ---- provider identity (never provider secrets) --------------
            $table->string('provider_customer_id', 191)->nullable();
            $table->string('provider_subscription_id', 191)->nullable();
            $table->string('provider_price_id', 191)->nullable();
            $table->string('provider_checkout_session_id', 191)->nullable();

            // ---- provider-confirmed state --------------------------------
            $table->string('status', 24)->default('pending');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // §10.2 — a downgrade takes effect at the period end, so the
            // intended tier is parked here rather than applied early.
            $table->unsignedBigInteger('pending_plan_catalog_id')->nullable();
            $table->timestamp('pending_effective_at')->nullable();

            // ---- replay/ordering bookkeeping (§12) -----------------------
            $table->string('last_event_id', 191)->nullable();
            $table->timestamp('last_event_at')->nullable();
            // A reason CODE, never a provider message: a message can carry
            // account identifiers and request detail.
            $table->string('last_reason', 64)->nullable();

            $table->timestamps();

            $table->foreign('workspace_id', 'ps_workspace_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign('workspace_plan_catalog_id', 'ps_catalog_foreign')
                ->references('id')->on('workspace_plan_catalog')->restrictOnDelete();
            $table->foreign('pending_plan_catalog_id', 'ps_pending_catalog_foreign')
                ->references('id')->on('workspace_plan_catalog')->restrictOnDelete();
            $table->foreign('currency_id', 'ps_currency_foreign')
                ->references('id')->on('currencies')->restrictOnDelete();

            $table->unique('workspace_id', 'ps_workspace_unique');
            // Unique when populated; NULLs coexist, so a row can exist before
            // the provider has told us its identity.
            $table->unique('provider_subscription_id', 'ps_provider_subscription_unique');
            $table->unique('provider_checkout_session_id', 'ps_provider_checkout_unique');
            $table->index('provider_customer_id', 'ps_provider_customer_index');
            $table->index('status', 'ps_status_index');
            $table->index(['status', 'current_period_end'], 'ps_status_period_end_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscriptions');
    }
};
