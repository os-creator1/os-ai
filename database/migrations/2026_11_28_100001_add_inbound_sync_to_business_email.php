<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Email — inbound Gmail synchronization.
 *
 * Inbound mail is appended to the SAME ledger as outbound mail
 * (`business_email_messages`, direction = inbound). Idempotency reuses the
 * existing UNIQUE(business_id, operation_key): an inbound row's key is
 * `inbound:{provider_message_id}`, so a repeated sync can never insert a
 * message twice.
 *
 * The account keeps only a provider cursor (Gmail historyId) and bookkeeping.
 * No mailbox content is stored on the account row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_email_accounts', function (Blueprint $table) {
            $table->string('inbound_cursor', 64)->nullable();
            $table->timestamp('inbound_synced_at')->nullable();
            // Operator/customer-safe category of the last inbound failure, e.g.
            // `reauthorization_required` or `permission_denied`. Cleared on success.
            $table->string('inbound_failure', 32)->nullable();
            // Messages that could not be stored are skipped, not retried forever.
            // ids and a reason only; never message content.
            $table->unsignedInteger('inbound_skipped_count')->default(0);
            $table->string('inbound_last_skipped_id', 191)->nullable();
            $table->string('inbound_last_skipped_reason', 64)->nullable();
            $table->timestamp('inbound_last_skipped_at')->nullable();
        });

        Schema::table('business_email_messages', function (Blueprint $table) {
            $table->string('in_reply_to', 255)->nullable();
            $table->text('references_header')->nullable();
            $table->string('from_name', 191)->nullable();
            $table->timestamp('received_at')->nullable();

            $table->index(['business_id', 'provider_message_id'], 'bem_business_provider_message_index');
            $table->index(['business_id', 'provider_thread_id'], 'bem_business_provider_thread_index');
        });
    }

    public function down(): void
    {
        Schema::table('business_email_messages', function (Blueprint $table) {
            $table->dropIndex('bem_business_provider_message_index');
            $table->dropIndex('bem_business_provider_thread_index');
            $table->dropColumn(['in_reply_to', 'references_header', 'from_name', 'received_at']);
        });

        Schema::table('business_email_accounts', function (Blueprint $table) {
            $table->dropColumn(['inbound_cursor', 'inbound_synced_at', 'inbound_failure', 'inbound_skipped_count', 'inbound_last_skipped_id', 'inbound_last_skipped_reason', 'inbound_last_skipped_at']);
        });
    }
};
