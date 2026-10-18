<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency BYO Telnyx as a first-class sender. Additive only: every column is
 * nullable or defaulted, so existing channels keep behaving exactly as before
 * (not default sender, never verified, no webhook key).
 *
 * `webhook_public_key` holds the agency's own Telnyx webhook public key,
 * sealed (App\Library\Security\SealedSecret) — the account-specific material
 * that lets an inbound Telnyx event for this channel be authenticated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_prospecting_channels', function (Blueprint $table): void {
            $table->boolean('is_default_sender')->default(false)->after('status');
            $table->text('webhook_public_key')->nullable()->after('is_default_sender');
            $table->timestamp('credentials_verified_at')->nullable()->after('webhook_public_key');
            $table->timestamp('webhook_verified_at')->nullable()->after('credentials_verified_at');
            $table->timestamp('disconnected_at')->nullable()->after('webhook_verified_at');
            $table->string('last_error_code', 64)->nullable()->after('disconnected_at');
        });
    }

    public function down(): void
    {
        Schema::table('agency_prospecting_channels', function (Blueprint $table): void {
            $table->dropColumn(['is_default_sender', 'webhook_public_key', 'credentials_verified_at', 'webhook_verified_at', 'disconnected_at', 'last_error_code']);
        });
    }
};
