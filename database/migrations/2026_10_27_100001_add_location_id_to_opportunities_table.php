<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Growth Center lane — `opportunities` gains ONE nullable column.
 *
 * WHY THIS COLUMN, AND WHY NOTHING ELSE. A Selected-Location staff member
 * must never see, count or be scored on an Opportunity for a Location they
 * cannot access (Contract 02 — filter first, aggregate second). That filter
 * has to run in SQL against the list/count queries, so the Location a
 * location-scoped Opportunity belongs to must be a real, indexable column;
 * parsing `context_key` per row would defeat the index.
 *
 * NULL means "Business-wide" — an Opportunity whose evidence is only
 * Business-level facts (for example "Website not published"). Every
 * pre-existing business_advisor Opportunity stays NULL: nothing is
 * backfilled and the Business Advisor queue is unaffected.
 *
 * Deliberately NOT added here (each already has a canonical home):
 *   - category / source module  -> OpportunityTypeRegistry metadata
 *   - rule version              -> the type key itself (`crm.x:v1`)
 *   - action started/completed  -> opportunity_action_executions
 *
 * ON DELETE SET NULL mirrors crm_opportunities.location_id; Locations are
 * archived, never hard-deleted, so this only guards a Business teardown.
 */
return new class extends Migration
{
    private const INDEX = 'opportunities_business_location_index';

    private const FOREIGN_KEY = 'opportunities_location_id_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('opportunities', 'location_id')) {
            Schema::table('opportunities', function (Blueprint $table): void {
                $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            });
        }

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->index(['business_id', 'location_id'], self::INDEX);
            $table->foreign('location_id', self::FOREIGN_KEY)
                ->references('id')->on('business_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropForeign(self::FOREIGN_KEY);
            $table->dropIndex(self::INDEX);
            $table->dropColumn('location_id');
        });
    }
};
