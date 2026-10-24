<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per managed message being reconciled with Telnyx's FINAL message
 * record. The Business is charged the approved retail base for the actual
 * billable parts plus ONLY Telnyx's actual carrier surcharge; what it was
 * billed up front was an estimate, so the difference is settled exactly,
 * idempotently, on the Business the message belongs to.
 *
 * expected_micro: retail base x billable parts + actual surcharge.
 * billed_micro: what was billed up front (reservations for this message).
 * settled_micro: net adjustment already applied (positive = debited,
 *   negative = credited); revision counts applied settlements, so a later
 *   Telnyx billing adjustment is settled once more by its own delta.
 * status: pending (not final yet, retried with a bound), matched,
 *   settled, incident (needs an operator; never guessed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_cost_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('operation_id')->unique();
            $table->string('direction', 16);
            $table->string('provider_message_id', 191);
            $table->string('meter_key', 64)->nullable();
            $table->decimal('billable_parts', 12, 4)->nullable();
            $table->bigInteger('billed_micro')->default(0);
            $table->bigInteger('carrier_cost_micro')->default(0);
            $table->bigInteger('surcharge_micro')->default(0);
            $table->bigInteger('expected_micro')->default(0);
            $table->bigInteger('adjustment_micro')->default(0);
            $table->bigInteger('settled_micro')->default(0);
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('incident_code', 48)->nullable();
            $table->text('last_error')->nullable();
            $table->string('status', 24);
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index('status');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_cost_reconciliations');
    }
};
