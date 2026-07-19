<?php

namespace App\Repositories\Contracts;

use App\Models\OpportunityRunCandidate;
use Illuminate\Support\Collection;

interface OpportunityRunCandidateRepository extends BaseRepository
{
    public function findForRunByFingerprint(int $runId, string $fingerprint): ?OpportunityRunCandidate;

    public function countDistinctForRun(int $runId): int;

    /**
     * Upserts by (opportunity_run_id, fingerprint). Identity fields
     * (fingerprint, fingerprint_version, type, context_key) are set only
     * when inserting a new row; on an existing row only mutable candidate
     * content is updated (RFC-002 §21).
     */
    public function upsertMutableFields(int $runId, string $fingerprint, array $attributes): OpportunityRunCandidate;

    /**
     * @return Collection<int, OpportunityRunCandidate>
     */
    public function orderedForRun(int $runId): Collection;
}
