<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive and nullable only.
 *
 * - agency_prospecting_channels.last_error_at: when `last_error_code` was last set by a real
 *   provider outcome, so a provider restriction can be retried once after a bounded cool-down.
 * - business_messaging_operations.delivery_unconfirmed_at: Telnyx `delivery_unconfirmed` — the
 *   carrier gave no delivered/failed answer. The operation stays `accepted` (neither delivered
 *   nor safely retryable); this only records that the uncertain outcome was reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_prospecting_channels', function (Blueprint $table): void {
            $table->timestamp('last_error_at')->nullable();
        });

        Schema::table('business_messaging_operations', function (Blueprint $table): void {
            $table->timestamp('delivery_unconfirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_operations', function (Blueprint $table): void {
            $table->dropColumn('delivery_unconfirmed_at');
        });

        Schema::table('agency_prospecting_channels', function (Blueprint $table): void {
            $table->dropColumn('last_error_at');
        });
    }
};
