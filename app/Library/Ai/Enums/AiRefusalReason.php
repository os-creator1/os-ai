<?php

namespace App\Library\Ai\Enums;

/**
 * Contract §10.1, §10.2 (`ai_usage_ledger.refusal_reason`). Named here so
 * every refusal path uses the same literal — never a free-form string
 * invented at the call site.
 */
enum AiRefusalReason: string
{
    case AiDisabled = 'ai_disabled';
    case EntitlementMissing = 'entitlement_missing';
    case PlanUnavailable = 'plan_unavailable';
    case Dormant = 'dormant';
    case InputTooLarge = 'input_too_large';
    case RequestTooExpensive = 'request_too_expensive';
    case BudgetExhausted = 'budget_exhausted';
    case InteractiveShareExhausted = 'interactive_share_exhausted';
    case NoRouteAffordable = 'no_route_affordable';

    /**
     * A per-Business, per-category ceiling (`config('ai.business_category_ceilings')`, AiBusinessCategoryCeiling)
     * was reached for this period. Independent of the Workspace cap; refused before any reservation.
     */
    case CategoryCeilingReached = 'category_ceiling_reached';

    /**
     * Contract §5.7a C, §6.7, R-28 — a Platform request whose `actorUserId`
     * does not resolve, on a freshly read `User` row, to `is_admin`. Refused
     * before any reservation and before any provider call.
     */
    case PlatformAuthorityDenied = 'platform_authority_denied';
}
