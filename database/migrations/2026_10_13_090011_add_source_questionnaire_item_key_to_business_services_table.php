<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — the wizard's "booth types"/"services" step
 * creates real BusinessService rows; this idempotency key lets "Edit
 * setup answers" update the matching service rather than duplicating it,
 * the same pattern already added to catalog_items and business_backdrops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_services', function (Blueprint $table): void {
            $table->string('source_questionnaire_item_key', 80)->nullable()->after('sort_order');
            $table->index(['business_id', 'source_questionnaire_item_key'], 'bs_business_source_item_index');
        });
    }

    public function down(): void
    {
        Schema::table('business_services', function (Blueprint $table): void {
            $table->dropIndex('bs_business_source_item_index');
            $table->dropColumn('source_questionnaire_item_key');
        });
    }
};
