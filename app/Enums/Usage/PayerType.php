<?php

namespace App\Enums\Usage;

/**
 * agency_rebill is never customer-selectable and never assigned by any M2
 * code path — inert until a future, separately authorized milestone
 * (RFC-005 §16, M2 contract §9).
 */
enum PayerType: string
{
    case Business = 'business';
    case Workspace = 'workspace';
    case AgencyRebill = 'agency_rebill';
}
