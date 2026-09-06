<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Agency AI Prospecting runtime pass — a bounded message ledger, never
     * the full legacy Reports/ChatBoxMessage shape. `provider_message_id`
     * is the sole idempotency key for inbound webhook delivery and
     * outbound send results (Twilio MessageSid / Telnyx message id) —
     * unique but nullable (an outbound row may briefly have none while a
     * send attempt is in flight or failed before the provider ever
     * assigned one). Never stores provider credentials.
     *
     * Correction 1 — `purpose` (initial/ai_reply/followup; null for
     * inbound rows, where it does not apply) and `operation_key` (unique,
     * nullable) give every OUTBOUND logical send a durable, deterministic
     * identity independent of `provider_message_id` (which does not exist
     * until the provider has actually accepted the send). This is what
     * lets a job retry after a crash claim "this exact operation already
     * has a row" atomically via the database's own unique constraint,
     * rather than relying on transaction-timing assumptions.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('agency_prospect_messages', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->foreignId('campaign_member_id')->constrained('agency_prospect_campaign_members')->cascadeOnDelete();
                $table->foreignId('channel_id')->nullable()->constrained('agency_prospecting_channels')->nullOnDelete();
                $table->string('direction', 16);
                $table->string('provider_message_id')->nullable();
                $table->string('purpose', 16)->nullable();
                $table->string('operation_key')->nullable();
                $table->text('body');
                $table->string('status', 16);
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->index('workspace_id');
                $table->index('campaign_member_id');
                $table->unique('provider_message_id');
                $table->unique('operation_key');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('agency_prospect_messages');
        }
    };
