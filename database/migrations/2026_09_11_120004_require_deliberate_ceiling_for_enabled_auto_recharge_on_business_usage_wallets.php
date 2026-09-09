<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 5 — Correction Round 1 (§2.4, §6.1, §7.3, §15).
 *
 * Additive and branch-local (the three Slice 5 migrations before it are
 * unmerged too, but are left exactly as verified; this is a separate,
 * safer additive step rather than a rewrite of a verified backfill).
 *
 * 1. Schema: `auto_recharge_refusal_notified_at` — the once-per-rolling-
 *    window marker for the "automatic top-up was refused" alert
 *    (UsageWalletManager::notifyAutoRechargeRefusal()). Nullable; no
 *    default; never backfilled.
 *
 * 2. Data (idempotent, apply()): the approved policy requires that an
 *    ENABLED automatic top-up carries a deliberately chosen monthly
 *    ceiling that is at least one preset and at most the approved
 *    $500 hard maximum. The maximum is a safety maximum, never a default
 *    ceiling, so an enabled row without a compliant ceiling is switched
 *    OFF — its threshold, amount and stored ceiling are preserved for a
 *    one-click re-enable by the payer, its consent record is left exactly
 *    as it was (nothing is fabricated, nothing is erased), and no
 *    balance, ledger row, payer assignment or attempt is touched. From
 *    this migration on, UsageWalletManager::configureAutoRecharge()
 *    refuses to enable without a compliant ceiling, so the invariant
 *    "enabled ⇒ compliant ceiling" holds for every row.
 *
 * down(): drops the marker column only. It never re-enables a row and
 * never recreates consent, a charge or a balance.
 */
return new class extends Migration
{
    /** Mirrors UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO (migrations stay self-contained). */
    private const BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO = 500_000_000;

    public function up(): void
    {
        if (! Schema::hasColumn('business_usage_wallets', 'auto_recharge_refusal_notified_at')) {
            Schema::table('business_usage_wallets', function (Blueprint $table): void {
                $table->timestamp('auto_recharge_refusal_notified_at')->nullable()->after('spending_limit_alert_period_key');
            });
        }

        $this->apply();
    }

    /**
     * @return array{disabled: int}
     */
    public function apply(): array
    {
        $now = now();

        $disabled = DB::table('business_usage_wallets')
            ->where('auto_recharge_enabled', true)
            ->where(function ($query): void {
                $query->whereNull('monthly_recharge_cap_micro')
                    ->orWhere('monthly_recharge_cap_micro', '<=', 0)
                    ->orWhere('monthly_recharge_cap_micro', '>', self::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO)
                    ->orWhereNull('auto_recharge_amount_micro')
                    ->orWhereColumn('monthly_recharge_cap_micro', '<', 'auto_recharge_amount_micro');
            })
            ->update(['auto_recharge_enabled' => false, 'updated_at' => $now]);

        return ['disabled' => $disabled];
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_usage_wallets', 'auto_recharge_refusal_notified_at')) {
            Schema::table('business_usage_wallets', function (Blueprint $table): void {
                $table->dropColumn('auto_recharge_refusal_notified_at');
            });
        }
    }
};
