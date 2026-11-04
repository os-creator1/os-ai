<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('business_messaging_registrations', 'support_contact')) {
            Schema::table('business_messaging_registrations', function (Blueprint $table) {
                // The customer-facing contact the Business itself publishes for
                // text-message help, and when the Business explicitly confirmed it.
                // Never prefilled from the 10DLC brand representative's details.
                $table->string('support_contact', 191)->nullable();
                $table->timestamp('support_contact_confirmed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table) {
            $table->dropColumn(['support_contact', 'support_contact_confirmed_at']);
        });
    }
};
