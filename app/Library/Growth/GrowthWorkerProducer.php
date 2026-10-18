<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthRuleOutcomeStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Opportunity\OpportunityCandidateData;
use App\Library\Opportunity\OpportunityEvidenceFactData;
use App\Library\Opportunity\OpportunityProducer;
use App\Models\Business;

/**
 * The RFC-002 producer for one engine worker (sales / website / seo /
 * reputation). It performs no I/O of its own: it turns the rule outcomes the
 * evaluator already computed into the immutable candidates the engine
 * validates and persists. The engine — not this class — owns dedupe,
 * lifecycle, scoring and resolution, exactly as for the Business Advisor.
 *
 * Constructed per evaluation with the already-computed outcomes so a worker
 * run never re-reads a fact or re-runs a rule.
 */
final class GrowthWorkerProducer implements OpportunityProducer
{
    /** @param  array<string, GrowthRuleOutcome>  $outcomes  rule key => outcome */
    public function __construct(
        private readonly OpportunityWorkerKey $worker,
        private readonly array $outcomes,
        private readonly GrowthFactSnapshot $facts,
    ) {
    }

    public function workerKey(): OpportunityWorkerKey
    {
        return $this->worker;
    }

    /**
     * Candidates in priority order, bounded by the engine's per-run candidate
     * cap (`opportunity.max_candidates_per_run`). A Business with very many
     * Locations can produce more findings than one run may stage; rather than
     * failing the whole run (and showing the owner nothing), the lowest-priority
     * findings beyond the cap are left for a run with a higher cap — set
     * OPPORTUNITY_MAX_CANDIDATES_PER_RUN for such a Business.
     *
     * @return iterable<OpportunityCandidateData>
     */
    public function produce(Business $business): iterable
    {
        $candidates = iterator_to_array($this->candidates($business), false);

        usort($candidates, fn (OpportunityCandidateData $a, OpportunityCandidateData $b) => [$b->impact, $b->confidence, $b->urgency, $a->type] <=> [$a->impact, $a->confidence, $a->urgency, $b->type]);

        yield from array_slice($candidates, 0, max(1, (int) config('opportunity.max_candidates_per_run', 100)));
    }

    /** @return iterable<OpportunityCandidateData> */
    private function candidates(Business $business): iterable
    {
        foreach (GrowthRuleRegistry::forWorker($this->worker->value) as $key => $rule) {
            $outcome = $this->outcomes[$key] ?? null;

            if ($outcome === null || $outcome->status !== GrowthRuleOutcomeStatus::Finding) {
                continue;
            }

            $definition = $rule->definition();

            foreach ($outcome->findings as $finding) {
                $identifier = 'business:' . $business->id . ($finding->locationId !== null ? ':location:' . $finding->locationId : '');

                yield new OpportunityCandidateData(
                    type: $key,
                    context: $finding->locationId === null ? null : ['business_location_id' => $finding->locationId],
                    templateParameters: [],
                    impact: $finding->impact,
                    urgency: $finding->urgency,
                    effort: $finding->effort,
                    confidence: $finding->confidence,
                    relevantGoalKeys: $definition->goalKeys,
                    evidence: [new OpportunityEvidenceFactData(
                        sourceType: $definition->domain,
                        sourceIdentifier: $identifier,
                        factKey: $definition->factKey,
                        observedValue: $finding->evidence,
                        retrievedAt: $this->facts->now,
                        observedAt: $this->facts->now,
                        expiresAt: null,
                        sourceUrl: null,
                        contentHash: null,
                    )],
                    actionParameters: null,
                );
            }
        }
    }
}
