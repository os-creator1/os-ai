<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — Packages images.
 *
 * `WebsiteAsset` is not reusable here: it `belongsTo(Website)` and would
 * vanish on a website rebuild/delete, which would recreate exactly the
 * "website-only copy of package data" problem the redesign brief
 * forbids, just inverted (the canonical CatalogItem would lose its image
 * the moment its owner rebuilds their site). A new, CatalogItem-owned
 * table is the correct home, mirroring `website_assets`'s own shape for
 * consistency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_item_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
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

            $table->index(['catalog_item_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_images');
    }
};
