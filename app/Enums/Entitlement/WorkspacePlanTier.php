<?php

namespace App\Enums\Entitlement;

/**
 * Identity only — no structural/commercial data. workspace_plan_catalog is
 * the sole authority for slot counts, pricing, and ratios per tier
 * (RFC-004 §12.1).
 */
enum WorkspacePlanTier: string
{
    case Core = 'core';
    case Growth = 'growth';
    case Agency = 'agency';
}
