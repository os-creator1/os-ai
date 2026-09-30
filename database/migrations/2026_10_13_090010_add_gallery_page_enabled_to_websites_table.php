<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — the automatic homepage-showcase-vs-full-
 * Gallery-page threshold (WebsitePageStrategy::galleryEligible(), based
 * on photo count) must be overridable by the owner, per the brief:
 * "Allow the owner to change this automatic choice later." NULL keeps
 * the existing automatic behavior; a true/false value pins it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('gallery_page_enabled')->nullable()->after('template_key');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('gallery_page_enabled');
        });
    }
};
