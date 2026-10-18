<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citations V1 completion — turns the two-row reference list into the catalog
 * the dashboard needs, and lets a Business keep its own directories.
 *
 * seo_citation_directories (platform catalog + Business-owned custom rows):
 *  - website_url, category, icon, setup_guidance: presentation/product copy.
 *  - importance (essential|recommended|optional): the platform's DEFAULT
 *    guidance for the directory; a niche may override it per niche. It is
 *    product guidance, never an authority score.
 *  - tracking_mode (connected|automatic_check|assisted|manual): what Business
 *    OS can really do. Only the platform sets it; a custom directory is always
 *    `manual`. `connected` is reserved for the Google row, which is read from
 *    the GBP read model rather than stored here.
 *  - is_platform_core: shown to every Business (as opposed to appearing only
 *    where a niche recommends it or the Business already tracks it).
 *  - business_id (+ optional business_location_id): NON-NULL only for a
 *    Business's own custom directory. Platform rows keep it NULL, so a custom
 *    row can never be mistaken for a platform-certified source.
 *
 * seo_citations.listed_website: what the directory shows as the website, so it
 * can be compared where a directory stores one. Never required.
 *
 * Nothing is dropped or rewritten: existing citation rows and the two seeded
 * directories keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_citation_directories', function (Blueprint $table): void {
            $table->string('website_url', 2048)->nullable()->after('claim_url');
            $table->string('category', 32)->default('general')->after('website_url');
            $table->string('icon', 40)->nullable()->after('category');
            $table->string('importance', 16)->default('recommended')->after('icon');
            $table->string('tracking_mode', 24)->default('assisted')->after('importance');
            $table->string('setup_guidance', 500)->nullable()->after('tracking_mode');
            $table->boolean('is_platform_core')->default(false)->after('setup_guidance');
            $table->foreignId('business_id')->nullable()->after('is_platform_core')->constrained('businesses')->restrictOnDelete();
            $table->unsignedBigInteger('business_location_id')->nullable()->after('business_id');

            $table->index(['business_id', 'is_active'], 'seo_citation_directories_business_active_index');
            $table->foreign(['business_location_id', 'business_id'], 'seo_citation_directories_location_business_foreign')
                ->references(['id', 'business_id'])
                ->on('business_locations')
                ->restrictOnDelete();
        });

        // The citation row's claim URL is on the directory; a custom directory
        // may have none.
        Schema::table('seo_citation_directories', function (Blueprint $table): void {
            $table->string('claim_url', 2048)->nullable()->change();
        });

        Schema::table('seo_citations', function (Blueprint $table): void {
            $table->string('listed_website', 2048)->nullable()->after('listed_address');
        });
    }

    public function down(): void
    {
        Schema::table('seo_citations', function (Blueprint $table): void {
            $table->dropColumn('listed_website');
        });

        Schema::table('seo_citation_directories', function (Blueprint $table): void {
            $table->dropForeign('seo_citation_directories_location_business_foreign');
            $table->dropForeign(['business_id']);
            $table->dropIndex('seo_citation_directories_business_active_index');
            $table->dropColumn([
                'website_url', 'category', 'icon', 'importance', 'tracking_mode',
                'setup_guidance', 'is_platform_core', 'business_id', 'business_location_id',
            ]);
        });
    }
};
