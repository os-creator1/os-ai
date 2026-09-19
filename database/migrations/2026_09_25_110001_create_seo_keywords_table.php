<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 18 §8.4 / Sub-slice D — SEO keywords: the search phrases a Business
 * wants customers to find it with. NOT the legacy inbound-SMS `keywords` table
 * (a different product: text-in keywords, purchased and assigned); nothing
 * here references, extends or shares anything with it.
 *
 * A keyword is Business-wide by default (business_location_id NULL) or carries
 * a Location as local-intent attribution. Invariants live in the database, not
 * only in application code:
 *
 *  - (business_location_id, business_id) is a composite FK to
 *    business_locations(id, business_id), reusing the existing
 *    bl_id_business_unique index, so a keyword can never pair a Location with
 *    a different Business. It is not enforced for NULL (MATCH SIMPLE), which
 *    is exactly the Business-wide case. restrictOnDelete: Locations are
 *    archived, never deleted.
 *  - location_key is a STORED generated column, COALESCE(location_id, 0), so
 *    the unique index below is a real backstop: a plain unique index treats
 *    every NULL as distinct and would allow the same Business-wide phrase many
 *    times.
 *  - unique(business_id, location_key, phrase_normalized) spans lifecycle
 *    states: an archived keyword still owns its phrase, and is reactivated
 *    rather than duplicated.
 *
 * lifecycle_state / archived_at are written only by SeoKeywordManager
 * (archive()/reactivate()); the model does not mass-assign them.
 *
 * No position, rank, score or coverage is stored: coverage is computed at read
 * time from the immutable published Website snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->unsignedBigInteger('business_location_id')->nullable();
            $table->string('phrase', 120);
            $table->string('phrase_normalized', 120);
            $table->unsignedBigInteger('location_key')->storedAs('COALESCE(business_location_id, 0)');
            $table->string('lifecycle_state', 16)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->string('source', 16)->default('manual');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'location_key', 'phrase_normalized'], 'seo_kw_business_location_phrase_unique');
            $table->index(['business_id', 'lifecycle_state'], 'seo_kw_business_state_index');

            $table->foreign(['business_location_id', 'business_id'], 'seo_kw_location_business_foreign')
                ->references(['id', 'business_id'])
                ->on('business_locations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_keywords');
    }
};
