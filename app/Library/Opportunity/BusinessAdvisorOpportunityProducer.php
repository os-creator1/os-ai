<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Business\InitialBusinessSnapshotBuilder;
use App\Models\Business;
use Carbon\CarbonImmutable;

/**
 * Deterministic business_advisor producer (RFC-002 §39), reusing
 * InitialBusinessSnapshotBuilder::opportunityFacts() as a library
 * dependency — called, not reimplemented. Performs no writes, no
 * transactions, no repository calls, and no external I/O: it only reads the
 * already-loaded $business (and its relations) and yields immutable
 * candidate data for OpportunityManager::stageCandidate() to validate.
 *
 * impact/urgency/effort below are the RFC-002 §39-locked values for this
 * worker — OpportunityTypeRegistry does not itself store them (it owns
 * type/allowed_relevant_goal_keys/required_evidence_fact_keys/action_key),
 * so this producer is their one source of truth for business_advisor.
 */
final class BusinessAdvisorOpportunityProducer implements OpportunityProducer
{
    private const URGENCY = 1;

    /** @var array<string, int> */
    private const IMPACT = [
        'missing_phone' => 3,
        'missing_email' => 1,
        'missing_website' => 3,
        'missing_description' => 3,
        'missing_primary_location' => 5,
        'incomplete_primary_location' => 5,
        'missing_services' => 5,
        'missing_primary_service' => 5,
        'missing_gbp_url' => 3,
        'missing_facebook_url' => 1,
        'missing_instagram_url' => 1,
    ];

    /** @var array<string, int> */
    private const EFFORT = [
        'missing_phone' => 1,
        'missing_email' => 1,
        'missing_website' => 3,
        'missing_description' => 1,
        'missing_primary_location' => 3,
        'incomplete_primary_location' => 3,
        'missing_services' => 3,
        'missing_primary_service' => 1,
        'missing_gbp_url' => 1,
        'missing_facebook_url' => 1,
        'missing_instagram_url' => 1,
    ];

    public function __construct(
        private readonly InitialBusinessSnapshotBuilder $snapshotBuilder,
    ) {
    }

    public function workerKey(): OpportunityWorkerKey
    {
        return OpportunityWorkerKey::BusinessAdvisor;
    }

    /**
     * One immutable timestamp is captured for the whole call and reused as
     * every yielded candidate's evidence retrieved_at, so a single
     * produce() call is internally consistent. Iterates
     * OpportunityTypeRegistry::forWorker() in its own fixed declaration
     * order (missing_phone ... missing_instagram_url) — deterministic, and
     * never capped: every currently-true condition yields a candidate.
     *
     * @return iterable<OpportunityCandidateData>
     */
    public function produce(Business $business): iterable
    {
        $retrievedAt = CarbonImmutable::now();
        $facts = $this->snapshotBuilder->opportunityFacts($business);

        foreach (OpportunityTypeRegistry::forWorker($this->workerKey()->value) as $type => $typeDefinition) {
            $factKey = $typeDefinition['required_evidence_fact_keys'][0];

            if (! ($facts[$factKey] ?? false)) {
                continue;
            }

            yield new OpportunityCandidateData(
                type: $type,
                context: null,
                templateParameters: [],
                impact: self::IMPACT[$type],
                urgency: self::URGENCY,
                effort: self::EFFORT[$type],
                confidence: 1.0,
                relevantGoalKeys: $typeDefinition['allowed_relevant_goal_keys'],
                evidence: [
                    new OpportunityEvidenceFactData(
                        sourceType: 'business_profile',
                        sourceIdentifier: "business:{$business->id}:{$factKey}",
                        factKey: $factKey,
                        observedValue: null,
                        retrievedAt: $retrievedAt,
                        observedAt: null,
                        expiresAt: null,
                        sourceUrl: null,
                        contentHash: null,
                    ),
                ],
                actionParameters: [],
            );
        }
    }
}
