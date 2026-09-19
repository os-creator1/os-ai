<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 16 §5.2, Sub-slice A — sparse Location-override
 * rows only. No row for a given (catalog_item_id, business_location_id)
 * pair means the item is enabled at every ACL-authorized Location at the
 * Business-wide default price — this contract's locked V1 implementation
 * default (§5.2), mirroring `workspace_membership_locations`'s own
 * explicit-grants-only pivot philosophy.
 *
 * `cascadeOnDelete` on `catalog_item_id` (this row has no independent
 * meaning once the catalog item is gone) but `restrictOnDelete` on
 * `business_location_id`, matching every other FK to `business_locations`
 * in this schema (`contacts.location_id`/`chat_boxes.location_id`
 * precedent, §3.3).
 *
 * No `currency_code_override` column — a Location's price override
 * changes the amount only, never the currency (§5.2).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog_item_location_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
            $table->foreignId('business_location_id')->constrained('business_locations')->restrictOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('price_minor_override')->nullable();
            $table->timestamps();

            $table->unique(['catalog_item_id', 'business_location_id'], 'catalog_item_overrides_item_location_unique');
            $table->index('business_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_location_overrides');
    }
};
