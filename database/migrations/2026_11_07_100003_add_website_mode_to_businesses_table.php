<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * External Website Audit Mode V1 — the Business's PRIMARY WEBSITE SOURCE.
 *
 *   NULL      not chosen yet (the first Website screen asks)
 *   hosted    the primary site is built and hosted by this platform
 *   external  the primary site is the owner's own, at `businesses.website_url`
 *   none      the owner chose "do this later"
 *
 * Only the CHOICE is stored here. The URL stays where it always was
 * (`businesses.website_url` — the one canonical URL authority, with its
 * `canonical_domain`), and the hosted Website keeps its own tables. The mode
 * is deliberately about the PRIMARY site and not exclusive: a Business whose
 * primary site is external may later still own platform-hosted campaign
 * landing pages.
 *
 * Existing Businesses that already have a Website row are backfilled to
 * `hosted` so their Website experience does not change at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('website_mode', 16)->nullable();
        });

        DB::table('businesses')
            ->whereIn('id', DB::table('websites')->select('business_id'))
            ->update(['website_mode' => 'hosted']);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('website_mode');
        });
    }
};
