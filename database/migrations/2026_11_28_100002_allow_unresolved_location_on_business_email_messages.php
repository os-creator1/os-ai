<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound mail whose Location cannot be proven (no unique Contact/thread match
 * and a Business with several active Locations) is stored with a NULL
 * location instead of being filed under an arbitrary one. NULL here is NOT
 * "visible to everyone": BusinessEmailLocationScope shows such rows only to
 * actors who can reach every Location of the Business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_email_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows with an unresolved Location cannot satisfy NOT NULL; they are
        // inbound-only and re-created by the next sync, so they are removed.
        \Illuminate\Support\Facades\DB::table('business_email_messages')->whereNull('location_id')->delete();

        Schema::table('business_email_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });
    }
};
