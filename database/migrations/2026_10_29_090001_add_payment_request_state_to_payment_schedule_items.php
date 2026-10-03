<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 17B §3/§7 — the automatic balance payment request.
 *
 * Why these columns exist: the reminder sweep cannot carry a pay link (the
 * access-token plaintext is unrecoverable), so a Deposit + Balance document
 * needs ONE send-once email, at the balance item's frozen due_at, that mints a
 * fresh link. "Once" has to survive concurrent sweeps, queue replays and
 * provider failures, which needs durable per-item state — the same shape the
 * reminder markers (`reminder_last_sent_at` / `reminder_count`) already give
 * reminders, kept separate so the two sweeps can never consume each other's
 * window.
 *
 *  - payment_request_claimed_at: the sweep's atomic claim (with a lease, so a
 *    crashed worker's claim expires instead of blocking the item forever);
 *  - payment_request_sent_at: the delivery fact; once set the item is never
 *    selected again;
 *  - payment_request_failed_at: the last delivery failure (cleared on success);
 *  - payment_request_attempts: claims made so far; capped, so a permanently
 *    failing address cannot be retried (and its link rotated) forever.
 *
 * Progress markers only — no commercial term changes, so the frozen schedule
 * (sequence, kind, amount, due_at) is untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_document_payment_schedule_items', function (Blueprint $table): void {
            $table->timestamp('payment_request_claimed_at')->nullable()->after('reminder_last_sent_at');
            $table->timestamp('payment_request_sent_at')->nullable()->after('payment_request_claimed_at');
            $table->timestamp('payment_request_failed_at')->nullable()->after('payment_request_sent_at');
            $table->unsignedTinyInteger('payment_request_attempts')->default(0)->after('payment_request_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('business_document_payment_schedule_items', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_request_claimed_at',
                'payment_request_sent_at',
                'payment_request_failed_at',
                'payment_request_attempts',
            ]);
        });
    }
};
