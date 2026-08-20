<?php

namespace App\Enums\Usage;

/**
 * business_usage_addon_purchases.status (RFC-005 §18, M4 contract
 * §18/§19/§21).
 */
enum AddonPurchaseStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
