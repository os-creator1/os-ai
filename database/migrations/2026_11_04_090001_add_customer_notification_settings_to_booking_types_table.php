<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Notifications V1 — the per-Booking-Type customer notification
 * settings the Booking Type editor edits. Three plain columns, each read by the
 * Calendar notification scheduler and nothing else:
 *
 *   notify_email      send the confirmation and reminders by email (default on)
 *   notify_sms        send them by text where the Business can actually text
 *                     and the guest consented on the booking form (default off:
 *                     texting needs a ready sender, so the owner opts in)
 *   reminder_offsets  minutes before the start, as a JSON list. NULL means "the
 *                     product defaults" (24 h and 2 h); an empty list means "no
 *                     reminders", which is a different, deliberate choice.
 *
 * Existing Booking Types get the column defaults, so a Business that already
 * takes public bookings starts confirming them by email without any action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->boolean('notify_email')->default(true)->after('slot_interval_minutes');
            $table->boolean('notify_sms')->default(false)->after('notify_email');
            $table->json('reminder_offsets')->nullable()->after('notify_sms');
        });
    }

    public function down(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->dropColumn(['notify_email', 'notify_sms', 'reminder_offsets']);
        });
    }
};
