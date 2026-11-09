<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('business_messaging_registrations', 'doing_business_as')) {
            Schema::table('business_messaging_registrations', function (Blueprint $table) {
                // The customer-facing name the owner states they operate under when it differs
                // from the legal name (Telnyx `doingBusinessAs`), and an optional owner-written
                // note explaining legitimate legal-name / website-brand / email-domain differences
                // (sent in `additionalInformation`). Neither is ever inferred.
                $table->string('doing_business_as', 191)->nullable();
                $table->string('additional_information', 300)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table) {
            $table->dropColumn(['doing_business_as', 'additional_information']);
        });
    }
};
