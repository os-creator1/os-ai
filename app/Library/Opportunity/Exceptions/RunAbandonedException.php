<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::stageCandidate() when the locked run's
 * heartbeat is older than the configured timeout (RFC-002 §21). Staging
 * itself never marks the run failed/abandoned — only beginRun()'s
 * abandonment-recovery path does that.
 */
class RunAbandonedException extends OpportunityException
{
}
