<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per managed message whose FINAL carrier cost (Telnyx's message
 * record: cost, completed_at) has been compared with what was billed to the
 * owning Business. Estimates are never the final word: the difference is
 * recorded here and settled explicitly, exactly once per message (the
 * unique operation id), on the Business the message belongs to.
 *
 * adjustment_micro: positive = the Business was under-billed (the carrier
 * cost more), negative = over-billed.
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
            $table->bigInteger('billed_micro');
            $table->bigInteger('carrier_cost_micro');
            $table->bigInteger('adjustment_micro');
            $table->string('status', 24);
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_cost_reconciliations');
    }
};
