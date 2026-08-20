<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §18, M4 contract §18 — append-only transition history for
 * business_usage_addon_purchases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_usage_addon_purchase_transitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->string('source', 24);
            $table->unsignedBigInteger('provider_event_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('purchase_id')->references('id')->on('business_usage_addon_purchases')->restrictOnDelete();
            $table->foreign('provider_event_id', 'buapt_provider_event_id_foreign')->references('id')->on('payment_provider_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_usage_addon_purchase_transitions');
    }
};
