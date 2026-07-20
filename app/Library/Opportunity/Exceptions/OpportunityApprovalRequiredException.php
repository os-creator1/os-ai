<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::beginTrustedAction() when the locked
 * Opportunity's persisted recommended_action requires approval
 * (registry-derived approval_required is not exactly false) — a trusted,
 * approval-free execution path is not available for it; confirmApproval()'s
 * flow must be used instead (RFC-002 §30). Never carries a model ID, action
 * key, hash, or any other internal detail.
 */
class OpportunityApprovalRequiredException extends OpportunityException
{
    public function __construct()
    {
        parent::__construct('This opportunity action requires approval.');
    }
}
