<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->timestamp('sms_capability_confirmed_at')->nullable()->after('last_error_code');

            // One live owner per sender number, enforced by the database so two agencies
            // connecting the same number at the same moment cannot both win. NULL (which a
            // UNIQUE index permits any number of) for a disconnected channel, so a number
            // an agency released can be connected elsewhere.
            $table->string('sender_key', 32)->nullable()->after('sender_number');
        });

        // Backfill without losing any row: the first (oldest) live channel for a number takes
        // the key; a pre-existing duplicate keeps working but stays un-keyed, to be resolved by
        // an operator (it cannot be decided automatically which agency really owns the number).
        $taken = [];

        DB::table('agency_prospecting_channels')->orderBy('id')->get(['id', 'sender_number', 'status'])
            ->each(function ($row) use (&$taken): void {
                $key = preg_replace('/\D/', '', (string) $row->sender_number) ?: null;

                if ($row->status === 'disconnected' || $key === null || isset($taken[$key])) {
                    return;
                }

                $taken[$key] = true;
                DB::table('agency_prospecting_channels')->where('id', $row->id)->update(['sender_key' => $key]);
            });

        Schema::table('agency_prospecting_channels', function (Blueprint $table): void {
            $table->unique('sender_key', 'apc_sender_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agency_prospecting_channels', function (Blueprint $table): void {
            $table->dropUnique('apc_sender_key_unique');
            $table->dropColumn(['sender_key', 'sms_capability_confirmed_at', 'is_default_sender', 'webhook_public_key', 'credentials_verified_at', 'webhook_verified_at', 'disconnected_at', 'last_error_code']);
        });
    }
};
