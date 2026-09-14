<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #295 Correction Round 1, item 7 — a toll-free verification has no
 * brand/campaign concept; the original migration's provider_brand_id/
 * provider_campaign_id columns must stay 10DLC-only. This additive column
 * is the one opaque reference a toll-free submission actually produces.
 * Never written for a `local` (10DLC) registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table): void {
            $table->string('provider_registration_id', 191)->nullable()->after('provider_campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table): void {
            $table->dropColumn('provider_registration_id');
        });
    }
};
