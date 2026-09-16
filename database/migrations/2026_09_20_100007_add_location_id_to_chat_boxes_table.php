<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 06 §5 — Location attribution for Conversations.
 *
 * `chat_boxes` gains a nullable `location_id`, shaped exactly like the
 * `business_id` this table already carries
 * (2026_09_14_100001_add_business_id_to_chat_boxes.php): nullable, indexed,
 * and restrict-on-delete.
 *
 * NULLABLE ON PURPOSE, for the same reason `business_id` is. A conversation
 * whose Location cannot be proven — a multi-Location Business, a Business with
 * no active Location, or a legacy row with no Business at all — stays NULL
 * rather than being attributed to a guess. Once Contract 08B wires Location
 * ACL into Conversations, a wrong Location would show one Location's staff
 * another Location's conversation; NULL shows it to neither.
 *
 * RESTRICT, not cascade (contract §5, following Contracts 02/04 rather than
 * `business_locations.business_id`'s own cascade): a conversation's Location
 * attribution is audit-relevant and must not vanish silently when a Location
 * row is deleted. Archiving a Location is the supported path and leaves both
 * the Location row and this reference intact.
 *
 * `chat_box_messages` deliberately gets NO location_id: a message is only ever
 * read through its parent box and inherits the box's tenancy, exactly as it
 * already does for Business.
 *
 * Column, index and foreign key are each guarded on their own. Laravel emits
 * them as separate ALTER statements, so a run interrupted between two of them
 * (a lock-wait timeout on a large table, say) leaves a state a single
 * column-level guard would skip past; guarding each step lets a replay finish
 * exactly what is missing.
 */
return new class extends Migration
{
    private const INDEX = 'chat_boxes_location_id_index';

    private const FOREIGN_KEY = 'chat_boxes_location_id_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('chat_boxes', 'location_id')) {
            Schema::table('chat_boxes', function (Blueprint $table): void {
                $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            });
        }

        if (! Schema::hasIndex('chat_boxes', self::INDEX)) {
            Schema::table('chat_boxes', function (Blueprint $table): void {
                $table->index('location_id', self::INDEX);
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table('chat_boxes', function (Blueprint $table): void {
                $table->foreign('location_id', self::FOREIGN_KEY)
                    ->references('id')->on('business_locations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('chat_boxes', function (Blueprint $table): void {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        if (Schema::hasIndex('chat_boxes', self::INDEX)) {
            Schema::table('chat_boxes', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('chat_boxes', 'location_id')) {
            Schema::table('chat_boxes', function (Blueprint $table): void {
                $table->dropColumn('location_id');
            });
        }
    }

    private function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('chat_boxes'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['name'] === self::FOREIGN_KEY);
    }
};
