<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::finalizeSuccessfulRun() when an existing
 * Opportunity matched by fingerprint has an immutable identity field that
 * does not match the staged candidate/locked run (RFC-002 §22) — an
 * integrity failure that aborts the entire finalization.
 */
class CrossWorkerFingerprintCollisionException extends OpportunityException
{
}
