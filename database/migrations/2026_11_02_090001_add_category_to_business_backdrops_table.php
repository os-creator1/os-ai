<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A backdrop is classified by the niche's own vocabulary (e.g. Photo Booth:
 * backdrop / booth setup / event / package example). The category is a
 * property of the canonical backdrop — not of one Website's copy of it — so
 * it lives on `business_backdrops`, where every consumer (Website sections,
 * future modules) reads the same value. Nullable: existing backdrops and
 * niches without a vocabulary have none. A short vocabulary KEY is stored,
 * never a display label, so relabelling the vocabulary never rewrites rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_backdrops', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('business_backdrops', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
