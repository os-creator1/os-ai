<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations failed-send/retry (correction round 3, item 4) — the
 * minimum extra persisted claim metadata needed to tell a genuinely DEAD
 * retry claim from one that is merely still running.
 *
 * WHY THIS COLUMN. A retry claim moves `send_status` to the transient
 * 'sending' state, releases its row lock, and only THEN calls
 * buildManualSendInput()/quickSend()/the provider — deliberately outside
 * any open transaction (§4.9: no provider call is ever made inside one).
 * Between that lock release and ManagedMessageDispatcher::recordAttempt()
 * actually inserting the operation row, there is a real window where the
 * claim is 'sending' with no operation row yet — and is still completely
 * alive. Reconciling "no operation row" straight to failed/retryable
 * without knowing how OLD the claim is would let a second, genuinely
 * concurrent click land in that exact window and start a second provider
 * send — precisely the duplicate this whole feature exists to prevent.
 *
 * `send_claimed_at` is stamped the moment a claim sets `send_status` to
 * 'sending' (both the ordinary claim and the fresh reclaim reconciliation
 * itself performs). Reconciliation for a claim with NO operation row only
 * ever proceeds once this is old enough that the claiming request's own
 * synchronous execution (which every provider HTTP call still happens
 * inside of) could not plausibly still be running — a narrow, bounded
 * liveness check, and explicitly NOT the same thing as guessing a
 * genuinely AMBIGUOUS provider outcome from elapsed time: an operation
 * that exists but is still 'attempted' is never resolved by a clock, at
 * any age, anywhere in this feature.
 *
 * Nullable, nothing backfilled — every existing row (which never had a
 * 'sending' claim of its own) stays exactly as valid as it was.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_box_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('chat_box_messages', 'send_claimed_at')) {
                $table->timestamp('send_claimed_at')->nullable()->after('retry_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_box_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('chat_box_messages', 'send_claimed_at')) {
                $table->dropColumn('send_claimed_at');
            }
        });
    }
};
