<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Niche-aware 10DLC registration — the customer-editable campaign
 * description and the customer's own per-item consent confirmations
 * (RegistrationConsentChecklist keys). Both nullable and additive: a row
 * saved before this migration simply has neither, and the
 * pre-submission validator reports that as an actionable correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table): void {
            $table->text('campaign_description')->nullable()->after('opt_in_method');
            $table->json('consent_confirmations')->nullable()->after('terms_url');
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table): void {
            $table->dropColumn(['campaign_description', 'consent_confirmations']);
        });
    }
};
