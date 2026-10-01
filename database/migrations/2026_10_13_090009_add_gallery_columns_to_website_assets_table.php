<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — the wizard's gallery step needs visual
 * reordering, a single cover image, and optional per-photo title/
 * classification, none of which `website_assets` has ever needed before
 * (it was previously an unordered flat pool). "At most one cover" is
 * enforced at the application layer (WebsiteGalleryManager::setCover()),
 * matching this codebase's existing convention of not enforcing that
 * class of business rule at the DB layer (see catalog_items' own
 * price/currency co-nullable invariant, also application-enforced).
 *
 * `source_catalog_item_image_id` is the provenance link for the package-
 * image mirroring step (MediaBindingService::mirrorPackageImages()): it
 * lets a re-generate/rebuild find an already-mirrored asset by its
 * source rather than re-uploading a duplicate copy every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_assets', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('first_published_at');
            $table->boolean('is_cover')->default(false)->after('sort_order');
            $table->string('title', 160)->nullable()->after('is_cover');
            $table->string('category_tag', 80)->nullable()->after('title');
            $table->foreignId('source_catalog_item_image_id')->nullable()->after('category_tag')
                ->constrained('catalog_item_images')->nullOnDelete();

            $table->index(['website_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('website_assets', function (Blueprint $table): void {
            $table->dropIndex(['website_id', 'sort_order']);
            $table->dropForeign(['source_catalog_item_image_id']);
            $table->dropColumn(['sort_order', 'is_cover', 'title', 'category_tag', 'source_catalog_item_image_id']);
        });
    }
};
