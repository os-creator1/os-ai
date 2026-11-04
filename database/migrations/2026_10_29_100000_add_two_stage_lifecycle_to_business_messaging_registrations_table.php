<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('business_messaging_registrations', 'brand_status')) {
            return;
        }

        Schema::table('business_messaging_registrations', function (Blueprint $table) {
            // processing | verified | needs_attention. Null = not yet known (polled, shown as in progress).
            $table->string('brand_status', 24)->nullable();
            $table->string('provider_brand_tcr_id', 64)->nullable();
            $table->string('brand_status_detail', 191)->nullable();
            $table->timestamp('brand_created_at')->nullable();
            $table->timestamp('brand_status_checked_at')->nullable();
            $table->timestamp('campaign_status_checked_at')->nullable();
            // A campaign eligibility/submission issue that did NOT create a campaign.
            $table->string('campaign_issue', 255)->nullable();
            $table->boolean('campaign_issue_permanent')->default(false);
        });

        // Existing records keep every ID and status exactly as they are. A
        // campaign (or a pending/approved registration) is NOT proof that its
        // brand was identity-verified, so brand_status is left NULL = unknown
        // and is reconciled against the carrier's identityStatus by the status
        // sweep and the Refresh button. Nothing is resubmitted or charged.
    }

    public function down(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table) {
            $table->dropColumn(['brand_status', 'provider_brand_tcr_id', 'brand_status_detail', 'brand_created_at', 'brand_status_checked_at', 'campaign_status_checked_at', 'campaign_issue', 'campaign_issue_permanent']);
        });
    }
};
