<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo booth website setup — the booth services a business offers need what a
 * service never had: its OWN photographs, a website-visibility switch, and a
 * way to tell a booth experience apart from an event type.
 *
 * Minimal and non-destructive (nothing is dropped or rewritten):
 *  - `business_services.kind` ('service' default | 'event'): an event type
 *    ("Weddings") is a canonical service row too, but is presented as an event,
 *    never as a booth card or a service page. Every existing row stays
 *    'service', so nothing existing changes meaning.
 *  - `business_services.website_visible` (default true): hide a service from the
 *    website without deactivating it for the rest of MotionGrove.
 *  - `business_service_images`: a service's photos, owned by the SERVICE (not a
 *    Website), exactly like `catalog_item_images` and `business_backdrop_images`,
 *    so a rebuilt or deleted website never takes the owner's pictures with it.
 *    Images attach by the service's own id — never by matching its name.
 *  - `website_assets.source_business_service_image_id`: provenance link for the
 *    narrow derived copy a service photo needs on a Website (same idempotent
 *    mirror idea as `source_catalog_item_image_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_services', function (Blueprint $table): void {
            $table->string('kind', 16)->default('service')->after('slug');
            $table->boolean('website_visible')->default(true)->after('status');
        });

        Schema::create('business_service_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_service_id')->constrained('business_services')->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('mime_type', 64);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 160)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();

            $table->index(['business_service_id', 'position']);
        });

        Schema::table('website_assets', function (Blueprint $table): void {
            $table->foreignId('source_business_service_image_id')->nullable()->after('source_catalog_item_image_id')
                ->constrained('business_service_images')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('website_assets', function (Blueprint $table): void {
            $table->dropForeign(['source_business_service_image_id']);
            $table->dropColumn('source_business_service_image_id');
        });

        Schema::dropIfExists('business_service_images');

        Schema::table('business_services', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'website_visible']);
        });
    }
};
