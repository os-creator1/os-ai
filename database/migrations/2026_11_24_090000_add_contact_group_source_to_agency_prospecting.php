<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outreach campaigns get a CRM contact group as their recipient source.
 *
 * Additive and nullable so every pre-existing campaign keeps working untouched:
 * a campaign with `recipient_source` NULL is a LEGACY campaign (individual
 * enrollment, no group required to start). Only campaigns created through the
 * new form carry `recipient_source = 'contact_group'`, and only those need a
 * valid group to start.
 *
 * - `contact_group_id` / `contact_group_business_id`: the Business-scoped CRM
 *   group and the Business it was validated against. Deliberately NOT foreign
 *   keys: a deleted group must never cascade into (or block) the Outreach
 *   ledger; the start gate re-validates the link instead.
 * - `recipient_report`: the last populate result (counts + skipped reasons).
 * - members.`source_contact_id`: which CRM Contact produced this membership
 *   (NULL = individually enrolled), so a draft refresh can reconcile
 *   group-sourced rows without ever touching manual ones.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agency_prospect_campaigns')) {
            Schema::table('agency_prospect_campaigns', function (Blueprint $table) {
                if (! Schema::hasColumn('agency_prospect_campaigns', 'recipient_source')) {
                    $table->string('recipient_source', 24)->nullable();
                }
                if (! Schema::hasColumn('agency_prospect_campaigns', 'contact_group_id')) {
                    $table->unsignedBigInteger('contact_group_id')->nullable()->index();
                }
                if (! Schema::hasColumn('agency_prospect_campaigns', 'contact_group_business_id')) {
                    $table->unsignedBigInteger('contact_group_business_id')->nullable();
                }
                if (! Schema::hasColumn('agency_prospect_campaigns', 'recipients_synced_at')) {
                    $table->timestamp('recipients_synced_at')->nullable();
                }
                if (! Schema::hasColumn('agency_prospect_campaigns', 'recipient_report')) {
                    $table->json('recipient_report')->nullable();
                }
            });
        }

        if (Schema::hasTable('agency_prospect_campaign_members') && ! Schema::hasColumn('agency_prospect_campaign_members', 'source_contact_id')) {
            Schema::table('agency_prospect_campaign_members', function (Blueprint $table) {
                $table->unsignedBigInteger('source_contact_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agency_prospect_campaigns')) {
            $present = array_values(array_filter(
                ['recipient_source', 'contact_group_id', 'contact_group_business_id', 'recipients_synced_at', 'recipient_report'],
                fn (string $c): bool => Schema::hasColumn('agency_prospect_campaigns', $c)
            ));
            if ($present !== []) {
                Schema::table('agency_prospect_campaigns', fn (Blueprint $t) => $t->dropColumn($present));
            }
        }

        if (Schema::hasTable('agency_prospect_campaign_members') && Schema::hasColumn('agency_prospect_campaign_members', 'source_contact_id')) {
            Schema::table('agency_prospect_campaign_members', fn (Blueprint $t) => $t->dropColumn('source_contact_id'));
        }
    }
};
