<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §17.C, M3 contract §21.3 — composite-protected on
 * (wallet_id, business_id), mirroring M1's own tenancy-ID integrity
 * pattern. provider_session_or_intent_reference is UNIQUE once populated,
 * so a verified provider object resolves to exactly one local attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_funding_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('wallet_id');
            $table->string('purpose', 24);
            $table->string('payer_type_snapshot', 16);
            $table->string('billing_contact_name_snapshot', 191)->nullable();
            $table->string('billing_contact_email_snapshot', 191)->nullable();
            $table->string('provider_customer_external_id_snapshot', 191);
            $table->unsignedBigInteger('provider_customer_id');
            $table->string('payment_method_display_snapshot', 64);
            $table->unsignedBigInteger('requesting_actor_user_id')->nullable();
            $table->unsignedBigInteger('expected_currency_id');
            $table->bigInteger('expected_amount_micro');
            $table->string('local_idempotency_key', 191);
            $table->string('provider_session_or_intent_reference', 191)->nullable();
            $table->string('state', 16)->default('created');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->foreign(['wallet_id', 'business_id'], 'bfa_wallet_business_foreign')
                ->references(['id', 'business_id'])
                ->on('business_usage_wallets');

            $table->foreign('provider_customer_id')->references('id')->on('payment_provider_customers')->restrictOnDelete();
            $table->foreign('expected_currency_id')->references('id')->on('currencies')->restrictOnDelete();

            $table->unique('local_idempotency_key');
            $table->unique('provider_session_or_intent_reference', 'bfa_provider_session_or_intent_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_funding_attempts');
    }
};
