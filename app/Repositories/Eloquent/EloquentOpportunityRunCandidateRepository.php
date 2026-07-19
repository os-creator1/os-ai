<?php

namespace App\Repositories\Eloquent;

use App\Models\OpportunityRunCandidate;
use App\Repositories\Contracts\OpportunityRunCandidateRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class EloquentOpportunityRunCandidateRepository extends EloquentBaseRepository implements OpportunityRunCandidateRepository
{
    /**
     * Set only when inserting a new candidate row — never overwritten on an
     * existing row (RFC-002 §21): opportunity_run_id, fingerprint,
     * fingerprint_version, type, context_key.
     */
    private const MUTABLE_FIELDS = [
        'title',
        'summary',
        'impact',
        'urgency',
        'effort',
        'confidence',
        'goal_relevance_rank',
        'evidence_freshness_rank',
        'priority_score',
        'scoring_version',
        'scored_at',
        'evidence',
        'recommended_action',
        'recommended_action_hash',
        'action_schema_version',
    ];

    public function __construct(OpportunityRunCandidate $candidate)
    {
        parent::__construct($candidate);
    }

    public function findForRunByFingerprint(int $runId, string $fingerprint): ?OpportunityRunCandidate
    {
        return $this->query()
            ->where('opportunity_run_id', $runId)
            ->where('fingerprint', $fingerprint)
            ->first();
    }

    public function countDistinctForRun(int $runId): int
    {
        return $this->query()->where('opportunity_run_id', $runId)->count();
    }

    public function upsertMutableFields(int $runId, string $fingerprint, array $attributes): OpportunityRunCandidate
    {
        $existing = $this->findForRunByFingerprint($runId, $fingerprint);

        if ($existing !== null) {
            // Deliberately not updateOrCreate(): only MUTABLE_FIELDS is ever
            // applied here, so identity fields in $attributes (if present)
            // are silently ignored rather than overwriting the existing row.
            $existing->fill(Arr::only($attributes, self::MUTABLE_FIELDS));
            $existing->save();

            return $existing;
        }

        /** @var OpportunityRunCandidate $candidate */
        $candidate = $this->make(array_merge(
            Arr::only($attributes, self::MUTABLE_FIELDS),
            [
                'opportunity_run_id' => $runId,
                'fingerprint' => $fingerprint,
                'fingerprint_version' => $attributes['fingerprint_version'],
                'type' => $attributes['type'],
                'context_key' => $attributes['context_key'] ?? null,
            ]
        ));
        $candidate->save();

        return $candidate;
    }

    public function orderedForRun(int $runId): Collection
    {
        return $this->query()->where('opportunity_run_id', $runId)->orderBy('id')->get();
    }
}
