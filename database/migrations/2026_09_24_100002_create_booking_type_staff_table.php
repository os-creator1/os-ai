<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.1, Sub-slice A — the pivot naming which
 * staff members a Booking Type offers, and therefore the round-robin pool
 * (§7.3).
 *
 * CONFIGURATION INTENT, NEVER AUTHORIZATION. A row here means only "an
 * authorized configurer nominated this staff member for this Booking Type".
 * It is never, at any point, proof that the staff member may currently be
 * scheduled at that Location — §6's eligibility rule re-derives that from
 * LocationAccessGuard at both configuration time and booking time, and a
 * stale row here never restores access.
 *
 * BOTH foreign keys cascade, and both for the same reason: this row carries
 * no independent audit value. Once the Booking Type is gone the nomination
 * is meaningless, and a deleted staff User must neither remain attached nor
 * block a legitimate User deletion merely because the nomination once
 * existed (§5.1). This is unlike appointments/staff_availability_rules/
 * staff_time_off, whose staff_user_id stays restrictOnDelete because those
 * rows ARE historically meaningful (§7.2).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_type_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_type_id')->constrained('booking_types')->cascadeOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['booking_type_id', 'staff_user_id'], 'booking_type_staff_type_user_unique');
            $table->index('staff_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_type_staff');
    }
};
