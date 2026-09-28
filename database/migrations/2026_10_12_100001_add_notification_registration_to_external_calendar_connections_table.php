<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.5/§11/§12.F, review correction — the minimum
 * provider-specific persistence §5.5 explicitly authorizes when a provider
 * mechanically requires it, reported here rather than assumed:
 *
 * Google's `events.watch` and Microsoft Graph's `POST /subscriptions` each
 * return values this application cannot derive or recompute — they must be
 * stored to (a) verify a later inbound notification actually belongs to the
 * registration THIS application created, (b) stop/delete that registration
 * on disconnect, and (c) know when to renew it:
 *
 *   - `notification_channel_id` — Google only. We choose this value at
 *     watch-request time, but it must be FRESH on every (re)registration
 *     (reusing a channel id for a live-or-recently-expired channel is
 *     rejected by Google), so unlike a connection's own `uid` it cannot be
 *     a fixed, derivable value and must be persisted. NULL for Outlook.
 *   - `notification_registration_id` — Google's `resourceId` (opaque,
 *     provider-assigned, required by `channels.stop` alongside the channel
 *     id) or Microsoft's Graph subscription `id` (provider-assigned,
 *     required by the renew-PATCH and delete-DELETE calls). Neither value
 *     is predictable or derivable client-side.
 *   - `notification_expires_at` — the ACTUAL expiration the provider
 *     granted (Google's returned `expiration`, or Graph's returned/renewed
 *     `expirationDateTime`), which the provider may cap below what was
 *     requested. Required both to decide when the scheduled sweep must
 *     renew and to reject an inbound notification against a registration
 *     this application already knows the provider would refuse to honor.
 *
 * What is deliberately NOT added: the channel/subscription "proof" value
 * itself (Google's channel `token`, Microsoft's `clientState`) is set to
 * this application's own existing deterministic
 * ExternalCalendarWebhookToken::forConnection() HMAC at registration time,
 * so it is recomputed on demand rather than stored — exactly the same
 * stateless posture the URL-embedded token already used. No access token,
 * plaintext credential, or provider secret is stored by this migration.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('external_calendar_connections', function (Blueprint $table): void {
            $table->string('notification_channel_id', 255)->nullable()->after('sync_failure_count');
            $table->string('notification_registration_id', 255)->nullable()->after('notification_channel_id');
            $table->timestamp('notification_expires_at')->nullable()->after('notification_registration_id');
        });
    }

    public function down(): void
    {
        Schema::table('external_calendar_connections', function (Blueprint $table): void {
            $table->dropColumn(['notification_channel_id', 'notification_registration_id', 'notification_expires_at']);
        });
    }
};
