<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §18, M4 contract §18/§23 — append-only transition history for
 * additional_business_slot_renewal_charges. The sole durable source the
 * attempt-ordinal algorithm (M4 contract §23) reads: current ordinal = 1 +
 * count of already-recorded to_state = 'failed' rows for a given
 * renewal_charge_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_business_slot_renewal_charge_transitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('renewal_charge_id');
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->string('source', 24);
            $table->unsignedBigInteger('provider_event_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('renewal_charge_id', 'absrct_renewal_charge_id_foreign')->references('id')->on('additional_business_slot_renewal_charges')->restrictOnDelete();
            $table->foreign('provider_event_id', 'absrct_provider_event_id_foreign')->references('id')->on('payment_provider_events');

            $table->index(['renewal_charge_id', 'to_state'], 'absrct_renewal_charge_id_to_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_business_slot_renewal_charge_transitions');
    }
};
