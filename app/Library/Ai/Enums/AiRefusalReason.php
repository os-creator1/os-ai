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
    case RequestTooExpensive = 'request_too_expensive';
    case BudgetExhausted = 'budget_exhausted';
    case InteractiveShareExhausted = 'interactive_share_exhausted';
    case NoRouteAffordable = 'no_route_affordable';
}
