<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Type scheduling settings. Each default equals what the public
 * scheduler hard-coded before (30 days ahead, no notice, no buffers, starts
 * every 30 minutes), so an existing Booking Type behaves exactly as it did.
 *
 * Buffers and notice are scheduling CONSTRAINTS only: an Appointment keeps its
 * real start/end, and timestamps stay UTC. `meeting_instructions` is free text
 * shown to the guest (where to go / how to join).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->unsignedSmallInteger('booking_window_days')->default(30)->after('duration_minutes');
            $table->unsignedInteger('minimum_notice_minutes')->default(0)->after('booking_window_days');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0)->after('minimum_notice_minutes');
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0)->after('buffer_before_minutes');
            $table->unsignedSmallInteger('slot_interval_minutes')->default(30)->after('buffer_after_minutes');
            $table->text('meeting_instructions')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->dropColumn([
                'booking_window_days', 'minimum_notice_minutes', 'buffer_before_minutes',
                'buffer_after_minutes', 'slot_interval_minutes', 'meeting_instructions',
            ]);
        });
    }
};
