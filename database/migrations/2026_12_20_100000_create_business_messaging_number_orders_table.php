<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable state for a carrier number order that was accepted but is not yet
 * finished (Telnyx `pending`) or whose outcome was never seen (timeout / 5xx).
 * It lets a scheduled reconciler finish the purchase with read-only requests,
 * and keeps the original quote, wallet reservation and purchase time so the
 * first paid month is never charged twice and nothing is re-ordered.
 *
 * Database guards (not application checks): at most ONE open order per
 * Business and per phone number, whatever races.
 */
return new class extends Migration
{
    private const OPEN = "'placing','processing','needs_attention'";

    public function up(): void
    {
        Schema::create('business_messaging_number_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->unsignedBigInteger('business_messaging_identity_id')->nullable();
            $table->string('phone_number', 32);
            $table->string('number_type', 16);
            $table->string('status', 24);
            $table->string('messaging_profile_id', 64)->nullable();
            $table->string('provider_order_id', 64)->nullable();
            $table->string('provider_phone_number_id', 64)->nullable();
            $table->unsignedBigInteger('usage_reservation_id')->nullable();
            $table->unsignedBigInteger('upfront_cost_micro')->nullable();
            $table->unsignedBigInteger('monthly_cost_micro')->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('open_business_guard')->nullable()->storedAs('CASE WHEN status IN (' . self::OPEN . ') THEN business_id ELSE NULL END');
            $table->string('open_phone_guard', 32)->nullable()->storedAs('CASE WHEN status IN (' . self::OPEN . ') THEN phone_number ELSE NULL END');

            $table->unique('open_business_guard', 'bmno_one_open_order_per_business');
            $table->unique('open_phone_guard', 'bmno_one_open_order_per_number');
            $table->index(['status', 'next_check_at']);
            $table->index('phone_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_number_orders');
    }
};
