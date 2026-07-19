<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::requestApproval() when the locked
 * Opportunity's persisted recommended_action is not a fully configured,
 * hash-valid, source-controlled executable action (RFC-002 §13.1, §28,
 * §45) — malformed structure, an unsupported action_key, incomplete
 * registry metadata, a persisted value that disagrees with the trusted
 * registry, unconfigured/invalid parameters, or a mismatched action hash.
 * The row is left completely untouched.
 */
class OpportunityActionNotExecutableException extends OpportunityException
{
}
