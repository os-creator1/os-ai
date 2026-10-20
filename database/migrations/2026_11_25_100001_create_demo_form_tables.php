<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Platform Owner's "Book a Demo" form. One row per saved definition: at most one `draft`, any number of
        // immutable `published` rows (the newest is live; older ones stay so historical answers keep their context).
        Schema::create('demo_form_versions', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->index();
            $table->json('fields');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // What a prospect answered, frozen at booking time (label and value as they were), one row per appointment.
        Schema::create('demo_booking_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id')->unique();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('form_version_id')->nullable();
            $table->json('answers');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_booking_responses');
        Schema::dropIfExists('demo_form_versions');
    }
};
