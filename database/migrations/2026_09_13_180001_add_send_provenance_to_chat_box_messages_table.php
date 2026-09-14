<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations — where an outgoing conversation message came from, so a
 * managed send is recorded in conversation history exactly once.
 *
 * WHY. A managed send (Slice 3) leaves no `reports` row and no conversation
 * message: the provider accepts it and the only lasting record is an
 * operational `business_messaging_operations` row with no body. The message a
 * person sent from Conversations therefore disappeared on reopen.
 * ConversationHistoryWriter now records it — and needs a stable identity for the
 * send, so a replayed request or a retried job can never write it twice.
 *
 *   business_messaging_operation_id   the managed operation this message is the
 *                                     history of. UNIQUE: that is the
 *                                     idempotency guarantee, enforced by the
 *                                     database rather than by a prior read.
 *                                     It also carries attribution: the
 *                                     operation's `report_id` names a campaign
 *                                     send's report.
 *   automation_step_run_id            the Automations V2 step that sent it, read
 *                                     from AutomationSendContext exactly as the
 *                                     `reports` stamp is (V2-F).
 *   source                            who asked for the send: `conversations`
 *                                     (a person pressed Send in the inbox),
 *                                     `quick_send`, or `campaign`.
 *
 * All nullable, nothing backfilled: every existing row — legacy inbound, legacy
 * two-way sends, managed inbound — stays exactly as it is, and NULL simply
 * means "no recorded provenance".
 *
 * References, not enforced foreign keys, for the same reason as
 * `reports.automation_step_run_id`: `chat_box_messages` is a legacy messaging
 * table, and a foreign key into the V2 or managed-messaging schema would couple
 * their rollback to it. Every reader pins these joins to the same Business and
 * treats a reference that does not resolve there as no reference at all.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_box_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('chat_box_messages', 'business_messaging_operation_id')) {
                $table->unsignedBigInteger('business_messaging_operation_id')->nullable()->after('send_by');
                $table->unique('business_messaging_operation_id', 'cbm_messaging_operation_unique');
            }

            if (! Schema::hasColumn('chat_box_messages', 'automation_step_run_id')) {
                $table->unsignedBigInteger('automation_step_run_id')->nullable()->after('business_messaging_operation_id');
            }

            if (! Schema::hasColumn('chat_box_messages', 'source')) {
                $table->string('source', 16)->nullable()->after('automation_step_run_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_box_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('chat_box_messages', 'business_messaging_operation_id')) {
                $table->dropUnique('cbm_messaging_operation_unique');
            }
        });

        Schema::table('chat_box_messages', function (Blueprint $table): void {
            foreach (['source', 'automation_step_run_id', 'business_messaging_operation_id'] as $column) {
                if (Schema::hasColumn('chat_box_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
