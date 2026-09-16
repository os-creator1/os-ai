<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 08B §5 — Location attribution for CRM
 * Opportunities (`crm_opportunities`, the genuine Location-bound
 * "Opportunity" operational record — see this slice's implementation
 * report for why the legacy `opportunities` table, the AI Opportunity-
 * detection engine, is NOT this contract's "Opportunity" resource and is
 * correctly excluded), mirroring `chat_boxes.location_id` exactly
 * (Contract 06).
 *
 * NULLABLE ON PURPOSE, same discipline: a deal whose Location cannot be
 * proven — a multi-Location Business, or a Business with no active
 * Location — stays NULL rather than being attributed to a guess.
 *
 * RESTRICT, not cascade, matching the existing Location FK precedent.
 */
return new class extends Migration
{
    private const INDEX = 'crm_opportunities_location_id_index';

    private const FOREIGN_KEY = 'crm_opportunities_location_id_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('crm_opportunities', 'location_id')) {
            Schema::table('crm_opportunities', function (Blueprint $table): void {
                $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            });
        }

        if (! Schema::hasIndex('crm_opportunities', self::INDEX)) {
            Schema::table('crm_opportunities', function (Blueprint $table): void {
                $table->index('location_id', self::INDEX);
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table('crm_opportunities', function (Blueprint $table): void {
                $table->foreign('location_id', self::FOREIGN_KEY)
                    ->references('id')->on('business_locations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('crm_opportunities', function (Blueprint $table): void {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        if (Schema::hasIndex('crm_opportunities', self::INDEX)) {
            Schema::table('crm_opportunities', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('crm_opportunities', 'location_id')) {
            Schema::table('crm_opportunities', function (Blueprint $table): void {
                $table->dropColumn('location_id');
            });
        }
    }

    private function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('crm_opportunities'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['name'] === self::FOREIGN_KEY);
    }
};
