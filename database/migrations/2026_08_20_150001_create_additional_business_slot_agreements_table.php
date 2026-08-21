<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §18/§22, M4 contract §18 — the additional-slot agreement's own
 * billing-side record. `current_allocation_count`/`target_allocation_count`
 * are billing-side view only; workspace_plan_assignments.additional_business_slots
 * (RFC-004) remains the sole authoritative entitlement value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_business_slot_agreements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedTinyInteger('current_allocation_count');
            $table->unsignedTinyInteger('target_allocation_count');
            $table->unsignedTinyInteger('paid_delta');
            $table->bigInteger('price_per_slot_micro_snapshot');
            $table->bigInteger('total_amount_micro_snapshot');
            $table->unsignedBigInteger('currency_id_snapshot');
            $table->decimal('ratio_snapshot', 6, 4);
            $table->unsignedBigInteger('plan_catalog_id_snapshot');
            $table->string('plan_tier_snapshot', 16);
            $table->unsignedBigInteger('requesting_customer_user_id');
            $table->string('requesting_customer_email_snapshot', 191);
            $table->unsignedBigInteger('provider_customer_id');
            $table->string('payment_method_display_snapshot', 64);
            $table->string('local_idempotency_key', 191);
            $table->string('provider_session_or_intent_reference', 191)->nullable();
            $table->string('billing_cadence', 16)->default('monthly');
            $table->timestamp('next_renewal_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->timestamp('cancellation_effective_at')->nullable();
            $table->boolean('payment_lapsed')->default(false);
            $table->timestamp('payment_lapsed_at')->nullable();
            $table->timestamp('payment_lapsed_cleared_at')->nullable();
            $table->string('state', 20)->default('quote_created');
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign('currency_id_snapshot')->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('plan_catalog_id_snapshot', 'absa_plan_catalog_id_snapshot_foreign')->references('id')->on('workspace_plan_catalog')->restrictOnDelete();
            $table->foreign('provider_customer_id')->references('id')->on('payment_provider_customers')->restrictOnDelete();

            $table->unique('local_idempotency_key');
            $table->unique('provider_session_or_intent_reference', 'absa_provider_session_or_intent_reference_unique');
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_business_slot_agreements');
    }
};
