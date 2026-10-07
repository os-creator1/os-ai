<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website V1 closure — "Let search engines find these pages" must release only what the PLATFORM hid
 * (generated pages start noindex until the owner has read them), never a page the OWNER chose to hide.
 * `noindex` alone cannot say which of the two it is, so this one flag records an explicit owner choice:
 * set when the owner ticks "Hide from search engines" on a page, cleared when they untick it, never set
 * by generation. Additive, defaults to false.
 *
 * Existing rows: a noindex page that has been edited since it was created is treated as the owner's own
 * choice (the safe side — the bulk action will leave it alone); an untouched one stays releasable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_pages', function (Blueprint $table) {
            $table->boolean('noindex_explicit')->default(false)->after('noindex');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE website_pages SET noindex_explicit = 1 WHERE noindex = 1 AND TIMESTAMPDIFF(SECOND, created_at, updated_at) >= 2');
        }
    }

    public function down(): void
    {
        Schema::table('website_pages', function (Blueprint $table) {
            $table->dropColumn('noindex_explicit');
        });
    }
};
