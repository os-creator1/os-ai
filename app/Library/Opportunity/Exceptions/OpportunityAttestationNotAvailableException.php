<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::attestComplete() when customer attestation
 * is not available for the locked Opportunity (RFC-002 §32) — wrong
 * status, stale freshness, a persisted completion_policy other than
 * customer_attested, or an active pending/running execution. The row is
 * left completely untouched.
 */
class OpportunityAttestationNotAvailableException extends OpportunityException
{
    public function __construct()
    {
        parent::__construct('Customer attestation is not available for this opportunity.');
    }
}
