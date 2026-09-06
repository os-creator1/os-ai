<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Agency AI Prospecting runtime pass — the smallest explicit
     * Workspace-owned sending-identity model the foundation pass deferred.
     * A dedicated, non-shared SendingServer row backs every channel (the
     * same "own SendingServer per connection" discipline B2 established
     * for Business Messaging Channels) — never a Business's own
     * CustomerBasedSendingServer assignment. `sender_number` is stored in
     * the canonical digits-only international form
     * (AgencyProspectPhoneNormalizer), matching agency_prospects.phone.
     *
     * No webhook-secret column exists here deliberately — the inbound
     * webhook URL's unguessable token is derived on demand from this
     * channel's own uid + APP_KEY (a deterministic HMAC), never stored,
     * so there is nothing here to leak or rotate.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('agency_prospecting_channels', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->foreignId('sending_server_id')->constrained('sending_servers')->restrictOnDelete();
                $table->string('provider', 32);
                $table->string('sender_number', 32);
                $table->string('status', 32)->default('active');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index('workspace_id');
                $table->unique(['workspace_id', 'sender_number']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('agency_prospecting_channels');
        }
    };
