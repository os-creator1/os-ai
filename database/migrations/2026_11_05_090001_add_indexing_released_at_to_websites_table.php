<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO V1 final — remembers that the owner has deliberately let search engines find their pages
 * ("Let search engines find these pages"). A rebuild keeps the rebuilt pages open once that has
 * happened, instead of silently re-hiding the whole site; a page the owner hid on purpose
 * (website_pages.noindex_explicit) stays hidden through it. A starter page that merely defaults to
 * indexable is NOT that act. NULL for every existing site: nothing changes until the owner acts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->timestamp('indexing_released_at')->nullable()->after('gallery_page_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('indexing_released_at');
        });
    }
};
