<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Keyword Rank Tracking V1 — local cache of the provider's FREE location
 * catalogue. A rank-tracking search geography ("Chicago, Illinois, United
 * States") is a provider location, NOT a BusinessLocation, so it lives here
 * and never in business_locations. Caching it means choosing a city is a local
 * query: no provider call, no spend, and a typed string can only ever become a
 * paid request by resolving to a row in this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_rank_locations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->unsignedBigInteger('location_code');
            $table->string('location_name', 255);
            $table->unsignedBigInteger('parent_code')->nullable();
            $table->char('country_iso', 2);
            $table->string('location_type', 32);
            $table->timestamps();

            $table->unique(['provider', 'location_code'], 'seo_rank_loc_provider_code_unique');
            $table->index(['provider', 'country_iso', 'location_type', 'location_name'], 'seo_rank_loc_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rank_locations');
    }
};
