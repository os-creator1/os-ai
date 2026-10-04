<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Outreach V1 (docs/automation/AGENCY-OUTREACH-V1-CONTRACT.md §3, §8, §13).
 *
 * Additive and guarded. Every column is nullable or defaulted so existing
 * Prospecting rows keep working untouched; nothing is backfilled and nothing is
 * seeded (the neutral default copy lives in code, OutreachScriptDefaults, and is
 * persisted only when an Agency saves its script).
 *
 * - settings: the Agency-owned script, the FAQ answers, the follow-up copy and the
 *   AI/scheduling switches. `booking_url` is the calendar URL and
 *   `follow_up_delay_hours` the delay — both already exist and are reused.
 * - members: the link to the canonical Conversations row and the manual-takeover state.
 * - messages: the audit trail (which source wrote it, which stage transition it caused,
 *   which script version, which person for a manual action, why a send did not happen).
 * - prospects: WHY a prospect stopped, so "Rejected" and "Opted out" can be told apart.
 *
 * `chat_box_id` is deliberately not a foreign key: a deleted conversation must never
 * cascade into (or block) the Outreach ledger.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agency_prospecting_settings')) {
            Schema::table('agency_prospecting_settings', function (Blueprint $table) {
                foreach (['website_url' => 2048] as $column => $length) {
                    if (! Schema::hasColumn('agency_prospecting_settings', $column)) {
                        $table->string($column, $length)->nullable();
                    }
                }

                foreach ([
                    'message_1', 'message_2', 'message_3',
                    'pricing_answer', 'location_answer', 'found_you_answer', 'what_we_do_answer', 'website_answer', 'clarify_answer',
                    'followup_message',
                ] as $column) {
                    if (! Schema::hasColumn('agency_prospecting_settings', $column)) {
                        $table->text($column)->nullable();
                    }
                }

                if (! Schema::hasColumn('agency_prospecting_settings', 'followup_enabled')) {
                    $table->boolean('followup_enabled')->default(true);
                }

                if (! Schema::hasColumn('agency_prospecting_settings', 'ai_enabled')) {
                    $table->boolean('ai_enabled')->default(true);
                }

                if (! Schema::hasColumn('agency_prospecting_settings', 'scheduling_mode')) {
                    $table->string('scheduling_mode', 32)->default('calendar_link');
                }

                if (! Schema::hasColumn('agency_prospecting_settings', 'script_version')) {
                    $table->unsignedInteger('script_version')->default(1);
                }
            });
        }

        if (Schema::hasTable('agency_prospect_campaign_members')) {
            Schema::table('agency_prospect_campaign_members', function (Blueprint $table) {
                if (! Schema::hasColumn('agency_prospect_campaign_members', 'chat_box_id')) {
                    $table->unsignedBigInteger('chat_box_id')->nullable()->index();
                }

                if (! Schema::hasColumn('agency_prospect_campaign_members', 'ai_paused_at')) {
                    $table->timestamp('ai_paused_at')->nullable();
                }

                if (! Schema::hasColumn('agency_prospect_campaign_members', 'ai_paused_by_user_id')) {
                    $table->unsignedBigInteger('ai_paused_by_user_id')->nullable();
                }
            });
        }

        if (Schema::hasTable('agency_prospect_messages')) {
            Schema::table('agency_prospect_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('agency_prospect_messages', 'source')) {
                    $table->string('source', 16)->nullable();
                }

                if (! Schema::hasColumn('agency_prospect_messages', 'stage_from')) {
                    $table->unsignedTinyInteger('stage_from')->nullable();
                }

                if (! Schema::hasColumn('agency_prospect_messages', 'stage_to')) {
                    $table->unsignedTinyInteger('stage_to')->nullable();
                }

                if (! Schema::hasColumn('agency_prospect_messages', 'script_version')) {
                    $table->unsignedInteger('script_version')->nullable();
                }

                if (! Schema::hasColumn('agency_prospect_messages', 'actor_user_id')) {
                    $table->unsignedBigInteger('actor_user_id')->nullable();
                }

                if (! Schema::hasColumn('agency_prospect_messages', 'failure_reason')) {
                    $table->string('failure_reason', 64)->nullable();
                }
            });
        }

        if (Schema::hasTable('agency_prospects') && ! Schema::hasColumn('agency_prospects', 'stop_reason')) {
            Schema::table('agency_prospects', function (Blueprint $table) {
                $table->string('stop_reason', 24)->nullable();
            });
        }
    }

    public function down(): void
    {
        $drop = [
            'agency_prospecting_settings' => [
                'website_url', 'message_1', 'message_2', 'message_3', 'pricing_answer', 'location_answer',
                'found_you_answer', 'what_we_do_answer', 'website_answer', 'clarify_answer', 'followup_message',
                'followup_enabled', 'ai_enabled', 'scheduling_mode', 'script_version',
            ],
            'agency_prospect_campaign_members' => ['chat_box_id', 'ai_paused_at', 'ai_paused_by_user_id'],
            'agency_prospect_messages' => ['source', 'stage_from', 'stage_to', 'script_version', 'actor_user_id', 'failure_reason'],
            'agency_prospects' => ['stop_reason'],
        ];

        foreach ($drop as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter($columns, fn (string $c): bool => Schema::hasColumn($table, $c)));

            if ($present !== []) {
                Schema::table($table, function (Blueprint $t) use ($present): void {
                    $t->dropColumn($present);
                });
            }
        }
    }
};
