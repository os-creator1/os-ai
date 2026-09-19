<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.2, Sub-slice A — recurring weekly windows:
 * who is bookable, when, at which Location.
 *
 * Multiple rows per (staff_user_id, business_location_id, day_of_week) are
 * ALLOWED and expected — that is how a split shift is expressed. There is
 * deliberately no unique constraint on that triple.
 *
 * start_time/end_time are LOCAL TIME-OF-DAY in the Location's Business's
 * timezone (§3.3's business.timezone, resolved via
 * business_location.business), never converted to a fixed UTC offset at
 * write time, so a DST transition never corrupts a recurring rule. This
 * mirrors AnalyticsDateRange's own discipline; note business_locations
 * carries no timezone column of its own (§5.3).
 *
 * `restrictOnDelete` on BOTH foreign keys: an availability rule is an
 * operational, historically meaningful record, so unlike the pure lock and
 * pivot rows of this schema it deliberately does block deletion of the
 * staff User (§7.2's stated, accepted trade).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_location_id')->constrained('business_locations')->restrictOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(
                ['staff_user_id', 'business_location_id', 'day_of_week'],
                'staff_availability_rules_staff_location_day_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_availability_rules');
    }
};
