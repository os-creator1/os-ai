<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §21, M3 contract §21.5 — the corrected, uniformly-bounded
 * claim/lease/exhaustion/disposition algorithm's own table.
 * payload_encrypted uses Laravel's encrypted cast (App\Models\PaymentProviderEvent)
 * — first real use of that cast in this repository. Created before
 * business_funding_attempt_transitions, which nullably references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16)->default('stripe');
            $table->string('provider_event_id', 191);
            $table->string('event_type', 64);
            $table->string('provider_object_id', 191);
            $table->longText('payload_encrypted')->nullable();
            $table->string('payload_hash', 64);
            $table->timestamp('payload_purged_at')->nullable();
            $table->string('state', 16)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('disposed_at')->nullable();
            $table->unsignedBigInteger('disposed_by_user_id')->nullable();
            $table->text('disposition_note')->nullable();

            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_events');
    }
};
