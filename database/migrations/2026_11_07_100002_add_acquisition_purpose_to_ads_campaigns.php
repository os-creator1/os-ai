<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acquisition Purpose V1 — the explicit campaign -> purpose assignment.
 *
 * Both provider campaign tables are upserted on their natural provider key and
 * rows are kept (a vanished campaign is marked removed/gone, never deleted),
 * so a nullable foreign key on the canonical row is stable across syncs and
 * gives real referential integrity. The sync's upsert names its update
 * columns explicitly, so it can never overwrite this one.
 *
 * Explicit assignment is the ONLY authority: nothing infers a purpose from a
 * campaign name or copy. `nullOnDelete` leaves a campaign unassigned (never
 * orphaned) if its purpose is ever deleted. That the purpose belongs to the
 * same Business as the campaign is enforced by AcquisitionPurposeManager (a
 * SET NULL composite foreign key cannot null a NOT NULL business_id).
 */
return new class extends Migration
{
    private const TABLES = [
        'google_ads_campaigns' => 'gads_camp_purpose',
        'meta_ads_campaigns' => 'mads_camp_purpose',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $name) {
            Schema::table($table, function (Blueprint $t) use ($name): void {
                $t->unsignedBigInteger('acquisition_purpose_id')->nullable();
                $t->foreign('acquisition_purpose_id', $name . '_fk')->references('id')->on('acquisition_purposes')->nullOnDelete();
                $t->index('acquisition_purpose_id', $name . '_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $name) {
            Schema::table($table, function (Blueprint $t) use ($name): void {
                $t->dropForeign($name . '_fk');
                $t->dropIndex($name . '_idx');
                $t->dropColumn('acquisition_purpose_id');
            });
        }
    }
};
