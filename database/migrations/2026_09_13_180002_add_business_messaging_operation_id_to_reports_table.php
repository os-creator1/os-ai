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
 * one send is one bubble: the conversation message stands for all of its
 * reports in its own conversation, and elsewhere only the send's first report
 * is shown.
 *
 * Nullable, nothing backfilled: every non-managed report, and every report
 * written before this, keeps NULL. A reference, not an enforced foreign key,
 * for the same reason as `reports.automation_step_run_id`.
 *
 * THE INDEX serves the one read that searches by it: "is there an earlier report
 * of the same operation?", asked for each campaign report a timeline reads.
 * InnoDB secondary entries end in the primary key, so `operation = ? AND id < ?`
 * is a range inside the index.
 *
 * Appended, not positioned: this runs before later migrations that add other
 * `reports` columns, so it names none of them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('reports', 'business_messaging_operation_id')) {
                $table->unsignedBigInteger('business_messaging_operation_id')->nullable();
                $table->index('business_messaging_operation_id', 'reports_business_messaging_operation_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reports', 'business_messaging_operation_id')) {
            return;
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropIndex('reports_business_messaging_operation_index');
        });

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('business_messaging_operation_id');
        });
    }
};
