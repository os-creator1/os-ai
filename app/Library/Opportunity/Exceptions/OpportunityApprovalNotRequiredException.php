<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::requestApproval() when the locked
 * Opportunity's persisted recommended_action has no usable current action,
 * or its registry-derived approval_required is not strictly true
 * (RFC-002 §30) — the row is left completely untouched.
 */
class OpportunityApprovalNotRequiredException extends OpportunityException
{
}
