<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §17.B, M3 contract §21.2 — the composite FK
 * (provider_customer_id, provider) -> payment_provider_customers(id, provider)
 * makes a provider-mismatched instrument a schema-level impossibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_payment_instruments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('provider_customer_id');
            $table->string('provider', 16)->default('stripe');
            $table->string('provider_payment_method_id', 191);
            $table->string('type', 24)->default('card');
            $table->string('brand', 24)->nullable();
            $table->string('last_four', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('detached_at')->nullable();

            $table->foreign(['provider_customer_id', 'provider'], 'bpi_provider_customer_foreign')
                ->references(['id', 'provider'])
                ->on('payment_provider_customers');

            $table->unique(['provider', 'provider_payment_method_id'], 'bpi_provider_payment_method_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payment_instruments');
    }
};
