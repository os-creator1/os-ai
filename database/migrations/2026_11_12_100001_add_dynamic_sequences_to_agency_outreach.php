<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Outreach V2: an Agency's reply sequence and answer library are ordered,
 * variable-length lists instead of the fixed message_1..3 and six FAQ columns.
 *
 * ADDITIVE ONLY. The legacy columns stay and remain the source for an Agency that
 * has never customised (the sequence is then derived from them with the stable ids
 * legacy-1..3 / legacy-followup), so existing conversations are not touched.
 *
 *  - settings.sequence_steps  ordered steps [{id, body, advance_on, delay_hours, enabled}]
 *  - settings.faq_entries     approved answers [{id, question, answer, keywords, intent, ...}]
 *  - settings.sequence_paused one switch that holds every send of the sequence
 *  - settings.faq_fallback    what to do with a question no entry covers (ai|continue|handoff)
 *  - members.sequence_sent_ids the step ids already sent to this prospect (no duplicates,
 *                              no skips or out-of-order sends when the sequence is edited)
 *  - messages.sequence_step_id which step a ledger row delivered (audit + idempotent advance)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_prospecting_settings', function (Blueprint $table): void {
            $table->json('sequence_steps')->nullable();
            $table->json('faq_entries')->nullable();
            $table->boolean('sequence_paused')->default(false);
            $table->string('faq_fallback', 12)->default('ai');
        });

        Schema::table('agency_prospect_campaign_members', function (Blueprint $table): void {
            $table->json('sequence_sent_ids')->nullable();
        });

        Schema::table('agency_prospect_messages', function (Blueprint $table): void {
            $table->string('sequence_step_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agency_prospect_messages', function (Blueprint $table): void {
            $table->dropColumn('sequence_step_id');
        });

        Schema::table('agency_prospect_campaign_members', function (Blueprint $table): void {
            $table->dropColumn('sequence_sent_ids');
        });

        Schema::table('agency_prospecting_settings', function (Blueprint $table): void {
            $table->dropColumn(['sequence_steps', 'faq_entries', 'sequence_paused', 'faq_fallback']);
        });
    }
};
