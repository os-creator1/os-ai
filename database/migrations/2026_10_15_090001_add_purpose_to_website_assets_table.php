<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round 2 — a user-editable `category_tag`
 * was being overloaded as an ownership/purpose boundary between three
 * genuinely different kinds of Website-owned image: general gallery
 * photography, the wizard's optional custom-section media, and a
 * package's mirrored cover image. Every gallery query, the homepage
 * hero/image_text pool, and the Gallery page itself read ALL assets
 * regardless of this distinction, so a custom-section photo could end up
 * on the homepage, a package mirror could become the hero, and gallery
 * "remove" could delete a custom-section image or vice versa.
 *
 * `purpose` is a durable, non-user-editable classification set once at
 * creation (never through the title/category_tag form fields a customer
 * can edit) — the actual scoping boundary every consumer now filters on.
 * Backfilled from what each existing asset already implies: a mirrored
 * package image (source_catalog_item_image_id set) is `package_mirror`;
 * an asset already tagged for the custom-section step is `custom_section`;
 * everything else (every pre-existing asset, and every ordinary upload)
 * is `gallery` — the historical default behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_assets', function (Blueprint $table): void {
            $table->string('purpose', 20)->default('gallery')->after('source_catalog_item_image_id');
        });

        DB::table('website_assets')->whereNotNull('source_catalog_item_image_id')->update(['purpose' => 'package_mirror']);
        DB::table('website_assets')->where('category_tag', 'custom_section')->update(['purpose' => 'custom_section']);

        Schema::table('website_assets', function (Blueprint $table): void {
            $table->index(['website_id', 'purpose', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('website_assets', function (Blueprint $table): void {
            $table->dropIndex(['website_id', 'purpose', 'sort_order']);
            $table->dropColumn('purpose');
        });
    }
};
