<?php

namespace App\Enums\Usage;

/**
 * business_usage_addon_catalog.fulfillment_mode (RFC-005 §18, M4 contract
 * §18/§19/§21). direct_deliverable invents no actual delivery mechanism —
 * M4 implements only the state-machine completion for it (§10).
 */
enum AddonFulfillmentMode: string
{
    case WalletCredit = 'wallet_credit';
    case DirectDeliverable = 'direct_deliverable';
}
