<?php

declare(strict_types=1);

namespace Tests\Feature\Opportunity\Support;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Opportunity\OpportunityCandidateData;
use App\Library\Opportunity\OpportunityProducer;
use App\Models\Business;

/**
 * Test-only OpportunityProducer implementation (RFC-002 Milestone 6, Slice
 * 1). Proves the run/candidate/finalization protocol in
 * OpportunityManager is genuinely driven by the OpportunityProducer
 * interface, not hardcoded to the concrete BusinessAdvisorOpportunityProducer
 * class. Performs no persistence, dispatches no job or event, and contains
 * no OpportunityManager logic or production-specific branching — it only
 * returns the fixed worker key and candidates it was constructed with.
 */
final class FakeOpportunityProducer implements OpportunityProducer
{
    /**
     * @param iterable<OpportunityCandidateData> $candidates
     */
    public function __construct(
        private readonly OpportunityWorkerKey $workerKey,
        private readonly iterable $candidates,
    ) {
    }

    public function workerKey(): OpportunityWorkerKey
    {
        return $this->workerKey;
    }

    /**
     * @return iterable<OpportunityCandidateData>
     */
    public function produce(Business $business): iterable
    {
        return $this->candidates;
    }
}
