<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane C §C3.4 — THE canonical lane-C commercial record: what one client pays
 * one Agency, on what terms, and what the provider has confirmed about it.
 *
 * `unique(client_workspace_id)` is structural, not convenient: a Client
 * Workspace has 0 or 1 active managing Agency (Addendum §2), so it has 0 or 1
 * lane-C subscription. One row per client means there is never an argument
 * about which record is current, and a customer who cancels and returns
 * re-drives their own row rather than accumulating a second one.
 *
 * THIS IS NOT AN ENTITLEMENT AUTHORITY. The CLIENT Workspace keeps its own
 * `workspace_plan_assignments` row, exactly as any other customer does. This
 * table records the COMMERCIAL relationship and supplies the provider-confirmed
 * reasons that make EntitlementManager's existing lifecycle writers fire. It is
 * never consulted to answer "may this Workspace use feature X".
 *
 * `connected_account_id` IS DENORMALIZED ON PURPOSE. Every provider call and
 * every inbound Connect event is checked against this column rather than against
 * the connection row it came from, so a subscription cannot be driven by an
 * event from a different Agency's account even if the connection row is later
 * changed, re-onboarded or disconnected.
 *
 * There is deliberately NO card attribute of any kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_client_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            // ---- identity and binding -----------------------------------
            $table->unsignedBigInteger('agency_workspace_id');
            $table->unsignedBigInteger('client_workspace_id')->unique();
            $table->unsignedBigInteger('agency_saas_plan_id')->nullable();
            $table->unsignedBigInteger('agency_stripe_connection_id')->nullable();
            $table->string('connected_account_id', 191)->nullable();
            $table->string('local_idempotency_key', 191)->unique();

            $table->string('status', 24)->default('offered');

            // ---- commercial snapshot, taken when the client agreed ------
            $table->decimal('price_snapshot', 16, 2)->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('billing_cycle_snapshot', 20)->nullable();
            $table->unsignedSmallInteger('trial_days_snapshot')->nullable();

            // ---- who offered it, and who consented ----------------------
            $table->unsignedBigInteger('offered_by_user_id')->nullable();
            $table->timestamp('offered_at')->nullable();
            $table->unsignedBigInteger('consented_by_user_id')->nullable();
            $table->timestamp('consented_at')->nullable();

            // ---- provider truth (finalizer only) ------------------------
            $table->string('provider_customer_id', 191)->nullable();
            $table->string('provider_subscription_id', 191)->nullable();
            $table->string('provider_price_id', 191)->nullable();
            $table->string('provider_checkout_session_id', 191)->nullable();
            $table->json('retired_provider_subscription_ids')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // ---- checkout attempt identity (§C7) ------------------------
            $table->uuid('checkout_attempt_uid')->nullable();
            $table->string('checkout_attempt_price_id', 191)->nullable();
            $table->timestamp('checkout_attempt_started_at')->nullable();
            $table->unsignedInteger('checkout_attempt_generation')->default(0);

            // ---- durable plan-change operation (§C7) --------------------
            $table->uuid('pending_operation_uid')->nullable();
            $table->string('pending_kind', 16)->nullable();
            $table->unsignedBigInteger('pending_plan_id')->nullable();
            $table->string('pending_price_id', 191)->nullable();
            $table->decimal('pending_price_snapshot', 16, 2)->nullable();
            $table->unsignedBigInteger('pending_currency_id')->nullable();
            $table->char('pending_currency_code', 3)->nullable();
            $table->string('pending_billing_cycle', 20)->nullable();
            $table->timestamp('pending_effective_at')->nullable();

            // ---- event bookkeeping --------------------------------------
            $table->string('last_event_id', 191)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->string('last_reason', 64)->nullable();

            $table->timestamps();

            $table->foreign('agency_workspace_id', 'acs_agency_workspace_foreign')
                ->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_workspace_id', 'acs_client_workspace_foreign')
                ->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('agency_saas_plan_id', 'acs_plan_foreign')
                ->references('id')->on('agency_saas_plans')->nullOnDelete();
            $table->foreign('agency_stripe_connection_id', 'acs_connection_foreign')
                ->references('id')->on('agency_stripe_connections')->nullOnDelete();
            $table->foreign('currency_id', 'acs_currency_foreign')
                ->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('pending_currency_id', 'acs_pending_currency_foreign')
                ->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('pending_plan_id', 'acs_pending_plan_foreign')
                ->references('id')->on('agency_saas_plans')->nullOnDelete();
            $table->foreign('offered_by_user_id', 'acs_offered_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('consented_by_user_id', 'acs_consented_by_foreign')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['agency_workspace_id', 'status'], 'acs_agency_status_index');
            $table->index('provider_subscription_id', 'acs_provider_subscription_index');
            $table->index('provider_customer_id', 'acs_provider_customer_index');
            $table->index(['pending_kind', 'pending_effective_at'], 'acs_pending_operation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_client_subscriptions');
    }
};
