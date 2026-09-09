<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 5 (contract §12.2 E-17/E-20, §19) — additive
 * columns on the existing RFC-005 wallet row. No second wallet, ledger or
 * counter: the new columns are (a) the Business-level emergency stop
 * ("Pause paid activity") and (b) the explicit record of who consented to
 * automatic top-up and when, plus (c) the once-per-period marker the
 * spending-threshold alert uses.
 *
 * Reversible: down() drops exactly these columns. No balance, cap, ledger
 * row or existing consent flag is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_usage_wallets', function (Blueprint $table): void {
            $table->timestamp('paid_activity_paused_at')->nullable()->after('billing_status');
            $table->unsignedBigInteger('paid_activity_paused_by_user_id')->nullable()->after('paid_activity_paused_at');
            $table->timestamp('auto_recharge_consented_at')->nullable()->after('auto_recharge_enabled');
            $table->unsignedBigInteger('auto_recharge_consented_by_user_id')->nullable()->after('auto_recharge_consented_at');
            $table->string('spending_limit_alert_period_key', 7)->nullable()->after('low_balance_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('business_usage_wallets', function (Blueprint $table): void {
            $table->dropColumn([
                'paid_activity_paused_at',
                'paid_activity_paused_by_user_id',
                'auto_recharge_consented_at',
                'auto_recharge_consented_by_user_id',
                'spending_limit_alert_period_key',
            ]);
        });
    }
};
