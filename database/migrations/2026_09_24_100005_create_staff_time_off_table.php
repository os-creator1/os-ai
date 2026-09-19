<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.3, Sub-slice A — availability exceptions.
 *
 * DELIBERATELY USER-GLOBAL: there is NO business_location_id column here,
 * and its absence is the design, not an oversight. Blueprint §12 frames
 * availability as "who is bookable, when" — a property of the staff member —
 * so a staff member on time off is unavailable at EVERY Location they are
 * otherwise granted, consistent with §7's cross-Location conflict
 * prevention. §14's acceptance wording states this exception outright
 * rather than implying a Location column that does not exist; Addendum §5's
 * enumeration covers "operational staff assignment WHERE APPLICABLE", and an
 * absence is not an assignment.
 *
 * Location-wide closures are out of this schema entirely (§5.3):
 * business_locations.hours is Business Knowledge Profile content with no
 * scheduling consumer anywhere in app/, so staff_availability_rules plus
 * this table are the sole booking authority.
 *
 * start_at/end_at are UTC timestamps (§5's opening rule).
 * `restrictOnDelete` on staff_user_id — a historically meaningful
 * operational record (§7.2). `nullOnDelete` on created_by_user_id — the
 * ordinary actor-column posture.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_time_off', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_user_id', 'start_at', 'end_at'], 'staff_time_off_staff_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_time_off');
    }
};
