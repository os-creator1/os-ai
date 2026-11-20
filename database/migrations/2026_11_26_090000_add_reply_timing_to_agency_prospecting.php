<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Natural reply pacing for Agency Outreach.
 *
 * - inbound ledger rows: the stable moment the automatic reply to THAT message is due
 *   (chosen once, so a redelivered webhook or a retried job can never re-roll it) and the
 *   reply category it was chosen for.
 * - settings: the Agency's own delay windows (seconds per category). NULL = approved defaults.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agency_prospect_messages')) {
            Schema::table('agency_prospect_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('agency_prospect_messages', 'reply_due_at')) {
                    $table->timestamp('reply_due_at')->nullable();
                }
                if (! Schema::hasColumn('agency_prospect_messages', 'reply_category')) {
                    $table->string('reply_category', 24)->nullable();
                }
            });
        }

        if (Schema::hasTable('agency_prospecting_settings') && ! Schema::hasColumn('agency_prospecting_settings', 'reply_delay_windows')) {
            Schema::table('agency_prospecting_settings', function (Blueprint $table) {
                $table->json('reply_delay_windows')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['agency_prospect_messages' => ['reply_due_at', 'reply_category'], 'agency_prospecting_settings' => ['reply_delay_windows']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter($columns, fn (string $c): bool => Schema::hasColumn($table, $c)));

            if ($present !== []) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }
    }
};
