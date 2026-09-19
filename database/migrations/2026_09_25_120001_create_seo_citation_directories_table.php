<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 18 §8.5, Sub-slice E — the platform-owned reference
 * list of directories a Business can track a listing in.
 *
 * GLOBAL reference data: no business_id, no customer write path. Rows change
 * only by migration/seeder (see the seed migration that follows) or a future
 * platform-admin surface — never by a customer. `key` is a stable slug that
 * survives renames of `name`; `claim_url` is the directory's own https claim
 * page; `country_scope` is a nullable ISO-3166 alpha-2 (NULL = global).
 * Nothing here is fetched: the platform never calls a directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_citation_directories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('key', 64);
            $table->string('name', 120);
            $table->string('claim_url', 2048);
            $table->char('country_scope', 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('key', 'seo_citation_directories_key_unique');
            $table->index(['is_active', 'sort_order'], 'seo_citation_directories_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_citation_directories');
    }
};
