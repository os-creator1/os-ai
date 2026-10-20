<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-mediated outbound calling (correction round 1). Additive only.
 *
 * A browser's Telnyx credential must NOT be able to dial the phone network itself, so outbound calls are
 * placed by MotionGrove through a Call Control application, and the credential connection is verified to
 * have no outbound voice profile. These columns record the facts that gate "callable" and "inbound ready":
 *
 *  voice_numbers.call_control_app_id   BYO: the Agency's own Call Control application (managed uses config)
 *  voice_numbers.outbound_locked_at    when the credential connection was last confirmed unable to dial out
 *  voice_numbers.inbound_status        unverified | ready | misconfigured  (separate from outbound readiness)
 *  voice_numbers.inbound_error_code
 *  voice_calls.bridge_requested_at     the one-time bridge to the validated destination was asked for
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_numbers', function (Blueprint $table) {
            $table->string('call_control_app_id', 64)->nullable()->after('webrtc_connection_id');
            $table->timestamp('outbound_locked_at')->nullable()->after('voice_checked_at');
            $table->string('inbound_status', 16)->default('unverified')->after('outbound_locked_at');
            $table->string('inbound_error_code', 48)->nullable()->after('inbound_status');
        });

        Schema::table('voice_calls', function (Blueprint $table) {
            $table->timestamp('bridge_requested_at')->nullable()->after('answered_at');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropColumn('bridge_requested_at');
        });

        Schema::table('voice_numbers', function (Blueprint $table) {
            $table->dropColumn(['call_control_app_id', 'outbound_locked_at', 'inbound_status', 'inbound_error_code']);
        });
    }
};
