<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §23, Receipt Boundary Correction Contract §9 — the Stripe-hosted
 * receipt mirror for a receipt-eligible funding credit. No UNIQUE or
 * convenience index beyond the two required FKs (contract §5/§N).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_billing_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('ledger_entry_id');
            $table->string('provider_receipt_url', 2048);
            $table->string('provider_reference', 191);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('ledger_entry_id')->references('id')->on('business_usage_ledger_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_billing_receipts');
    }
};
