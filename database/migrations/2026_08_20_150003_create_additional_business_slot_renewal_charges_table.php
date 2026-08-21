<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §18/§22, M4 contract §18 — one row per scheduled renewal or
 * mid-period increase charge. local_idempotency_key's own derivation
 * branches on charge_kind (M4 contract §11); provider_session_or_intent_reference
 * is this renewal's own PaymentIntent id, distinct from the parent
 * agreement's initial Checkout Session id.
 *
 * Correction Round 1 §B — allocation_delta is the durable, frozen slot
 * count a mid_period_increase charge purchases; NULL for scheduled_renewal.
 * Never reverse-derived from amount_micro_snapshot (proration/rounding
 * make that mapping lossy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_business_slot_renewal_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agreement_id');
            $table->string('charge_kind', 24);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->bigInteger('amount_micro_snapshot');
            $table->string('requesting_customer_email_snapshot', 191);
            $table->string('payer_type_snapshot', 16)->default('workspace');
            $table->string('provider_customer_external_id_snapshot', 191);
            $table->string('payment_method_display_snapshot', 64);
            $table->unsignedBigInteger('currency_id_snapshot');
            $table->unsignedBigInteger('plan_catalog_id_snapshot');
            $table->string('plan_tier_snapshot', 16);
            $table->decimal('ratio_snapshot', 6, 4);
            $table->string('initiated_by', 16)->default('scheduled_job');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('change_operation_id', 191)->nullable();
            $table->string('local_idempotency_key', 191);
            $table->string('provider_session_or_intent_reference', 191)->nullable();
            $table->unsignedTinyInteger('allocation_delta')->nullable()->default(null);
            $table->string('state', 16)->default('created');
            $table->text('failure_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('agreement_id')->references('id')->on('additional_business_slot_agreements')->restrictOnDelete();
            $table->foreign('currency_id_snapshot', 'absrc_currency_id_snapshot_foreign')->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('plan_catalog_id_snapshot', 'absrc_plan_catalog_id_snapshot_foreign')->references('id')->on('workspace_plan_catalog')->restrictOnDelete();

            $table->unique('change_operation_id', 'absrc_change_operation_id_unique');
            $table->unique('local_idempotency_key', 'absrc_local_idempotency_key_unique');
            $table->unique('provider_session_or_intent_reference', 'absrc_provider_session_or_intent_reference_unique');
            $table->index('agreement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_business_slot_renewal_charges');
    }
};
