<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Agency AI Prospecting runtime pass — `booking_url` is the single
     * server-controlled URL the responder is ever allowed to send (the AI
     * may decide WHEN to send it, never invent the URL itself).
     * `follow_up_delay_hours` bounds the one-time delayed follow-up after
     * a booking link is sent; defaults to the previously-established
     * useful behavior (24 hours).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('agency_prospecting_settings', function (Blueprint $table) {
                $table->string('booking_url', 2048)->nullable();
                $table->unsignedSmallInteger('follow_up_delay_hours')->default(24);
            });
        }

        public function down(): void
        {
            Schema::table('agency_prospecting_settings', function (Blueprint $table) {
                $table->dropColumn(['booking_url', 'follow_up_delay_hours']);
            });
        }
    };
