<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GBP Slice A contract §11.2 / §12 (C-2..C-5) — the BusinessLocation-owned
 * binding to exactly one Google location.
 *
 * ROLLBACK WARNING (contract §29.4): rolling back this migration destroys
 * every location binding and every mirrored profile. Bindings must be
 * re-selected by hand; nothing re-derives them.
 *
 * C-3 makes provider_location_resource_name UNIQUE PLATFORM-WIDE (no
 * tenant qualifier), so no two Businesses — in any Workspace — can claim
 * the same Google listing. Google location identity is global: every v1
 * method Slice A calls addresses locations/{id} with no account prefix.
 *
 * BOTH resource names are persisted (contract §8.4, correction A-2): the
 * account name is required by every future v4.9 surface, which addresses
 * accounts/{a}/locations/{l}, and re-deriving it later would need a full
 * re-enumeration under a grant that may have lapsed.
 *
 * There is deliberately NO street-address column (contract §23.4 point 4):
 * the private-address invariant is structural here, not conditional.
 * bound_locality_snapshot is a LOCALITY only.
 *
 * mirror_expires_at is always <= mirror_fetched_at + 30 calendar days
 * (contract §13.1, C-8). That is an application invariant asserted by test,
 * not a CHECK constraint — this repository's migrations use none and MySQL
 * support is version-dependent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Supporting index for the composite foreign key C-4 below.
        // business_locations has no (id, business_id) unique key of its
        // own; MySQL requires one on the referenced side.
        Schema::table('business_locations', function (Blueprint $table) {
            $table->unique(['id', 'business_id'], 'bl_id_business_unique');
        });

        Schema::create('business_google_locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_google_connection_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_location_id');
            $table->string('provider_account_resource_name', 191);
            $table->string('provider_location_resource_name', 191);
            $table->string('bound_title_snapshot', 191)->nullable();
            $table->string('bound_locality_snapshot', 120)->nullable();
            $table->char('bound_region_code_snapshot', 2)->nullable();
            $table->string('verification_state', 32)->nullable();
            $table->boolean('has_voice_of_merchant')->nullable();
            $table->boolean('has_pending_edits')->nullable();
            $table->string('open_status', 32)->nullable();
            $table->string('duplicate_of_resource_name', 191)->nullable();
            $table->json('profile_mirror')->nullable();
            $table->timestamp('mirror_fetched_at')->nullable();
            $table->timestamp('mirror_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('bound_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('business_location_id', 'bgl_business_location_unique');
            $table->unique('provider_location_resource_name', 'bgl_provider_location_unique');

            // C-4 — a binding's Business must equal its bound location's
            // Business. Deleting a BusinessLocation deletes its binding;
            // the connection survives (contract §29.5).
            $table->foreign(['business_location_id', 'business_id'], 'bgl_location_business_foreign')
                ->references(['id', 'business_id'])
                ->on('business_locations')
                ->onDelete('cascade');

            // C-5 — a binding's connection must belong to the same
            // Business. The cascade from `businesses` reaches this table
            // through business_google_connections.
            $table->foreign(['business_google_connection_id', 'business_id'], 'bgl_connection_business_foreign')
                ->references(['id', 'business_id'])
                ->on('business_google_connections')
                ->onDelete('cascade');

            $table->index('business_id', 'bgl_business_index');
            $table->index('business_google_connection_id', 'bgl_connection_index');
            $table->index('mirror_expires_at', 'bgl_mirror_expires_index');
            $table->index('last_synced_at', 'bgl_last_synced_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_google_locations');

        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropUnique('bl_id_business_unique');
        });
    }
};
