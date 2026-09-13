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
 * A REFERENCE, DELIBERATELY NOT AN ENFORCED FOREIGN KEY. `reports` is a legacy
 * messaging table; `automation_step_runs` belongs to the V2 schema, and V2-0
 * guarantees that schema can be rolled back and replayed on its own (T-WF-29,
 * MigrationIntegrityTest) — the only rollback proof V2 has, because this
 * repository's global down() chain is not rollback-clean. A foreign key from
 * `reports` into it would make "roll back the V2 foundation" impossible without
 * first altering a legacy table, which is exactly the coupling that guarantee
 * exists to prevent. So the id is stored as a plain reference, and every reader
 * treats a mark that does not resolve to a step run in the SAME Business as no
 * mark at all (MessageReceivedTriggerSource::precedingAutomationProducer). A
 * message therefore also outlives the journey that sent it — unlike B4's
 * `reports.automation_id`, whose cascade deletes message history with the
 * automation.
 *
 * THE COMPOSITE INDEX serves exactly one read, and it is on the inbound hot path:
 * "the most recent message this Business sent to this number". Every inbound
 * message asks it once, and without the index that is a walk over every outbound
 * row the Business ever sent. With it, `WHERE business_id = ? AND to = ? AND
 * direction = 'outgoing' ORDER BY id DESC LIMIT 1` is a backward step inside the
 * index, because InnoDB secondary entries already end in the primary key.
 * Nothing searches reports BY the mark, so the mark itself is not indexed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('automation_step_run_id')->nullable()->after('automation_id');

            $table->index(['business_id', 'to', 'direction'], 'reports_business_to_direction_index');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropIndex('reports_business_to_direction_index');
            $table->dropColumn('automation_step_run_id');
        });
    }
};
