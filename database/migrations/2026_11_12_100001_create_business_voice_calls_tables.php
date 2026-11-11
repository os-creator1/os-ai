<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telnyx Call Control admin test call — minimal call persistence.
 *
 * The destination is never stored: only `to_hash`, an HMAC keyed by the app key, so an operator can match a known
 * number to a row without the table holding a phone number. The caller ID is the Business's own number, referenced by
 * id rather than copied. Events are recorded by Telnyx event id (unique) so a redelivered webhook is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_voice_calls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_messaging_number_id');
            $table->string('telnyx_call_control_id', 191)->nullable()->unique();
            $table->char('to_hash', 64);
            $table->string('status', 16)->default('requested'); // requested|initiated|answered|ended|failed
            $table->string('failure_code', 64)->nullable();
            $table->string('hangup_cause', 64)->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'bvc_business_foreign')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('business_messaging_number_id', 'bvc_number_foreign')
                ->references('id')->on('business_messaging_numbers')->restrictOnDelete();
        });

        Schema::create('business_voice_call_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_event_id', 191)->unique();
            $table->unsignedBigInteger('business_voice_call_id');
            $table->string('event_type', 64);
            $table->boolean('applied')->default(false);
            $table->timestamp('received_at')->useCurrent();

            $table->foreign('business_voice_call_id', 'bvce_call_foreign')
                ->references('id')->on('business_voice_calls')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_voice_call_events');
        Schema::dropIfExists('business_voice_calls');
    }
};
