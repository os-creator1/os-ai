<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_types', function (Blueprint $table) {
            // null = the type's behaviour before this feature (nothing changes). Otherwise zoom | in_person | phone | custom.
            $table->string('meeting_location_type', 16)->nullable();
            $table->string('meeting_location_custom', 500)->nullable();
            // Null = the staff member assigned to each booking hosts it. Set = one explicitly authorized shared host.
            $table->unsignedBigInteger('zoom_host_connection_id')->nullable();
        });

        Schema::table('appointments', function (Blueprint $table) {
            // Snapshot at booking time (type default or a per-appointment choice): later edits to the type never rewrite it.
            $table->string('meeting_location_type', 16)->nullable();
            $table->string('meeting_location_custom', 500)->nullable();

            $table->unsignedBigInteger('zoom_connection_id')->nullable();   // the host connection the meeting was created under
            $table->string('zoom_status', 16)->nullable();                   // null | pending | ready | failed | cancelled
            $table->string('zoom_meeting_id', 32)->nullable();
            $table->text('zoom_join_url')->nullable();                       // the participant join_url ONLY
            $table->timestamp('zoom_synced_start_at')->nullable();           // the start the Zoom meeting currently has
            $table->unsignedSmallInteger('zoom_synced_duration')->nullable();
            $table->unsignedSmallInteger('zoom_attempts')->default(0);
            $table->string('zoom_last_error', 64)->nullable();
            $table->index('zoom_status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['zoom_status']);
            $table->dropColumn(['meeting_location_type', 'meeting_location_custom', 'zoom_connection_id', 'zoom_status', 'zoom_meeting_id', 'zoom_join_url', 'zoom_synced_start_at', 'zoom_synced_duration', 'zoom_attempts', 'zoom_last_error']);
        });

        Schema::table('booking_types', function (Blueprint $table) {
            $table->dropColumn(['meeting_location_type', 'meeting_location_custom', 'zoom_host_connection_id']);
        });
    }
};
