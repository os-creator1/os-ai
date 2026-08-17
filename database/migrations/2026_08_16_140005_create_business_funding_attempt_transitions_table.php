<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §17.C, M3 contract §21.4 — append-only transition history for
 * business_funding_attempts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_funding_attempt_transitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('funding_attempt_id');
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->string('source', 24);
            $table->unsignedBigInteger('provider_event_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('funding_attempt_id')->references('id')->on('business_funding_attempts')->restrictOnDelete();
            $table->foreign('provider_event_id')->references('id')->on('payment_provider_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_funding_attempt_transitions');
    }
};
