<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telnyx voice: durable record of whether a local number was CONFIRMED assigned to the configured Voice Connection.
 * voice_assigned_at is written only once Telnyx itself reports that connection on the number; a failure is recorded
 * as a reason code (never a provider body). All nullable and additive: existing numbers are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->string('voice_connection_id', 64)->nullable();
            $table->timestamp('voice_assigned_at')->nullable();
            $table->string('voice_assignment_failure_reason', 64)->nullable();
            // Bounded automatic retry: attempts made, and when the next attempt (or the in-flight lease) is due.
            $table->unsignedSmallInteger('voice_assignment_attempts')->default(0);
            $table->timestamp('voice_assignment_next_attempt_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->dropColumn(['voice_connection_id', 'voice_assigned_at', 'voice_assignment_failure_reason', 'voice_assignment_attempts', 'voice_assignment_next_attempt_at']);
        });
    }
};
