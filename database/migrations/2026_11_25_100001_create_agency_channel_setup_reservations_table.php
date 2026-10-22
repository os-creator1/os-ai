<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reserves the channel identity (uid) an agency's own-provider webhook URL is built from,
 * BEFORE the channel exists, so the URL can be pasted into Telnyx while the Messaging Profile
 * is being created. The reservation is only a name: it is not a channel, holds no credentials,
 * is never a sender, and the public webhook route answers 404 for it. Connecting creates the
 * channel with this exact uid and consumes the reservation. One open reservation per
 * Workspace and provider, so a refresh, a return visit or a failed attempt reuses it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agency_channel_setup_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'provider'], 'acsr_workspace_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_channel_setup_reservations');
    }
};
