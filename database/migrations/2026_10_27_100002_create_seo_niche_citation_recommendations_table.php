<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citations V1 — which directories a niche recommends.
 *
 * Why its own table (not a Niche Blueprint component): a Blueprint installs by
 * COPYING its components into a Business once, so a later edit would never
 * reach existing Businesses. A recommendation must apply to every Business of
 * the niche the moment the Platform Owner changes it, so it is a live lookup
 * keyed on the same niche key the Website questionnaire and templates already
 * use: businesses.industry (App\Enums\Business\BusinessIndustry).
 *
 * A row REFERENCES a catalog directory; it never duplicates one, and it cannot
 * change the directory's website, claim URL or automation capability — those
 * belong to the platform catalog. It may only override importance, order and
 * add short niche-specific guidance. No Business-state rows are created: a
 * recommended directory with no seo_citations row simply renders "Needs setup".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_niche_citation_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('niche_key', 64);
            $table->unsignedBigInteger('seo_citation_directory_id');
            $table->string('importance', 16)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('guidance', 500)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['niche_key', 'seo_citation_directory_id'], 'seo_niche_citation_recs_niche_directory_unique');
            $table->index(['niche_key', 'is_enabled', 'sort_order'], 'seo_niche_citation_recs_lookup_index');
            $table->foreign('seo_citation_directory_id', 'seo_niche_citation_recs_directory_foreign')
                ->references('id')->on('seo_citation_directories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_niche_citation_recommendations');
    }
};
