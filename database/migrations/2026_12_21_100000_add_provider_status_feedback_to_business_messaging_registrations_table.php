<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('business_messaging_registrations', 'provider_status')) {
            Schema::table('business_messaging_registrations', function (Blueprint $table) {
                // Toll-free verification: the provider's own nonterminal/terminal state
                // (normalized, e.g. waiting_for_telnyx), the sanitized provider-supplied
                // reason (kept even while the overall status is still pending), and the
                // last time the provider conclusively answered.
                $table->string('provider_status', 64)->nullable();
                $table->string('provider_status_reason', 1000)->nullable();
                $table->timestamp('provider_status_checked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('business_messaging_registrations', function (Blueprint $table) {
            $table->dropColumn(['provider_status', 'provider_status_reason', 'provider_status_checked_at']);
        });
    }
};
