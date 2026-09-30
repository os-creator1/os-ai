<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — the wizard's package step needs a
 * featured/recommended flag, which `catalog_items` has never had, and
 * the same idempotency key already added to `business_backdrops` so
 * "Edit setup answers" updates the matching package rather than
 * duplicating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->boolean('featured')->default(false)->after('position');
            $table->string('source_questionnaire_item_key', 80)->nullable()->after('featured');
        });

        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->index(['business_id', 'source_questionnaire_item_key'], 'ci_business_source_item_index');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->dropIndex('ci_business_source_item_index');
            $table->dropColumn(['featured', 'source_questionnaire_item_key']);
        });
    }
};
