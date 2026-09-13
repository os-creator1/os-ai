<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V2-F §9.1 / §10.1 — the durable mark of automation output.
 *
 * WHY A COLUMN AND NOT AN INFERENCE. The message-received trigger must never let
 * a workflow re-trigger itself off its own output (T-WF-25). The only thing that
 * can say "this outbound message was produced by that step of that journey" is
 * the step run itself, recorded on the message row at the moment it is created.
 * Reading it back from the message text, or from how soon a reply followed a
 * send, would be a guess — and a guess here either lets an auto-responder loop
 * or silently drops a real customer's message.
 *
 * NULL means "not produced by a v2 workflow step": a person in the inbox, a
 * campaign, an API send, a B4 automation (which keeps its own `automation_id`),
 * and every row that existed before this migration. Nothing is backfilled,
 * because nothing before this slice could have produced a v2 step run.
 *
 * NULL ON DELETE, deliberately NOT B4's cascade. `reports.automation_id` cascades,
 * so deleting a B4 automation deletes customers' message history with it. A
 * message is the Business's record of what was said and must outlive the journey
 * that sent it; losing the step run only loses the mark.
 *
 * THE COMPOSITE INDEX serves exactly one read, and it is on the inbound hot path:
 * "the most recent message this Business sent to this number". Every inbound
 * message asks it once per listening workflow, and without the index that is a
 * walk over every outbound row the Business ever sent. With it, `WHERE
 * business_id = ? AND to = ? AND direction = 'outgoing' ORDER BY id DESC LIMIT 1`
 * is a single backward step inside the index, because InnoDB secondary entries
 * already end in the primary key.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('automation_step_run_id')->nullable()->after('automation_id');

            $table->foreign('automation_step_run_id', 'reports_automation_step_run_id_foreign')
                ->references('id')
                ->on('automation_step_runs')
                ->nullOnDelete();

            $table->index(['business_id', 'to', 'direction'], 'reports_business_to_direction_index');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropIndex('reports_business_to_direction_index');
            $table->dropForeign('reports_automation_step_run_id_foreign');
            $table->dropColumn('automation_step_run_id');
        });
    }
};
