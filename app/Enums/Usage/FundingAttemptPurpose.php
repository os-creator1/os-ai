<?php

namespace App\Enums\Usage;

/**
 * business_funding_attempts.purpose (RFC-005 §17.C, M3 contract §21.3).
 * AddonPurchase is inert at M3 — no M3 code path ever sets it; it exists
 * only so the enum's future M4 use does not require a later migration.
 */
enum FundingAttemptPurpose: string
{
    case ManualTopUp = 'manual_top_up';
    case AutoRecharge = 'auto_recharge';
    case AddonPurchase = 'addon_purchase';
}
