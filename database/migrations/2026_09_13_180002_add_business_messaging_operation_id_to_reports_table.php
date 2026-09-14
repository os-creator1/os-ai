<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations — which managed send a campaign `reports` row is the report of.
 *
 * WHY. A managed campaign send is recorded twice by design: once as its report
 * (ManagedDispatchDelegate::recordLegacyReport(), which campaign accounting
 * reads) and once as its conversation message (ConversationHistoryWriter). The
 * timeline shows the message and must not show the report as a second bubble.
 *
 * `business_messaging_operations.report_id` names only ONE report. But one
 * managed operation can legitimately be tracked by more than one campaign job —
 * two contacts of the campaign on the same number share one operation key, and
 * a redelivered job reaches it again. Each tracked job gets its own report,
 * because `tracking_logs.message_id` is unique per report and the campaign's
 * per-recipient accounting depends on it; reusing one report would break that
 * contract. So every managed campaign report carries the operation it belongs
 * to, and the timeline proves by that identity — never by text or time — that
 * the conversation message already stands for all of them.
 *
 * Nullable, nothing backfilled: every non-managed report, and every report
 * written before this, keeps NULL. A reference, not an enforced foreign key,
 * for the same reason as `reports.automation_step_run_id`. Not indexed: nothing
 * searches reports BY it — the one reader starts from an indexed
 * (business_id, to, direction) read and checks the conversation message by its
 * own unique index.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('reports', 'business_messaging_operation_id')) {
                // Appended, not positioned: this runs before later migrations
                // that add other `reports` columns, so it names none of them.
                $table->unsignedBigInteger('business_messaging_operation_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            if (Schema::hasColumn('reports', 'business_messaging_operation_id')) {
                $table->dropColumn('business_messaging_operation_id');
            }
        });
    }
};
