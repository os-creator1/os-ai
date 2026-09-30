<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — Backdrops, migration 2 of 2.
 *
 * Same shape as `website_assets` for consistency (disk/path/mime_type/
 * size/dimensions/alt_text), but owned by the backdrop, not a Website —
 * a backdrop can carry more than one image (contract: "one or more
 * images").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_backdrop_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_backdrop_id')->constrained('business_backdrops')->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('mime_type', 64);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 160)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['business_backdrop_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_backdrop_images');
    }
};
