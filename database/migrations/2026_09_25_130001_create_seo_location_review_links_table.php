<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 18 §8.6, Sub-slice F — one MANUAL review link per
 * Location.
 *
 * A link the Business pasted in: nothing here is fetched, checked, copied
 * from Google or clicked-through. There is no redirect, short-link or
 * click-tracking column or endpoint (open-redirect rule, GBP §17). The
 * "effective" link shown to a user is computed at read time — this manual
 * link, else the unexpired Google mirror's review URI through the GBP read
 * model — and the Google value is never copied into this table.
 *
 * unique(business_location_id) is the database's own "one per Location". The
 * composite FK (business_location_id, business_id) -> business_locations
 * (id, business_id) — the existing bl_id_business_unique key — means a link
 * can never pair a Location with a different Business. RESTRICT: a Location's
 * lifecycle is archive, never delete; this is customer-authored data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_location_review_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->unsignedBigInteger('business_location_id');
            $table->string('review_url', 2048);
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('business_location_id', 'seo_review_links_location_unique');
            $table->index('business_id', 'seo_review_links_business_index');

            $table->foreign(['business_location_id', 'business_id'], 'seo_review_links_location_business_foreign')
                ->references(['id', 'business_id'])
                ->on('business_locations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_location_review_links');
    }
};
