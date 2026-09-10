<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy Messaging Schema Completion — makes a migration-built database
 * match what executable production code already writes.
 *
 * THE GAP. Three schema pieces are written by live production paths but
 * created by no migration in this repository, so a clean migration-built
 * database cannot run those paths:
 *
 *   chat_boxes.ai_replied   written by DLRController::inboundDLR()'s
 *                           attributed branch, alongside the already
 *                           migrated reply_by_customer.
 *   chat_boxes.ai_stage     written by
 *                           EloquentCampaignRepository::campaignBuilder()'s
 *                           legacy AI-prospecting branch.
 *   ai_box_campaign_map     inserted into by that same branch.
 *
 * Every shape below is taken from how the unmodified production code
 * already uses the column or table — never from a guess. The producers
 * are deliberately untouched; `routes/web.php` records that they are
 * stop-listed for B5 and belong to a separate contract.
 *
 * chat_boxes.ai_replied — DLRController writes the literal 0 in the same
 * update that writes reply_by_customer => 1. It is mirrored on
 * reply_by_customer's own migration exactly
 * (2023_05_07_163338_add_reply_by_customer_to_chat_boxes.php): a
 * non-nullable boolean defaulting to false, so every pre-existing row
 * gets the same "has not been AI-replied to" meaning the writer implies.
 *
 * chat_boxes.ai_stage — campaignBuilder writes the literal 1 when it
 * enrolls a contact into the AI sales state machine. It is NULLABLE on
 * purpose: chat boxes are also created by the ordinary inbound-message
 * path, which never sets a stage, and "not in the state machine" is a
 * different fact from "stage 0". A default would invent that fact for
 * every existing row. unsignedTinyInteger covers the documented 1-6/99
 * value range (B5 §14 and the Slice-3 Dashboard Security remediation
 * contract §3.6 both record it) with room to spare.
 *
 * ai_box_campaign_map — the insert supplies exactly box_id, campaign_id
 * and created_at, and the (since-removed) reader joined
 * chat_boxes.id = map.box_id and filtered on map.campaign_id. Those are
 * the only columns created here; no updated_at is added, because nothing
 * writes one.
 *
 * BOTH FOREIGN KEYS CASCADE, because existing runtime behavior requires
 * it rather than merely tolerating it:
 *   - campaign_id: campaignBuilder inserts the map rows and only THEN
 *     evaluates subscribersToSend(); when that count is zero it calls
 *     $new_campaign->delete() with the map rows already present. Campaigns
 *     is not soft-deleting, so a restricted FK would turn that existing
 *     path into a constraint violation.
 *   - box_id: chat_boxes rows are hard-deleted by ClearChatbox (rows older
 *     than seven days) and by CustomerController when a customer is
 *     removed. A restricted FK would break both.
 *
 * IDEMPOTENT AND REPLAY-SAFE. Long-lived installations already carry these
 * pieces out-of-band, which is exactly why the code works in production and
 * not on a fresh migrate. Each addition is guarded, following the
 * repository's own established convention for retrofitting legacy schema
 * (2024_03_05_162536_update_contacts_table.php,
 * 2025_05_29_131834_add_direction_to_reports_table.php,
 * 2025_10_13_144953_add_performance_indexes_to_reports_and_others.php).
 *
 * REVERSIBLE, NARROWLY. down() drops only what up() introduced, and only
 * if this migration is what introduced it. No existing chat box, campaign
 * or message row is read, rewritten or deleted in either direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_boxes', function (Blueprint $table): void {
            if (! Schema::hasColumn('chat_boxes', 'ai_replied')) {
                $table->boolean('ai_replied')->default(false)->after('reply_by_customer');
            }

            if (! Schema::hasColumn('chat_boxes', 'ai_stage')) {
                $table->unsignedTinyInteger('ai_stage')->nullable()->after('ai_replied');
            }
        });

        if (! Schema::hasTable('ai_box_campaign_map')) {
            Schema::create('ai_box_campaign_map', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('box_id');
                $table->unsignedBigInteger('campaign_id');
                $table->timestamp('created_at')->nullable();

                // The reader this table existed for joined on box_id and
                // filtered on campaign_id; this index serves that shape and
                // backs the campaign_id foreign key.
                $table->index(['campaign_id', 'box_id'], 'ai_box_campaign_map_campaign_box_index');

                $table->foreign('box_id')->references('id')->on('chat_boxes')->onDelete('cascade');
                $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        // Dropped before the columns it references, so the chat_boxes
        // change never runs against a live foreign key.
        Schema::dropIfExists('ai_box_campaign_map');

        Schema::table('chat_boxes', function (Blueprint $table): void {
            $drop = [];

            if (Schema::hasColumn('chat_boxes', 'ai_stage')) {
                $drop[] = 'ai_stage';
            }

            if (Schema::hasColumn('chat_boxes', 'ai_replied')) {
                $drop[] = 'ai_replied';
            }

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
