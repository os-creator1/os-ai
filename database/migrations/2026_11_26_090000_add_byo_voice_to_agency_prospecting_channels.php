<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency-owned Telnyx voice (outbound browser calling). Additive: a channel with none of these set behaves exactly as
 * before, and a call/credential row without a channel id is a platform-managed one, unchanged.
 *
 * The Voice connection ids are Telnyx identifiers (not secrets); the agency's API key and webhook public key stay in the
 * existing sealed columns. `voice_number_key` is the digits of the agency's voice number, UNIQUE so two agencies can never
 * both claim one number. Each `*_verified_at` is written only after Telnyx itself confirmed that fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_prospecting_channels', function (Blueprint $table): void {
            $table->boolean('voice_enabled')->default(false);
            $table->string('voice_connection_id', 64)->nullable();
            $table->string('voice_webrtc_connection_id', 64)->nullable();
            $table->string('voice_number', 32)->nullable();
            $table->string('voice_number_key', 32)->nullable()->unique('apc_voice_number_key_unique');
            $table->timestamp('voice_connection_verified_at')->nullable();
            $table->timestamp('voice_webrtc_verified_at')->nullable();
            $table->timestamp('voice_number_verified_at')->nullable();
            $table->timestamp('voice_webhook_verified_at')->nullable();
            $table->string('voice_last_error_code', 64)->nullable();
        });

        Schema::table('business_voice_calls', function (Blueprint $table): void {
            // The agency channel a call was placed through (null = platform-managed). Its number is the channel's voice number.
            $table->unsignedBigInteger('agency_prospecting_channel_id')->nullable()->index('bvc_agency_channel_index');
            // Non-secret keyed digests of the transport (account / account+connection+number) VERIFIED at placement, so a
            // call can never silently move to settings changed afterwards. No key or secret is stored.
            $table->char('transport_account_hash', 64)->nullable();
            $table->char('transport_config_hash', 64)->nullable();
        });

        // BYO calls have no managed number row (their number is the channel's voice number).
        Schema::table('business_voice_calls', function (Blueprint $table): void {
            $table->unsignedBigInteger('business_messaging_number_id')->nullable()->change();
        });

        Schema::table('business_voice_browser_credentials', function (Blueprint $table): void {
            // 0 = platform-managed credential (not NULL, so the unique key below still holds for managed rows).
            $table->unsignedBigInteger('agency_prospecting_channel_id')->default(0);
            // The WebRTC credential connection an agency credential was minted on (NULL for platform-managed rows): a
            // credential is reusable only while the channel still points at that exact connection.
            $table->string('webrtc_connection_id', 64)->nullable();
            $table->dropUnique('bvbc_business_user_unique');
            $table->unique(['business_id', 'user_id', 'agency_prospecting_channel_id'], 'bvbc_business_user_channel_unique');
        });
    }

    /**
     * Rollback policy (deterministic, idempotent, documented).
     *
     * An agency (BYO) browser credential is only a CACHE of identifiers (Telnyx credential id + SIP username, no secret):
     * the pre-feature schema has no way to say which account it belongs to, and once the channel column is gone it would be
     * indistinguishable from a platform credential - so it must not survive the rollback. They are therefore deleted first
     * (the user's next softphone session simply provisions a fresh one; the Telnyx-side credential expires on its own).
     * Platform credentials (channel 0) are never touched, which also guarantees the legacy UNIQUE (business_id, user_id)
     * can be restored. Every step is guarded, so a rollback interrupted by a DDL failure can be re-run to completion.
     * Call history rows keep their data; `business_messaging_number_id` stays nullable (BYO calls have no managed number).
     */
    public function down(): void
    {
        if (Schema::hasColumn('business_voice_browser_credentials', 'agency_prospecting_channel_id')) {
            DB::table('business_voice_browser_credentials')->where('agency_prospecting_channel_id', '!=', 0)->delete();
        }

        if (Schema::hasColumn('business_voice_browser_credentials', 'agency_prospecting_channel_id')) {
            Schema::table('business_voice_browser_credentials', function (Blueprint $table): void {
                $table->dropUnique('bvbc_business_user_channel_unique');
                $table->dropColumn(['agency_prospecting_channel_id', 'webrtc_connection_id']);
            });
        }
        if (! Schema::hasIndex('business_voice_browser_credentials', 'bvbc_business_user_unique')) {
            Schema::table('business_voice_browser_credentials', function (Blueprint $table): void {
                $table->unique(['business_id', 'user_id'], 'bvbc_business_user_unique');
            });
        }

        // business_messaging_number_id stays nullable on rollback: BYO rows (if any) have none, and restoring NOT NULL would fail.
        if (Schema::hasColumn('business_voice_calls', 'agency_prospecting_channel_id')) {
            Schema::table('business_voice_calls', function (Blueprint $table): void {
                $table->dropIndex('bvc_agency_channel_index');
                $table->dropColumn(['agency_prospecting_channel_id', 'transport_account_hash', 'transport_config_hash']);
            });
        }

        if (Schema::hasColumn('agency_prospecting_channels', 'voice_enabled')) {
            Schema::table('agency_prospecting_channels', function (Blueprint $table): void {
                $table->dropUnique('apc_voice_number_key_unique');
                $table->dropColumn(['voice_enabled', 'voice_connection_id', 'voice_webrtc_connection_id', 'voice_number', 'voice_number_key', 'voice_connection_verified_at', 'voice_webrtc_verified_at', 'voice_number_verified_at', 'voice_webhook_verified_at', 'voice_last_error_code']);
            });
        }
    }
};
