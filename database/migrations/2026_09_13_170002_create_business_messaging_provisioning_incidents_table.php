<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #295 Correction Round 1, item 3 — the reconciliation seam for the
 * narrow window where a real Telnyx provider call has already succeeded
 * (a Messaging Profile, a number order, a partial multi-step provisioning
 * call) but this platform's own local finalization failed before the
 * result could be attached to a BusinessMessagingNumber. A row here means
 * "a provider resource may exist that this platform has not fully wired
 * up locally — check it manually"; it is never read by any normal
 * request path and never presented to the customer. Admin-visible only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_messaging_provisioning_incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('stage', 64);
            $table->string('messaging_profile_id', 191)->nullable();
            $table->string('provider_phone_number_id', 191)->nullable();
            $table->string('phone_number', 32)->nullable();
            $table->string('number_type', 16)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'bmpi_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->index('business_id', 'bmpi_business_index');
            $table->index('resolved_at', 'bmpi_resolved_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_provisioning_incidents');
    }
};
