<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Agency AI Prospecting runtime pass — additive runtime fields on the
     * single existing state-machine owner (AgencyProspectCampaignMember).
     * `stage` itself (added by the foundation pass) remains the sole
     * authoritative stage column; nothing here duplicates it.
     *
     * Correction 1 — `soft_negative_count` bounds repeated early-stage
     * soft-negative replies (e.g. "no thanks") without inventing a
     * separate state machine: the AI may respond once per prospect at
     * stage 1/2, never indefinitely. `followup_cancelled_at` records that
     * a scheduled follow-up was deliberately suppressed (a later inbound
     * reply made it inappropriate) WITHOUT falsely claiming it was sent —
     * `followup_sent_at` must mean only "a provider send actually
     * succeeded".
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('agency_prospect_campaign_members', function (Blueprint $table) {
                $table->timestamp('last_inbound_at')->nullable();
                $table->timestamp('last_outbound_at')->nullable();
                $table->timestamp('booking_link_sent_at')->nullable();
                $table->timestamp('followup_at')->nullable();
                $table->timestamp('followup_sent_at')->nullable();
                $table->timestamp('followup_cancelled_at')->nullable();
                $table->string('proposed_slot')->nullable();
                $table->string('last_provider_message_id')->nullable();
                $table->unsignedTinyInteger('soft_negative_count')->default(0);
            });
        }

        public function down(): void
        {
            Schema::table('agency_prospect_campaign_members', function (Blueprint $table) {
                $table->dropColumn([
                    'last_inbound_at',
                    'last_outbound_at',
                    'booking_link_sent_at',
                    'followup_at',
                    'followup_sent_at',
                    'followup_cancelled_at',
                    'proposed_slot',
                    'last_provider_message_id',
                    'soft_negative_count',
                ]);
            });
        }
    };
