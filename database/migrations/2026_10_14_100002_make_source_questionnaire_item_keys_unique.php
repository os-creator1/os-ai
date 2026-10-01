<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round — the three `(business_id,
 * source_questionnaire_item_key)` indexes added earlier this lane were
 * plain, not unique, so the "idempotent by source key" claim
 * (WebsiteSetupAnswerApplier findBySourceKey()-then-update-or-create) was
 * never actually protected against two concurrent submissions racing
 * past the find and both inserting. A composite UNIQUE index closes that:
 * MySQL treats each NULL in `source_questionnaire_item_key` as distinct
 * for uniqueness purposes, so any number of manually-created rows
 * (source key NULL) for one Business remain unaffected — only two rows
 * that would share the SAME non-null key for the SAME Business are ever
 * refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->dropIndex('ci_business_source_item_index');
            $table->unique(['business_id', 'source_questionnaire_item_key'], 'ci_business_source_item_unique');
        });

        Schema::table('business_services', function (Blueprint $table): void {
            $table->dropIndex('bs_business_source_item_index');
            $table->unique(['business_id', 'source_questionnaire_item_key'], 'bs_business_source_item_unique');
        });

        Schema::table('business_backdrops', function (Blueprint $table): void {
            $table->dropIndex('bb_business_source_item_index');
            $table->unique(['business_id', 'source_questionnaire_item_key'], 'bb_business_source_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->dropUnique('ci_business_source_item_unique');
            $table->index(['business_id', 'source_questionnaire_item_key'], 'ci_business_source_item_index');
        });

        Schema::table('business_services', function (Blueprint $table): void {
            $table->dropUnique('bs_business_source_item_unique');
            $table->index(['business_id', 'source_questionnaire_item_key'], 'bs_business_source_item_index');
        });

        Schema::table('business_backdrops', function (Blueprint $table): void {
            $table->dropUnique('bb_business_source_item_unique');
            $table->index(['business_id', 'source_questionnaire_item_key'], 'bb_business_source_item_index');
        });
    }
};
