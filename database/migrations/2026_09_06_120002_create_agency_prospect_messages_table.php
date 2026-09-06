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
                $table->text('body');
                $table->string('status', 16);
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->index('workspace_id');
                $table->index('campaign_member_id');
                $table->unique('provider_message_id');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('agency_prospect_messages');
        }
    };
