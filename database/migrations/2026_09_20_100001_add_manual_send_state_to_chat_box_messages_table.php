<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations — a manual outbound message must not silently disappear when
 * it cannot be sent, and a retry must update the SAME bubble rather than
 * create a second one.
 *
 * WHY THESE COLUMNS AND NOT A SECOND TABLE. `chat_box_messages` is already
 * the one customer-visible bubble a managed send is recorded as
 * (`business_messaging_operation_id`, added for #299). A logical manual
 * send is a 1:1 concept with that bubble, so it needs no join table of its
 * own — it needs this row to be able to say what STATE it is in, WHY, and
 * to be found again by something more stable than an operation key that
 * changes on every retry attempt.
 *
 *   send_uid            the stable identity of the LOGICAL message, set once
 *                        at the first attempt and never reused for another
 *                        message. UNIQUE when present. Every retry attempt
 *                        gets its OWN operation_key (so a genuinely new
 *                        provider attempt is never silently suppressed by
 *                        the dispatcher's own operation-key idempotency),
 *                        but is found and applied back onto this SAME row by
 *                        send_uid — never by body, time, or phone.
 *   send_status          'sending' | 'failed' | 'sent' | 'delivered' |
 *                        'delivery_failed'. NULL on every row this feature
 *                        does not apply to — every legacy, inbound, quick
 *                        send, campaign, and automation row included — so
 *                        existing rows and their rendering are unaffected.
 *   send_failure_reason  a customer-safe reason CODE (never a provider
 *                        payload, id, or raw exception), null unless
 *                        send_status is 'failed' or 'delivery_failed'.
 *   retry_count           how many provider attempts this logical message has
 *                        had; 1 after the first attempt, whether it
 *                        succeeded or failed.
 *
 * All nullable, nothing backfilled — additive only, exactly the
 * `send_provenance` migration's own stated pattern.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_box_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('chat_box_messages', 'send_uid')) {
                $table->char('send_uid', 36)->nullable()->after('source');
                $table->unique('send_uid', 'cbm_send_uid_unique');
            }

            if (! Schema::hasColumn('chat_box_messages', 'send_status')) {
                $table->string('send_status', 16)->nullable()->after('send_uid');
            }

            if (! Schema::hasColumn('chat_box_messages', 'send_failure_reason')) {
                $table->string('send_failure_reason', 32)->nullable()->after('send_status');
            }

            if (! Schema::hasColumn('chat_box_messages', 'retry_count')) {
                $table->unsignedTinyInteger('retry_count')->nullable()->after('send_failure_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_box_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('chat_box_messages', 'send_uid')) {
                $table->dropUnique('cbm_send_uid_unique');
            }
        });

        Schema::table('chat_box_messages', function (Blueprint $table): void {
            foreach (['retry_count', 'send_failure_reason', 'send_status', 'send_uid'] as $column) {
                if (Schema::hasColumn('chat_box_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
