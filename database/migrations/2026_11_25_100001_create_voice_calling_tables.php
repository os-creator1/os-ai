<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browser calling (V1). Purely additive: four new tables, nothing existing is altered.
 *
 *  - voice_numbers        which of a Business's numbers have been explicitly set up for voice, and
 *                         who rings for inbound. A number is only callable once voice_status = ready.
 *  - voice_registrations  one Telnyx telephony credential per (user, Business, account scope); only
 *                         identifiers are stored — the SIP password never leaves Telnyx and API keys
 *                         stay in their existing sealed locations.
 *  - voice_calls          the one canonical call record.
 *  - voice_call_events    the provider-event ledger that makes webhook handling idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_numbers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->string('source', 16);                              // managed | byo
            $table->unsignedBigInteger('messaging_number_id')->nullable();   // managed: business_messaging_numbers.id
            $table->unsignedBigInteger('agency_channel_id')->nullable();     // byo: agency_prospecting_channels.id
            $table->string('phone_number', 32)->unique();              // canonical E.164; a live number has one owner
            $table->string('webrtc_connection_id', 64)->nullable();    // byo: the agency's own credential connection
            $table->string('voice_status', 16)->default('unverified'); // unverified | ready | misconfigured
            $table->string('voice_error_code', 48)->nullable();
            $table->timestamp('voice_checked_at')->nullable();
            $table->unsignedBigInteger('inbound_user_id')->nullable(); // the one user who rings for inbound
            $table->timestamps();

            $table->index(['business_id', 'voice_status']);
        });

        Schema::create('voice_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('scope_key', 40);                           // "managed" | "byo:{channel id}"
            $table->string('connection_id', 64);
            $table->string('telnyx_credential_id', 64);
            $table->string('sip_username', 128);
            $table->timestamp('last_seen_at')->nullable();             // heartbeat of the one leading browser tab
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'business_id', 'scope_key']);
        });

        Schema::create('voice_calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('voice_number_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();         // initiating / answering user
            $table->string('direction', 8);                            // outbound | inbound
            $table->string('from_number', 32);
            $table->string('to_number', 32);
            $table->string('counterpart_number', 32);                  // the other party, for contact/conversation joins
            $table->string('status', 24)->default('initiating');
            $table->unsignedTinyInteger('status_rank')->default(0);    // a lower rank never overwrites a higher one
            $table->string('provider_call_session_id', 64)->nullable()->unique();
            $table->string('provider_call_control_id', 191)->nullable();
            $table->string('provider_leg_id', 64)->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('hangup_cause', 64)->nullable();
            $table->string('hangup_source', 24)->nullable();
            $table->timestamp('last_event_at')->nullable();            // provider timestamp of the newest applied event
            $table->timestamp('client_reported_ended_at')->nullable(); // a browser hint only, never authoritative
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'contact_id', 'initiated_at']);
            $table->index(['business_id', 'counterpart_number', 'initiated_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('voice_call_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('voice_call_id')->nullable();
            $table->string('provider_event_id', 64)->unique();
            $table->string('event_type', 64);
            $table->timestamp('occurred_at')->nullable();
            $table->string('applied', 16);                             // applied | ignored | unmatched
            $table->timestamp('created_at')->nullable();

            $table->index('voice_call_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_call_events');
        Schema::dropIfExists('voice_calls');
        Schema::dropIfExists('voice_registrations');
        Schema::dropIfExists('voice_numbers');
    }
};
