<?php

namespace App\Library\Coo\Insight;

use App\Enums\Coo\CooInsightInvalidationReason;
use App\Enums\Coo\CooInsightKind;
use App\Models\CooInsight;
use Illuminate\Support\Carbon;

/**
 * Contract §9.3 — the only writer of `coo_insights.invalidated_at`.
 *
 * Soft, idempotent and free: each method is a bounded UPDATE of rows that are
 * still valid, it never deletes, never dispatches a job and never reaches the
 * AI gateway. Regeneration is a separate decision, made only by a §8.2
 * trigger.
 */
final class CooInsightInvalidator
{
    /** How many of one Business's valid rows a work event inspects at most. */
    private const WORK_EVENT_SCAN_LIMIT = 200;

    /**
     * `signal_changed` — the facts read now bucket to a different fingerprint
     * than rows cached for the same window, prompt and policy.
     */
    public function invalidateChangedSignals(int $businessId, CooInsightKind $kind, string $periodKey, string $fingerprint, int $promptVersion, int $policyVersion): int
    {
        return CooInsight::query()
            ->where('business_id', $businessId)
            ->where('kind', $kind->value)
            ->where('period_key', $periodKey)
            ->where('prompt_version', $promptVersion)
            ->where('policy_version', $policyVersion)
            ->whereNull('invalidated_at')
            ->where('signal_fingerprint', '!=', $fingerprint)
            ->update($this->stamp(CooInsightInvalidationReason::SignalChanged));
    }

    /**
     * `subject_work_changed` — an Opportunity was completed, dismissed, or its
     * execution succeeded or failed. Every still-valid insight about that
     * Opportunity, and every performance diagnosis whose facts cited its type,
     * described work that is no longer in the state it described.
     */
    public function invalidateForOpportunityWork(int $businessId, int $opportunityId, ?string $opportunityType): int
    {
        $ids = [];

        $candidates = CooInsight::query()
            ->where('business_id', $businessId)
            ->whereNull('invalidated_at')
            ->orderByDesc('id')
            ->limit(self::WORK_EVENT_SCAN_LIMIT)
            ->get(['id', 'kind', 'subject_type', 'subject_id', 'facts_snapshot']);

        foreach ($candidates as $insight) {
            $aboutThisOpportunity = $insight->subject_type === CooInsight::SUBJECT_OPPORTUNITY && (int) $insight->subject_id === $opportunityId;

            if ($aboutThisOpportunity || ($opportunityType !== null && $this->citesOpportunityType($insight, $opportunityType))) {
                $ids[] = (int) $insight->id;
            }
        }

        if ($ids === []) {
            return 0;
        }

        return CooInsight::query()
            ->whereIn('id', $ids)
            ->whereNull('invalidated_at')
            ->update($this->stamp(CooInsightInvalidationReason::SubjectWorkChanged));
    }

    /**
     * `context_changed` — the website was published, the Google connection was
     * made, lost or revoked, or the Business itself was updated. Every valid
     * performance diagnosis of that Business described a context that moved.
     */
    public function invalidateContext(int $businessId): int
    {
        return CooInsight::query()
            ->where('business_id', $businessId)
            ->where('kind', CooInsightKind::PerformanceDiagnosis->value)
            ->whereNull('invalidated_at')
            ->update($this->stamp(CooInsightInvalidationReason::ContextChanged));
    }

    private function citesOpportunityType(CooInsight $insight, string $type): bool
    {
        if ($insight->kind !== CooInsightKind::PerformanceDiagnosis) {
            return false;
        }

        foreach ((array) ($insight->facts_snapshot['facts'] ?? []) as $fact) {
            if (is_array($fact) && ($fact['opportunity_type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function stamp(CooInsightInvalidationReason $reason): array
    {
        $now = Carbon::now();

        return ['invalidated_at' => $now, 'invalidation_reason' => $reason->value, 'updated_at' => $now];
    }
}
