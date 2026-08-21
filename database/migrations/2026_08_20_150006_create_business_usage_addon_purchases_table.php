<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §18, M4 contract §10/§18/§21 — an add-on purchase's own charge
 * routes through business_funding_attempts (funding_attempt_id, unique FK)
 * rather than a parallel charge primitive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_usage_addon_purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('addon_key', 64);
            $table->bigInteger('price_micro');
            $table->unsignedBigInteger('funding_attempt_id');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('funding_attempt_id')->references('id')->on('business_funding_attempts')->restrictOnDelete();

            $table->unique('funding_attempt_id');
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_usage_addon_purchases');
    }
};
