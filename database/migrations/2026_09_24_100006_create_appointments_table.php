<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.4, Sub-slice A — the canonical Appointment.
 *
 * NO `appointment_transitions` TABLE, BY DECISION (§5.4, §10, §15). No
 * governing Slice 15 authority requires durable Appointment history, so V1
 * ships none. What that means, stated plainly rather than implied: the row
 * preserves CURRENT state plus `reschedule_count`, and the PREVIOUS
 * start_at/end_at/staff_user_id of each reschedule are NOT durably
 * queryable — a reschedule overwrites them and the AppointmentRescheduled
 * event carrying the old values is transient (RFC-002 §41: domain events
 * are a notification mechanism, not an audit log). Sub-slice C is not gated
 * on this and must not stop to ask.
 *
 * NO `timezone` column — derived via business_location_id -> business_id ->
 * business.timezone (§3.3). NO provider-specific columns of any kind: the
 * external busy-block cache (§5.6) is advisory only and this row's
 * authority never depends on it (§5.5's boundary rule).
 *
 * FOUR NOT NULL restrictOnDelete parents (Location, Booking Type, staff
 * User, Contact): an Appointment is an operational, audit-relevant record,
 * so each parent deliberately blocks deletion. That does mean a staff
 * member who has ever been booked cannot be hard-deleted until those rows
 * are dealt with — the correct trade for operational history, recorded in
 * §7.2 rather than discovered later, and deliberately unlike this schema's
 * pure lock/pivot rows which cascade.
 *
 * The two composite indexes are named for their query: the staff index
 * serves §7.6's cross-Location conflict check, the Location index serves
 * Sub-slice D's day/week calendar view.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_location_id')->constrained('business_locations')->restrictOnDelete();
            $table->foreignId('booking_type_id')->constrained('booking_types')->restrictOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('crm_opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->unsignedInteger('reschedule_count')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['staff_user_id', 'status', 'start_at', 'end_at'], 'appointments_staff_status_window_index');
            $table->index(['business_location_id', 'start_at', 'end_at'], 'appointments_location_window_index');
            $table->index('contact_id');
            $table->index('crm_opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
