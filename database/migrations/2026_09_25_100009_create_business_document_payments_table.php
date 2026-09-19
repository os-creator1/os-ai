<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.9, Sub-slice A — one row per payment ATTEMPT
 * against a schedule item, on the Business's own connected Stripe account.
 * Lane B only (§4).
 *
 * ONE ACTIVE ATTEMPT PER SCHEDULE ITEM (§7.2). `active_schedule_item_id` is a
 * STORED generated column that yields the schedule_item_id only while the
 * attempt is LIVE (created / requires_action / processing) and NULL once it is
 * terminal (succeeded / failed / canceled), with a UNIQUE key. Two concurrent
 * first clicks therefore cannot both hold a live row for one item, and a
 * terminal failure frees the slot for exactly one new deliberate attempt.
 * `schedule_item_id` is RESTRICT — it is the base column of that generated
 * column, where MySQL forbids CASCADE/SET NULL.
 *
 * PROVIDER IDENTITY, not provider secrets. Durable identity is
 * uid + provider_payment_intent_id + business_stripe_connection_id (§7.2.1).
 * There is DELIBERATELY NO client_secret column, and never will be: a
 * PaymentIntent client_secret is transient browser material (§7.2.1, §11.8).
 * There are equally no card/PAN/CVC/payment-method columns — card data is
 * collected only by Stripe.js and never reaches this application.
 *
 * `local_idempotency_key` is `document-payment:{uid}` (§8.1), stored under a
 * TENANT-SCOPED unique key (business_id, key). provider_payment_intent_id and
 * provider_charge_id are unique when populated (NULLs coexist), so a replay
 * can never create a second payment.
 *
 * `status` is OUR local vocabulary, mapped from Stripe's in one gateway seam
 * (§11.8) — never a provider status string.
 *
 * `business_stripe_connection_id` keeps its exact historical value forever
 * (§5.7); it is RESTRICT.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_document_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_document_id');
            $table->unsignedBigInteger('schedule_item_id');
            $table->unsignedBigInteger('business_stripe_connection_id');
            $table->string('local_idempotency_key', 191);
            $table->string('provider_payment_intent_id', 191)->nullable();
            $table->string('provider_charge_id', 191)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('created');
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('receipt_sent_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'bdp_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('business_document_id', 'bdp_document_foreign')
                ->references('id')->on('business_documents')->restrictOnDelete();
            $table->foreign('schedule_item_id', 'bdp_schedule_item_foreign')
                ->references('id')->on('business_document_payment_schedule_items')->restrictOnDelete();
            $table->foreign('business_stripe_connection_id', 'bdp_connection_foreign')
                ->references('id')->on('business_stripe_connections')->restrictOnDelete();

            $table->unique(['business_id', 'local_idempotency_key'], 'bdp_business_key_unique');
            $table->unique('provider_payment_intent_id', 'bdp_intent_unique');
            $table->unique('provider_charge_id', 'bdp_charge_unique');
            $table->index(['business_document_id', 'status'], 'bdp_document_status_index');
            $table->index(['schedule_item_id', 'status'], 'bdp_item_status_index');
        });

        Schema::table('business_document_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('active_schedule_item_id')
                ->nullable()
                ->storedAs("CASE WHEN status IN ('created','requires_action','processing') THEN schedule_item_id ELSE NULL END")
                ->after('status');
        });

        Schema::table('business_document_payments', function (Blueprint $table) {
            $table->unique('active_schedule_item_id', 'bdp_active_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_payments');
    }
};
