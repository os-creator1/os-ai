<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Redesign — Slice 2B §3/§14/§15: Business tenancy for
 * Conversations.
 *
 * `chat_boxes` gains the same nullable, indexed, restrict-on-delete
 * `business_id` every Pass-1 tenancy table already carries
 * (2026_09_05_120001_add_nullable_business_id_to_tenancy_tables.php) — this
 * extends that pattern rather than inventing a second one.
 *
 * NULLABLE ON PURPOSE. Historical conversations whose Business cannot be
 * proven stay NULL forever and are never reachable through a Business route:
 * every Business-scoped read is a hard `business_id = ?`, and
 * `business_id = ? OR business_id IS NULL` is prohibited. A guessed Business
 * would put one tenant's conversation in another tenant's inbox; NULL puts it
 * in nobody's.
 *
 * `chat_box_messages` deliberately gets NO business_id. A message is only
 * ever read through its parent box, which is resolved Business-first, so a
 * child tenancy column would be a second copy of one fact with no query or
 * security need behind it.
 *
 * INDEXES — exactly the locked set (§14 + §15), no speculative extras:
 *   chat_boxes_business_id_index                  every Business-scoped read
 *   chat_boxes_business_pinned_updated_index      pinned rail + "recents"
 *   chat_boxes_business_notification_index        unread / read filters
 *   chat_boxes_business_id_created_at_index       Dashboard startedCount()
 * The conditional chat_box_messages(box_id, created_at) index is decided by
 * an EXPLAIN at implementation time, not here — see the Slice 2B report.
 *
 * Guarded with hasColumn/hasIndex in the repository's established retrofit
 * style, so a replay after partial application converges instead of failing.
 */
return new class extends Migration
{
    private const INDEXES = [
        'chat_boxes_business_pinned_updated_index' => ['business_id', 'pinned', 'updated_at'],
        'chat_boxes_business_notification_index' => ['business_id', 'notification'],
        'chat_boxes_business_id_created_at_index' => ['business_id', 'created_at'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('chat_boxes', 'business_id')) {
            Schema::table('chat_boxes', function (Blueprint $table): void {
                $table->unsignedBigInteger('business_id')->nullable()->after('user_id');
                $table->index('business_id', 'chat_boxes_business_id_index');
                $table->foreign('business_id', 'chat_boxes_business_id_foreign')
                    ->references('id')->on('businesses')->restrictOnDelete();
            });
        }

        foreach (self::INDEXES as $name => $columns) {
            if (! Schema::hasIndex('chat_boxes', $name)) {
                Schema::table('chat_boxes', function (Blueprint $table) use ($name, $columns): void {
                    $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if (Schema::hasIndex('chat_boxes', $name)) {
                Schema::table('chat_boxes', function (Blueprint $table) use ($name): void {
                    $table->dropIndex($name);
                });
            }
        }

        if (Schema::hasColumn('chat_boxes', 'business_id')) {
            Schema::table('chat_boxes', function (Blueprint $table): void {
                $table->dropForeign('chat_boxes_business_id_foreign');
                $table->dropIndex('chat_boxes_business_id_index');
                $table->dropColumn('business_id');
            });
        }
    }
};
