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
use App\Library\Seo\SeoConfig;
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
    public const STATE_UNAVAILABLE = 'unavailable';
    public const STATE_ACTIVE = 'active';

    /**
     * Why a check can never run for a Business, in the words shown to its owner.
     * Organic rank is matched on the Website's ACTIVE PRIMARY domain only; a
     * local check needs a domain or a business phone number (SeoRankIdentity).
     */
    public const NO_DOMAIN_REASON = 'Connect your website domain so we can find your site in results';
    public const NO_IDENTITY_REASON = 'Connect your website domain or add a business phone number so we can find your business in results';

    public function __construct(
        private readonly SeoRankHistoryReader $history,
        private readonly SeoRankTrackingBudget $budget,
        private readonly SeoConfig $config,
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
        // Provider switched off / not configured: no check can run, and that is NOT a spend pause.
        $unavailable = ! $this->budget->enabled();
        $byKeyword = $targets->groupBy('seo_keyword_id');
        $rows = [];

        // What we can match our own listing against. Only needed when something is tracked.
        $identity = $targets->isEmpty() ? null : SeoRankIdentity::forBusiness($business);
        $staleAfterDays = $this->config->rankStaleAfterDays();

        foreach ($keywords as $keyword) {
            $keywordTargets = $byKeyword->get($keyword->id, collect());

            if ($keywordTargets->isEmpty()) {
                $rows[] = $this->untrackedRow($keyword);

                continue;
            }

            foreach ($keywordTargets as $target) {
                $row = $this->targetRow($keyword, $target, $summaries[$target->id] ?? [], isset($openTargetIds[$target->id]), $failedTargetIds[$target->id] ?? null, $paused, $unavailable);

                // A position is only as good as its last check: past the freshness
                // window it is kept visible but labelled "may be out of date".
                $row['stale_days'] = self::staleDays($target->last_checked_at, $staleAfterDays, $now);

                // No Active primary domain means organic rank can never be matched
                // (and no domain AND no phone means local can't be either): say why
                // instead of waiting forever.
                $row['organic_blocked'] = $identity !== null && ! $identity->canMatchOrganic();
                $row['local_blocked'] = $identity !== null && ! $identity->canMatchLocal();

                $rows[] = $row;
            }
        }

        $rows = self::sortByBestRank($rows);

        return ['rows' => $rows, 'summary' => $this->summary($business, $rows, $plan)];
    }

    /**
     * The best (lowest) real numeric position visible on a row: the better of its
     * Google search (organic) and Google Maps (local) results. Null when neither
     * is a FOUND position — "Not in top N", "Not matched", checking, waiting,
     * needs-domain and untracked rows have no rank, and absence is never a number.
     *
     * @param array<string, mixed> $row
     */
    public static function bestRank(array $row): ?int
    {
        $positions = [];

        foreach (['organic', 'local'] as $key) {
            $obs = $row[$key] ?? null;

            if ($obs instanceof SeoRankObservation && $obs->isFound()) {
                $positions[] = (int) $obs->position;
            }
        }

        return $positions === [] ? null : min($positions);
    }

    /**
     * Best performers first. Sort key, in order:
     *   1. rows with a real numeric rank, ascending by best rank (1 is best);
     *   2. rows still checking or waiting for their first result;
     *   3. everything else with no rank (not in top N, not matched, needs domain,
     *      paused, unavailable, SEO-target only).
     * Ties, and rows inside groups 2 and 3, keep their incoming (created) order.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sortByBestRank(array $rows): array
    {
        $keyed = [];

        foreach (array_values($rows) as $index => $row) {
            $best = self::bestRank($row);
            $pending = in_array($row['state'] ?? null, [self::STATE_CHECKING, self::STATE_WAITING], true);
            $keyed[] = [$best === null ? ($pending ? 1 : 2) : 0, $best ?? 0, $index, $row];
        }

        usort($keyed, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(fn (array $k) => $k[3], $keyed);
    }

    /**
     * Whole days since the last completed check when that is past the freshness
     * window (config seo.rank_tracking.stale_after_days), else null. Never
     * called for a target that has not been checked: no date, no staleness.
     */
    public static function staleDays(?\DateTimeInterface $lastChecked, int $staleAfterDays, ?CarbonImmutable $now = null): ?int
    {
        if ($lastChecked === null) {
            return null;
        }

        $now ??= CarbonImmutable::now('UTC');
        $last = CarbonImmutable::instance($lastChecked);

        return $last < $now->subDays($staleAfterDays) ? (int) abs($last->diffInDays($now)) : null;
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
            'stale_days' => null,
            'organic_blocked' => false,
            'local_blocked' => false,
        ];
    }

    /**
     * @param array<string, array{current: SeoRankObservation|null, previous: SeoRankObservation|null, best: int|null}> $summary
     * @return array<string, mixed>
     */
    private function targetRow(SeoKeyword $keyword, SeoRankTarget $target, array $summary, bool $open, mixed $failedAt, bool $paused, bool $unavailable): array
    {
        $organic = $summary[SeoRankCheckType::Organic->value] ?? ['current' => null, 'previous' => null, 'best' => null];
        $local = $summary[SeoRankCheckType::Local->value] ?? ['current' => null, 'previous' => null, 'best' => null];
        $hasObservation = $organic['current'] !== null || $local['current'] !== null;

        $state = match (true) {
            $target->tracking_state === SeoRankTrackingState::Stopped => self::STATE_PAUSED,
            $open => self::STATE_CHECKING,
            ! $hasObservation && $unavailable => self::STATE_UNAVAILABLE,
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
        $trackedAll = array_filter($rows, fn ($r) => $r['target'] !== null && $r['target']->tracking_state === SeoRankTrackingState::Tracking);
        $hasResult = fn ($r) => ($r['organic'] ?? null) !== null || ($r['local'] ?? null) !== null;
        // The cards describe how the Business is doing NOW: a position older than the freshness
        // window is still shown on its row (labelled), but is not averaged in as if it were current.
        $tracked = array_filter($trackedAll, fn ($r) => ($r['stale_days'] ?? null) === null);
        $checked = count(array_filter($trackedAll, $hasResult));
        $fresh = count(array_filter($tracked, $hasResult));
        $organic = array_filter(array_map(fn ($r) => $r['organic'] ?? null, $tracked));
        $local = array_filter(array_map(fn ($r) => $r['local'] ?? null, $tracked));
        $foundOrganic = array_filter($organic, fn (SeoRankObservation $o) => $o->isFound());
        $foundLocal = array_filter($local, fn (SeoRankObservation $o) => $o->isFound());

        $bestOf = function (array $rows, string $key): ?array {
            $best = null;

            foreach ($rows as $r) {
                $obs = $r[$key] ?? null;

                if ($obs instanceof SeoRankObservation && $obs->isFound() && ($best === null || $obs->position < $best['position'])) {
                    $best = ['position' => (int) $obs->position, 'phrase' => (string) $r['keyword']->phrase];
                }
            }

            return $best;
        };

        return [
            'slots_used' => $this->budget->trackedTargetsQuery($business)->count(),
            'slots_limit' => $plan?->trackedTargets,
            // "—" when no tracked target has completed an organic check yet.
            'average_organic' => $organic === [] ? null : ($foundOrganic === [] ? null : round(array_sum(array_map(fn ($o) => $o->position, $foundOrganic)) / count($foundOrganic), 1)),
            'top10' => $organic === [] ? null : count(array_filter($foundOrganic, fn ($o) => $o->position <= 10)),
            'improved' => $organic === [] ? null : count(array_filter($tracked, fn ($r) => ($r['change']['kind'] ?? null) === SeoRankHistoryReader::CHANGE_UP)),
            'local_top3' => $local === [] ? null : count(array_filter($foundLocal, fn ($o) => $o->position <= 3)),
            // The single best result across tracked keywords, with the phrase that earned it.
            'best_local' => $bestOf($tracked, 'local'),
            'best_organic' => $bestOf($tracked, 'organic'),
            // "Based on N of M keywords checked recently", only when some results are stale.
            'basis' => $fresh < $checked ? ['fresh' => $fresh, 'checked' => $checked] : null,
        ];
    }
}
