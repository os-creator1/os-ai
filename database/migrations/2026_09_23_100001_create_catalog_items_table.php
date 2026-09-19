<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 16 §5.1, Sub-slice A — the canonical, Business-
 * wide Packages & Products catalog. One row per catalog item; no per-
 * Location duplication (that is `catalog_item_location_overrides`, the
 * next migration). `type` is display/categorization only — no bundling
 * relationship (§5.1's own naming note).
 *
 * `price_minor`/`currency_code` are both nullable — a catalog item MAY be
 * a fully custom/quote-only offering with no fixed base price
 * (`BusinessService.starting_price` is nullable for the same reason). The
 * co-nullable invariant (both null, or both set) is enforced at the
 * application layer by Sub-slice B's `CatalogItemManager`, never at the DB
 * layer here — mirroring `workspace_plan_catalog`'s identical precedent.
 *
 * `lifecycle_state`/`archived_at` mirror `business_locations` exactly:
 * string-backed state plus a nullable timestamp, both NOT fillable on the
 * model (enforced in the Eloquent model, not here) — the only write path is
 * Sub-slice B's `archive()`/`reactivate()`, never ordinary mass-assignment.
 *
 * Every money column is `unsignedBigInteger`, matching the actual
 * `CrmOpportunity.value_minor` migration precedent — never a signed
 * `bigInteger`, since no document authorizes a negative sale price.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('lifecycle_state', 16)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'lifecycle_state']);
            $table->index(['business_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
