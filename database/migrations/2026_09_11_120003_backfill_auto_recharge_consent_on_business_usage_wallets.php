<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer Experience Slice 5 (contract §12.2, §28.9; brief §7/§14) —
 * data-only, idempotent backfill of the automatic top-up consent record.
 *
 * Rule: automatic top-up stays enabled for an existing wallet only when
 * prior consent is mechanically provable — the flag is on AND the
 * configuration is one RFC-005 accepted through its consent-gated
 * UsageWalletManager::configureAutoRecharge() (the sole write authority)
 * AND the amount is one of the four fixed presets this slice ships. Such
 * rows receive auto_recharge_consented_at = updated_at (the last write of
 * that consent-gated method); the consenting user is unknown to the
 * schema and stays null rather than being invented. Every other enabled
 * row — a free-form amount the product no longer offers, or a missing
 * threshold — is switched OFF; its threshold/amount/cap values are kept
 * so the payer can re-enable with one click. Null and a stored payment
 * instrument are never read as consent. Balances, caps, ledger rows and
 * payer assignments are untouched.
 *
 * down() is a deliberate no-op: consent stamps and disabled flags are not
 * "fabricated back" — the columns themselves are removed by the schema
 * migration's own down().
 */
return new class extends Migration
{
    public const PRESET_AMOUNTS_MICRO = [5_000_000, 10_000_000, 25_000_000, 50_000_000];

    public function up(): void
    {
        $this->apply();
    }

    public function down(): void
    {
        // Intentionally empty (see class docblock).
    }

    /**
     * Idempotent: rows already stamped are skipped; rows already off are
     * never touched.
     */
    public function apply(): array
    {
        $stamped = DB::table('business_usage_wallets')
            ->where('auto_recharge_enabled', true)
            ->whereNull('auto_recharge_consented_at')
            ->whereNotNull('auto_recharge_threshold_micro')
            ->whereIn('auto_recharge_amount_micro', self::PRESET_AMOUNTS_MICRO)
            ->update(['auto_recharge_consented_at' => DB::raw('updated_at')]);

        $disabled = DB::table('business_usage_wallets')
            ->where('auto_recharge_enabled', true)
            ->whereNull('auto_recharge_consented_at')
            ->update(['auto_recharge_enabled' => false]);

        return ['stamped' => $stamped, 'disabled' => $disabled];
    }
};
