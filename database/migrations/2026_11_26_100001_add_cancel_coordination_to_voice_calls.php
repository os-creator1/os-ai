<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancel / bridge coordination (correction round 2). Additive only.
 *
 *  cancel_requested_at         the user (or the system) asked to cancel; once set, no bridge may be dispatched
 *  bridge_dispatched_at        set under the row lock immediately before the transfer request is sent; a
 *                              cancel that finds this NULL has definitively won (no transfer will ever be sent)
 *  customer_call_control_id    the customer leg of the session, when Telnyx has told us about it
 *  leg_teardown_status         NULL (nothing owed) | pending | done | failed — provider legs still to hang up
 *  leg_teardown_attempts       provider hangup attempts made (bounded retry)
 *  leg_teardown_error          stable reason of the last failed attempt (never a raw provider body)
 *  leg_teardown_at             last attempt
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('bridge_requested_at');
            $table->timestamp('bridge_dispatched_at')->nullable()->after('cancel_requested_at');
            $table->string('customer_call_control_id', 128)->nullable()->after('provider_leg_id');
            $table->string('leg_teardown_status', 16)->nullable()->after('client_reported_ended_at');
            $table->unsignedTinyInteger('leg_teardown_attempts')->default(0)->after('leg_teardown_status');
            $table->string('leg_teardown_error', 48)->nullable()->after('leg_teardown_attempts');
            $table->timestamp('leg_teardown_at')->nullable()->after('leg_teardown_error');
            $table->index(['leg_teardown_status', 'leg_teardown_attempts'], 'voice_calls_teardown_idx');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropIndex('voice_calls_teardown_idx');
            $table->dropColumn(['cancel_requested_at', 'bridge_dispatched_at', 'customer_call_control_id', 'leg_teardown_status', 'leg_teardown_attempts', 'leg_teardown_error', 'leg_teardown_at']);
        });
    }
};
