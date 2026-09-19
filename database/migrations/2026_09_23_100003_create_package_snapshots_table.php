<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 16 §5.3, Sub-slice A — the immutable transactional
 * record Slice 17 will consume. Mirrors `website_revisions`'s exact
 * write-once discipline: no `updated_at`, no soft delete, no status column.
 *
 * `business_location_id` is `restrictOnDelete`, NOT NULL — it records the
 * Location of the TRANSACTION itself, never merely "an override happened to
 * exist" (§5.3, corrected from an earlier mistaken nullable draft). Every
 * proposal/invoice/booking this snapshot serves occurs at a specific
 * Location, even when the Business-wide default price applied.
 *
 * `created_by_user_id` is nullable, `nullOnDelete` (corrected from an
 * earlier mistaken NOT NULL draft) — a User id for a staff/customer-portal
 * actor, NULL for a public/system transactional flow (a public self-booking
 * has no authenticated staff User to attribute the snapshot to; no fake
 * system User account is invented).
 *
 * `price_minor_at_snapshot`/`currency_code_at_snapshot` are NOT nullable —
 * a snapshot represents money that was actually used; `PackageSnapshotService`
 * (Sub-slice D) refuses to create a row without one resolved, never a null
 * or a silent zero.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('package_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->foreignId('business_location_id')->constrained('business_locations')->restrictOnDelete();
            $table->string('name_at_snapshot', 160);
            $table->text('description_at_snapshot')->nullable();
            $table->unsignedBigInteger('price_minor_at_snapshot');
            $table->char('currency_code_at_snapshot', 3);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('catalog_item_id');
            $table->index('business_id');
            $table->index('business_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_snapshots');
    }
};
