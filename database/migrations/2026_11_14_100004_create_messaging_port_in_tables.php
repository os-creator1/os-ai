<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking for a number moving INTO managed messaging from another carrier (e.g. a HighLevel LC Phone number).
 * This table records and follows an order; it never creates or submits one. One live order per number across ALL
 * tenants (unique `active_phone_number`), so two Businesses can never claim the same incoming number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_port_in_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_location_id')->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->string('phone_number', 32);
            $table->string('provider', 16)->default('telnyx');
            $table->string('provider_order_id', 64)->nullable()->unique();
            $table->string('status', 16)->default('draft'); // draft|submitted|in_process|exception|foc_scheduled|ported|assigned|cancelled
            $table->string('provider_status', 48)->nullable();
            $table->timestamp('foc_at')->nullable();
            $table->timestamp('ported_at')->nullable();
            $table->unsignedBigInteger('assigned_number_id')->nullable();
            $table->json('checklist')->nullable();
            $table->string('active_phone_number', 32)->nullable()->unique();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->index(['business_id', 'status'], 'mpo_business_status_index');
        });

        Schema::create('messaging_port_in_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16)->default('telnyx');
            $table->string('provider_event_id', 96);
            $table->unsignedBigInteger('port_in_order_id')->nullable();
            $table->string('outcome', 24);
            $table->timestamp('received_at')->useCurrent();

            // Replayed or duplicated deliveries collapse onto this key.
            $table->unique(['provider', 'provider_event_id'], 'mpe_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_port_in_events');
        Schema::dropIfExists('messaging_port_in_orders');
    }
};
