<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Models\Business;

/**
 * The interface every worker (RFC-002 §12) implements to supply candidates
 * (§38). A producer performs no writes, no transactions, no repository
 * calls, and no external I/O — it only reads $business (and its already
 * loaded relations) and yields immutable, worker-owned candidate data for
 * OpportunityManager::stageCandidate() to validate and persist.
 */
interface OpportunityProducer
{
    public function workerKey(): OpportunityWorkerKey;

    /**
     * @return iterable<OpportunityCandidateData>
     */
    public function produce(Business $business): iterable;
}
