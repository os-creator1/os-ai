<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-defined old-address redirects ("/brochure" -> "/"), frozen into each published revision's `redirects`
 * map next to the automatic rename redirects. Additive and nullable: existing websites are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('custom_redirects')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('custom_redirects');
        });
    }
};
