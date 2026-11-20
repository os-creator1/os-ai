<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared, database-backed outbound pacing for Agency Outreach.
 *
 * - outreach_send_slots: ONE row per sending number holding when it may next send. Every
 *   Outreach campaign using that number reserves under that row's lock, so the limit is shared
 *   and atomic rather than per campaign or per worker.
 * - agency_prospect_messages.deferred_until: when a rate-limited (paused) send will be retried.
 * - agency_prospect_campaigns.min_send_interval_seconds: an optional STRICTER per-campaign spacing.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('outreach_send_slots')) {
            Schema::create('outreach_send_slots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->string('sender_key', 64);
                $table->timestamp('next_free_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'sender_key']);
            });
        }

        if (Schema::hasTable('agency_prospect_messages')) {
            Schema::table('agency_prospect_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('agency_prospect_messages', 'deferred_until')) {
                    $table->timestamp('deferred_until')->nullable();
                }
                if (! Schema::hasColumn('agency_prospect_messages', 'outreach_send_slot_id')) {
                    $table->unsignedBigInteger('outreach_send_slot_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('agency_prospect_campaigns') && ! Schema::hasColumn('agency_prospect_campaigns', 'min_send_interval_seconds')) {
            Schema::table('agency_prospect_campaigns', function (Blueprint $table) {
                $table->unsignedSmallInteger('min_send_interval_seconds')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_send_slots');

        foreach (['agency_prospect_messages' => ['deferred_until', 'outreach_send_slot_id'],
            'agency_prospect_campaigns' => ['min_send_interval_seconds']] as $table => $columns) {
            if (Schema::hasTable($table)) {
                $present = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
                if ($present !== []) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
                }
            }
        }
    }
};
