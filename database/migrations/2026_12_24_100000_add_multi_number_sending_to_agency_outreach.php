<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Outreach multi-number sending.
 *
 *  - agency_prospecting_channels.outreach_enabled: the agency's own Telnyx number is in its
 *    Outreach sending pool. Backfilled for the current default sender, so an agency that
 *    already sends from one number sees it in the pool without doing anything.
 *  - agency_prospect_campaigns.sender_channel_ids: the campaign's selected sending numbers.
 *    NULL = never configured: the campaign keeps sending from the agency's default sender
 *    exactly as before (existing single-number campaigns need no reconfiguration).
 *  - agency_prospect_campaign_members.sender_channel_id: the ONE number this prospect's
 *    conversation is pinned to, persisted before the first outbound message.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agency_prospecting_channels') && ! Schema::hasColumn('agency_prospecting_channels', 'outreach_enabled')) {
            Schema::table('agency_prospecting_channels', function (Blueprint $table) {
                $table->boolean('outreach_enabled')->default(false)->after('is_default_sender');
            });

            DB::table('agency_prospecting_channels')
                ->where('is_default_sender', true)
                ->where('provider', 'telnyx')
                ->update(['outreach_enabled' => true]);
        }

        if (Schema::hasTable('agency_prospect_campaigns') && ! Schema::hasColumn('agency_prospect_campaigns', 'sender_channel_ids')) {
            Schema::table('agency_prospect_campaigns', function (Blueprint $table) {
                $table->json('sender_channel_ids')->nullable();
            });
        }

        if (Schema::hasTable('agency_prospect_campaign_members') && ! Schema::hasColumn('agency_prospect_campaign_members', 'sender_channel_id')) {
            Schema::table('agency_prospect_campaign_members', function (Blueprint $table) {
                // Channels are never deleted (a disconnected one is kept for history), so an
                // assignment can never silently vanish and be re-made on another number.
                $table->foreignId('sender_channel_id')->nullable()->constrained('agency_prospecting_channels')->restrictOnDelete();
                $table->timestamp('sender_assigned_at')->nullable();
                $table->index(['campaign_id', 'sender_channel_id'], 'apcm_campaign_sender_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agency_prospect_campaign_members') && Schema::hasColumn('agency_prospect_campaign_members', 'sender_channel_id')) {
            Schema::table('agency_prospect_campaign_members', function (Blueprint $table) {
                $table->dropForeign(['sender_channel_id']);
                $table->dropIndex('apcm_campaign_sender_index');
                $table->dropColumn(['sender_channel_id', 'sender_assigned_at']);
            });
        }

        if (Schema::hasTable('agency_prospect_campaigns') && Schema::hasColumn('agency_prospect_campaigns', 'sender_channel_ids')) {
            Schema::table('agency_prospect_campaigns', fn (Blueprint $table) => $table->dropColumn('sender_channel_ids'));
        }

        if (Schema::hasTable('agency_prospecting_channels') && Schema::hasColumn('agency_prospecting_channels', 'outreach_enabled')) {
            Schema::table('agency_prospecting_channels', fn (Blueprint $table) => $table->dropColumn('outreach_enabled'));
        }
    }
};
