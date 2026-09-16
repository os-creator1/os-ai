<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 08B §5 — Location attribution for Contacts,
 * mirroring `chat_boxes.location_id` exactly (Contract 06):
 * `2026_09_20_100007_add_location_id_to_chat_boxes_table.php`.
 *
 * NULLABLE ON PURPOSE, same reason `contacts.business_id` already is
 * (`2026_09_05_120001_add_nullable_business_id_to_tenancy_tables.php`): a
 * Contact whose Location cannot be proven — a multi-Location Business, a
 * Business with no active Location, or a legacy row with no Business at
 * all — stays NULL rather than being attributed to a guess.
 *
 * RESTRICT, not cascade, matching Contract 02/04/06's own Location FK
 * precedent: a Contact's Location attribution is audit-relevant and must
 * not vanish silently when a Location row is deleted.
 *
 * Column, index and foreign key are each guarded on their own, mirroring
 * the chat_boxes migration's own idempotent, replay-safe shape.
 */
return new class extends Migration
{
    private const INDEX = 'contacts_location_id_index';

    private const FOREIGN_KEY = 'contacts_location_id_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('contacts', 'location_id')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            });
        }

        if (! Schema::hasIndex('contacts', self::INDEX)) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->index('location_id', self::INDEX);
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->foreign('location_id', self::FOREIGN_KEY)
                    ->references('id')->on('business_locations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        if (Schema::hasIndex('contacts', self::INDEX)) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('contacts', 'location_id')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->dropColumn('location_id');
            });
        }
    }

    private function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('contacts'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['name'] === self::FOREIGN_KEY);
    }
};
