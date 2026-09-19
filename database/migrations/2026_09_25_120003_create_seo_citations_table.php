<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 18 §8.5, Sub-slice E — one row per (Location,
 * directory): the Business's own, USER-ASSERTED record of a listing.
 *
 * Nothing here is observed from a directory. `verification_source` is a
 * single-value column (`user_asserted`) precisely so a future vendor's
 * observations can never be written into this table without a contract
 * change. `last_verified_at` is a DATE the user typed. NAP consistency is a
 * read-time comparison and is never stored — there is no comparison column.
 *
 * business_location_id is NOT NULL and is protected by the composite FK
 * (business_location_id, business_id) -> business_locations(id, business_id)
 * (the existing bl_id_business_unique key), so a row can never pair a
 * Location with a different Business. Both FKs are RESTRICT: a Location's
 * lifecycle is archive, never delete, and a citation is customer-authored
 * workflow data (contract §8 preamble).
 *
 * unique(business_location_id, seo_citation_directory_id) is the database's
 * own guarantee of "one per (Location, directory)", independent of the
 * application.
 *
 * The private-address rule for `listed_address` is an application invariant
 * enforced by SeoCitationManager (and asserted by test), not a CHECK
 * constraint — this repository's migrations use none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_citations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->unsignedBigInteger('business_location_id');
            $table->foreignId('seo_citation_directory_id')->constrained('seo_citation_directories')->restrictOnDelete();
            $table->string('status', 24)->default('not_started');
            $table->string('listing_url', 2048)->nullable();
            $table->string('listed_name', 191)->nullable();
            $table->string('listed_phone', 50)->nullable();
            $table->string('listed_address', 255)->nullable();
            $table->date('last_verified_at')->nullable();
            $table->string('verification_source', 32)->default('user_asserted');
            $table->string('notes', 500)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_location_id', 'seo_citation_directory_id'], 'seo_citations_location_directory_unique');
            $table->index('business_id', 'seo_citations_business_index');
            $table->index('seo_citation_directory_id', 'seo_citations_directory_index');

            $table->foreign(['business_location_id', 'business_id'], 'seo_citations_location_business_foreign')
                ->references(['id', 'business_id'])
                ->on('business_locations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_citations');
    }
};
