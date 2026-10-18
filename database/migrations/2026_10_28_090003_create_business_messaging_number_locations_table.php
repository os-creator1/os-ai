<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging — which Locations a managed number is USED BY.
 *
 * A Business has one managed identity and (in V1) one primary number, and every
 * Location of the Business texts through it. That is fine until something has to
 * PROVE a text speaks for one particular Location — a workflow limited to a
 * Location, say. Until now the number was Business-level with no Location at all,
 * so nothing could prove it, and Location-limited Automations could not text.
 *
 * One row = "this number is used by this Location". Written only through the
 * Text messaging settings, by someone who reaches every Location, and read by the
 * managed dispatcher when a send carries a Location context. A number with NO rows
 * is the Business-level number it always was: it keeps serving a Business-wide
 * workflow and a Business with a single Location unchanged.
 *
 * A pivot, not a column on the number: one number routinely serves several
 * Locations, and a single `business_location_id` could only say "one".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_messaging_number_locations')) {
            return;
        }

        Schema::create('business_messaging_number_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_messaging_number_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_location_id');
            $table->timestamps();

            $table->unique(['business_messaging_number_id', 'business_location_id'], 'bmnl_number_location_unique');
            $table->index('business_location_id', 'bmnl_location_index');
            $table->index('business_id', 'bmnl_business_index');

            $table->foreign('business_messaging_number_id', 'bmnl_number_foreign')
                ->references('id')->on('business_messaging_numbers')->cascadeOnDelete();
            $table->foreign('business_location_id', 'bmnl_location_foreign')
                ->references('id')->on('business_locations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_number_locations');
    }
};
