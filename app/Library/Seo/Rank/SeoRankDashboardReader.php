<?php

namespace App\Library\Seo\Rank;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankObservationStatus;
use App\Enums\Seo\SeoRankRunState;
use App\Enums\Seo\SeoRankTrackingState;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the Search keywords dashboard from rows the CALLER has already
 * Location-filtered. Nothing here widens visibility: targets are loaded only for
 * the supplied keyword ids, inside the Business.
 *
 * One row per tracked/stopped target, plus one row per keyword that has no
 * target at all ("SEO target" only). Summary numbers are real or null — a null
 * is rendered "—", never a fake zero.
 */
final class SeoRankDashboardReader
{
    public const STATE_UNTRACKED = 'untracked';
    public const STATE_PAUSED = 'paused';
    public const STATE_CHECKING = 'checking';
    public const STATE_WAITING = 'waiting';
    public const STATE_BUDGET_PAUSED = 'budget_paused';
    public const STATE_ACTIVE = 'active';

    public function __construct(
        private readonly SeoRankHistoryReader $history,
        private readonly SeoRankTrackingBudget $budget,
    ) {
    }

    /**
     * @param Collection<int, SeoKeyword> $keywords visible, ACTIVE keywords
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function build(Business $business, Collection $keywords, ?SeoRankPlan $plan, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $keywordIds = $keywords->pluck('id')->all();

        $targets = $keywordIds === [] ? collect() : SeoRankTarget::query()
            ->where('business_id', $business->id)
            ->whereIn('seo_keyword_id', $keywordIds)
            ->with('searchLocation')
            ->orderBy('id')
            ->get();

        $targetIds = $targets->pluck('id')->all();
        $summaries = $this->history->summaries($targetIds);

        $openTargetIds = $targetIds === [] ? [] : SeoRankCheckRun::query()
            ->whereIn('seo_rank_target_id', $targetIds)
            ->whereIn('state', [SeoRankRunState::Scheduled->value, SeoRankRunState::Submitting->value, SeoRankRunState::Submitted->value, SeoRankRunState::Held->value])
            ->pluck('seo_rank_target_id')
            ->flip()
            ->all();

        $failedTargetIds = $targetIds === [] ? [] : SeoRankCheckRun::query()
            ->whereIn('seo_rank_target_id', $targetIds)
            ->where('state', SeoRankRunState::FailedTerminal->value)
            ->where('failed_at', '>', $now->subDays(2))
            ->where('error_code', '!=', 'cancelled')
            ->get(['seo_rank_target_id', 'failed_at'])
            ->groupBy('seo_rank_target_id')
            ->map(fn ($runs) => $runs->max('failed_at'))
            ->all();

        $paused = $this->budget->isPausedBySpend($business, $now);
        $byKeyword = $targets->groupBy('seo_keyword_id');
        $rows = [];

        foreach ($keywords as $keyword) {
            $keywordTargets = $byKeyword->get($keyword->id, collect());

            if ($keywordTargets->isEmpty()) {
                $rows[] = $this->untrackedRow($keyword);

                continue;
            }

            foreach ($keywordTargets as $target) {
                $rows[] = $this->targetRow($keyword, $target, $summaries[$target->id] ?? [], isset($openTargetIds[$target->id]), $failedTargetIds[$target->id] ?? null, $paused);
            }
        }

        return ['rows' => $rows, 'summary' => $this->summary($business, $rows, $plan)];
    }

    /** @return array<string, mixed> */
    private function untrackedRow(SeoKeyword $keyword): array
    {
        return [
            'keyword' => $keyword,
            'target' => null,
            'state' => self::STATE_UNTRACKED,
            'location_label' => null,
            'organic' => null,
            'local' => null,
            'change' => ['kind' => SeoRankHistoryReader::CHANGE_NONE, 'amount' => null],
            'last_checked_at' => null,
            'unavailable' => false,
        ];
    }

    /**
     * @param array<string, array{current: SeoRankObservation|null, previous: SeoRankObservation|null, best: int|null}> $summary
     * @return array<string, mixed>
     */
    private function targetRow(SeoKeyword $keyword, SeoRankTarget $target, array $summary, bool $open, mixed $failedAt, bool $paused): array
    {
        $organic = $summary[SeoRankCheckType::Organic->value] ?? ['current' => null, 'previous' => null, 'best' => null];
        $local = $summary[SeoRankCheckType::Local->value] ?? ['current' => null, 'previous' => null, 'best' => null];
        $hasObservation = $organic['current'] !== null || $local['current'] !== null;

        $state = match (true) {
            $target->tracking_state === SeoRankTrackingState::Stopped => self::STATE_PAUSED,
            $open => self::STATE_CHECKING,
            ! $hasObservation && $paused => self::STATE_BUDGET_PAUSED,
            ! $hasObservation => self::STATE_WAITING,
            default => self::STATE_ACTIVE,
        };

        $lastChecked = $target->last_checked_at;
        $unavailable = $failedAt !== null && ($lastChecked === null || $failedAt > $lastChecked);

        return [
            'keyword' => $keyword,
            'target' => $target,
            'state' => $state,
            'location_label' => SeoRankLocationCatalog::label((string) $target->searchLocation?->location_name),
            'organic' => $organic['current'],
            'local' => $local['current'],
            'best_organic' => $organic['best'],
            'best_local' => $local['best'],
            'change' => SeoRankHistoryReader::change($organic['current'], $organic['previous']),
            'last_checked_at' => $lastChecked,
            'unavailable' => $unavailable,
            'budget_paused' => $paused && $target->tracking_state === SeoRankTrackingState::Tracking,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summary(Business $business, array $rows, ?SeoRankPlan $plan): array
    {
        $tracked = array_filter($rows, fn ($r) => $r['target'] !== null && $r['target']->tracking_state === SeoRankTrackingState::Tracking);
        $organic = array_filter(array_map(fn ($r) => $r['organic'] ?? null, $tracked));
        $local = array_filter(array_map(fn ($r) => $r['local'] ?? null, $tracked));
        $foundOrganic = array_filter($organic, fn (SeoRankObservation $o) => $o->isFound());
        $foundLocal = array_filter($local, fn (SeoRankObservation $o) => $o->isFound());

        return [
            'slots_used' => $this->budget->trackedTargetsQuery($business)->count(),
            'slots_limit' => $plan?->trackedTargets,
            // "—" when no tracked target has completed an organic check yet.
            'average_organic' => $organic === [] ? null : ($foundOrganic === [] ? null : round(array_sum(array_map(fn ($o) => $o->position, $foundOrganic)) / count($foundOrganic), 1)),
            'top10' => $organic === [] ? null : count(array_filter($foundOrganic, fn ($o) => $o->position <= 10)),
            'improved' => $organic === [] ? null : count(array_filter($tracked, fn ($r) => ($r['change']['kind'] ?? null) === SeoRankHistoryReader::CHANGE_UP)),
            'local_top3' => $local === [] ? null : count(array_filter($foundLocal, fn ($o) => $o->position <= 3)),
        ];
    }
}
