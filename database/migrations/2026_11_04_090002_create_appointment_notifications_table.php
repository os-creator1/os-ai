<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Notifications V1 — the durable ledger of every customer-facing
 * notification the Calendar owes an appointment: one row per
 * (appointment, channel, occurrence). It exists for three jobs the Calendar has
 * no other place for:
 *
 *   1. IDEMPOTENCY. UNIQUE(appointment_id, channel, occurrence_key) is the single
 *      guarantee that a confirmation or a reminder is owed, and sent, once. A
 *      booking replay, a redelivered event, a re-run sweep and a retried job all
 *      collide on it instead of sending twice.
 *   2. RECIPIENT HISTORY. The email, phone and SMS consent the guest gave AT
 *      BOOKING TIME are frozen on the row. Later Contact edits never rewrite who a
 *      scheduled reminder goes to, and the consent fact that allowed a text is
 *      provable (it is a transactional-booking consent, never marketing consent).
 *   3. HONEST OUTCOMES. status/reason record sent, skipped (and why) or failed, so
 *      "the customer was not texted because ..." is a fact, not a silence.
 *
 * `appointment_start_at` binds a row to the start it was scheduled for: a
 * reschedule leaves the old rows pointing at the old start, which is exactly how
 * they are recognised as obsolete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('business_location_id')->constrained('business_locations')->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();

            $table->string('kind', 16);            // confirmation | reminder
            $table->string('channel', 8);          // email | sms
            $table->string('occurrence_key', 96);  // confirmation | reminder:{offset}:{startUtc}
            $table->unsignedInteger('offset_minutes')->nullable();
            $table->dateTime('appointment_start_at');
            $table->dateTime('scheduled_for');

            $table->string('recipient_email', 190)->nullable();
            $table->string('recipient_phone', 32)->nullable();
            $table->dateTime('sms_consent_at')->nullable();

            $table->string('status', 16)->default('pending'); // pending|sending|sent|skipped|failed|cancelled
            $table->string('reason', 64)->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('attempted_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['appointment_id', 'channel', 'occurrence_key'], 'appt_notif_occurrence_unique');
            $table->index(['status', 'scheduled_for'], 'appt_notif_due_index');
            $table->index(['business_id', 'appointment_id'], 'appt_notif_business_appt_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_notifications');
    }
};
