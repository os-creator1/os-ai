<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.9, Sub-slice A — refunds against a captured
 * payment. Lane B only (§4); refund ISSUANCE does not exist anywhere in the
 * repository today and is built in Sub-slice F, not here.
 *
 * Admission (§8.7) happens under the payment row lock and reserves against
 * BOTH pending and succeeded refunds; a terminal failed refund releases its
 * capacity. None of that logic lives in DDL — this table only stores the rows
 * and enforces the uniqueness that makes replay safe:
 *
 *   - unique(business_id, local_idempotency_key) — tenant-scoped; the key is
 *     `document-refund:{uid}` (§8.1);
 *   - unique(provider_refund_id) — unique when populated.
 *
 * The refund's provider call targets the payment's HISTORICAL
 * business_stripe_connection (§5.7), which is reached through the payment, so
 * no connection column is duplicated here.
 *
 * Both FKs are RESTRICT: money records never silently disappear.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_document_refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_document_payment_id');
            $table->string('local_idempotency_key', 191);
            $table->string('provider_refund_id', 191)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 255)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'bdr_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('business_document_payment_id', 'bdr_payment_foreign')
                ->references('id')->on('business_document_payments')->restrictOnDelete();

            $table->unique(['business_id', 'local_idempotency_key'], 'bdr_business_key_unique');
            $table->unique('provider_refund_id', 'bdr_provider_refund_unique');
            $table->index(['business_document_payment_id', 'status'], 'bdr_payment_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_refunds');
    }
};
