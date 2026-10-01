<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — Backdrops, migration 1 of 2.
 *
 * A backdrop is a structured, repeatable, Business-owned offering, but it
 * is NOT a `CatalogItem`: `CatalogItemType` is `product|package` only, and
 * a backdrop carries no price in the Photobooth questionnaire's own field
 * list (name, image(s), description, availability, ordering) — it does
 * not fit CatalogItem's "sellable/priced item" purpose, so a new,
 * purpose-built table is justified rather than overloading an unrelated
 * model.
 *
 * Business-scoped (not Website-scoped) so a backdrop survives a website
 * rebuild or deletion, matching the same reasoning already applied to
 * `catalog_items`/`business_services` — real business facts never live
 * only inside a Website row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_backdrops', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('availability')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            // Idempotency key back to the questionnaire answer that created
            // this row, so re-running "Edit setup answers" updates the
            // existing backdrop instead of creating a duplicate.
            $table->string('source_questionnaire_item_key', 80)->nullable();

            $table->timestamps();

            $table->index(['business_id', 'position']);
            $table->index(['business_id', 'source_questionnaire_item_key'], 'bb_business_source_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_backdrops');
    }
};
