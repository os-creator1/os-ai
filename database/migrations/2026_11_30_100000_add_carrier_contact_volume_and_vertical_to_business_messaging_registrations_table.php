<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carrier-required registration inputs the form never captured: the
 * business contact's first/last name (toll-free verification and 10DLC
 * brand), the expected monthly message volume and an opt-in screenshot URL
 * (toll-free verification), and the brand's industry vertical (10DLC
 * brand). All nullable: existing registrations stay valid and are asked
 * for these only if they are (re)submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table): void {
            $table->string('contact_first_name', 100)->nullable();
            $table->string('contact_last_name', 100)->nullable();
            $table->string('message_volume', 20)->nullable();
            $table->string('opt_in_image_url', 2083)->nullable();
            $table->string('brand_vertical', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table): void {
            $table->dropColumn(['contact_first_name', 'contact_last_name', 'message_volume', 'opt_in_image_url', 'brand_vertical']);
        });
    }
};
