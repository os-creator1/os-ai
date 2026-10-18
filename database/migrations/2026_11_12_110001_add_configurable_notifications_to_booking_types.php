<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar Notifications V2: a Booking Type's reminders are an ordered, unbounded
 * list of steps (each with its own timing, channels and wording) and the
 * confirmation wording is editable.
 *
 * ADDITIVE ONLY. `reminder_offsets` + `notify_email` / `notify_sms` stay and remain
 * the source for a Booking Type that never used the new editor (its steps are
 * derived from them), so no existing Booking Type changes behaviour.
 *
 *  - reminder_steps               [{id, offset_minutes, email, sms, enabled, email_subject, email_body, sms_body}]
 *  - confirmation_email_subject / confirmation_email_body / confirmation_sms_body
 *                                 NULL = the built-in wording
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->json('reminder_steps')->nullable();
            $table->string('confirmation_email_subject', 200)->nullable();
            $table->text('confirmation_email_body')->nullable();
            $table->text('confirmation_sms_body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->dropColumn(['reminder_steps', 'confirmation_email_subject', 'confirmation_email_body', 'confirmation_sms_body']);
        });
    }
};
