<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §18, M4 contract §18 — append-only transition history for
 * additional_business_slot_agreements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_business_slot_agreement_transitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agreement_id');
            $table->string('from_state', 20);
            $table->string('to_state', 20);
            $table->string('source', 24);
            $table->unsignedBigInteger('provider_event_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('agreement_id', 'absat_agreement_id_foreign')->references('id')->on('additional_business_slot_agreements')->restrictOnDelete();
            $table->foreign('provider_event_id', 'absat_provider_event_id_foreign')->references('id')->on('payment_provider_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_business_slot_agreement_transitions');
    }
};
