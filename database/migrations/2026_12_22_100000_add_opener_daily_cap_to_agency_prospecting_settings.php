<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Agency's own limit on new conversations per rolling 24 hours (per sending number).
 * NULL = no saved override: the configured default (outreach.send_pacing.opener_daily_cap) applies.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agency_prospecting_settings') && ! Schema::hasColumn('agency_prospecting_settings', 'opener_daily_cap')) {
            Schema::table('agency_prospecting_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('opener_daily_cap')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agency_prospecting_settings') && Schema::hasColumn('agency_prospecting_settings', 'opener_daily_cap')) {
            Schema::table('agency_prospecting_settings', fn (Blueprint $table) => $table->dropColumn('opener_daily_cap'));
        }
    }
};
