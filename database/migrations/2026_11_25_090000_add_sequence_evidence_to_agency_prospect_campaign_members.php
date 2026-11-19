<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign-scoped record of WHICH send satisfied a step that no step-tagged ledger row proves
 * (the campaign opener, or a message a person sent by hand): { stepId: "L<ledgerId>"|"C<chatMessageId>" }.
 * It lives on the member, so it can never cross campaigns, and one evidence key is held by at
 * most one step. Nullable and additive: members without it keep the content-derived behaviour.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agency_prospect_campaign_members') && ! Schema::hasColumn('agency_prospect_campaign_members', 'sequence_evidence')) {
            Schema::table('agency_prospect_campaign_members', function (Blueprint $table) {
                $table->json('sequence_evidence')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agency_prospect_campaign_members') && Schema::hasColumn('agency_prospect_campaign_members', 'sequence_evidence')) {
            Schema::table('agency_prospect_campaign_members', fn (Blueprint $t) => $t->dropColumn('sequence_evidence'));
        }
    }
};
