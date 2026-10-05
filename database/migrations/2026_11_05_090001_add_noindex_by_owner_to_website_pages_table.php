<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO V1 final — tells a page the owner deliberately hid from search engines
 * apart from one hidden only because every generated page starts hidden.
 *
 * Without it the one-click "Let search engines find these pages" action had
 * to clear EVERY noindex page (including ones the owner hid on purpose), and a
 * rebuild had to re-hide everything. With it: the action releases only the
 * generated-default pages, a page the owner hid stays hidden through both, and
 * the owner's choice survives a rebuild.
 *
 * `websites.indexing_released_at` records the one deliberate act of letting
 * search engines find the generated pages (the Pages screen's button): a rebuild
 * keeps the pages open once the owner has done that, instead of re-hiding the
 * whole site. A starter page that merely defaults to indexable is NOT that act.
 *
 * Existing rows default to false / NULL: today's generated pages stay releasable
 * and nothing changes for a site until its owner acts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_pages', function (Blueprint $table): void {
            $table->boolean('noindex_by_owner')->default(false)->after('noindex');
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->timestamp('indexing_released_at')->nullable()->after('gallery_page_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('indexing_released_at');
        });

        Schema::table('website_pages', function (Blueprint $table): void {
            $table->dropColumn('noindex_by_owner');
        });
    }
};
