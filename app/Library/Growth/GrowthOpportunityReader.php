<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Models\Business;
use App\Models\Opportunity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Growth Center's ONLY read path into `opportunities` (Growth Center §54).
 * Every query it issues is scoped to the Business, to the Growth workers'
 * Opportunities, and to the Locations the actor may see — and the scoping is
 * applied in SQL BEFORE any list, count or sum is computed, so a
 * Selected-Location staff member can never infer an inaccessible Location's
 * problems from a total.
 *
 * Business-wide Opportunities (location_id NULL) carry only Business-level
 * evidence by construction (see GrowthRuleDefinition scope), so they are
 * visible to every actor who may see the Growth Center at all.
 *
 * It reads; it never writes. Dismiss / snooze / reopen go through
 * OpportunityManager.
 */
final class GrowthOpportunityReader
{
    public const STATE_OPEN = 'open';

    public const STATE_HIGH = 'high_impact';

    public const STATE_NEW = 'new';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_SNOOZED = 'snoozed';

    public const STATE_RESOLVED = 'resolved';

    public const STATE_DISMISSED = 'dismissed';

    public const STATE_ALL = 'all';

    public const STATES = [
        self::STATE_OPEN, self::STATE_HIGH, self::STATE_NEW, self::STATE_IN_PROGRESS,
        self::STATE_SNOOZED, self::STATE_RESOLVED, self::STATE_DISMISSED, self::STATE_ALL,
    ];

    /** Rules whose evidence carries a canonical PIPELINE (deal) value. */
    private const PIPELINE_RULES = ['crm.unanswered_new_leads:v1', 'crm.stale_opportunities:v1', 'crm.high_value_stale_opportunities:v1'];

    /**
     * Rules whose evidence carries a canonical RECEIVABLE amount. failed_payment
     * is deliberately NOT summed: its item is also an unpaid/overdue balance,
     * so adding it would count the same money twice.
     */
    private const RECEIVABLE_RULES = ['documents.signed_unpaid:v1', 'payments.overdue_balance:v1'];

    private const CAP = 300;

    public function base(Business $business, GrowthViewer $viewer): Builder
    {
        $query = Opportunity::query()
            ->where('business_id', $business->id)
            ->whereIn('worker_key', GrowthRuleRegistry::workerKeys())
            ->whereIn('type', array_keys(GrowthRuleRegistry::all()));

        if (! $viewer->fullAccess) {
            // Ad spend and cost figures are Business-wide money data: a Location-restricted actor never sees them.
            $query->where('worker_key', '!=', OpportunityWorkerKey::Ads->value);

            $query->where(function (Builder $q) use ($viewer): void {
                $q->whereNull('location_id');

                if ($viewer->accessibleLocationIds !== []) {
                    $q->orWhereIn('location_id', $viewer->accessibleLocationIds);
                }
            });
        }

        return $query;
    }

    /** Apply one owner-facing state to a query. */
    public function applyState(Builder $query, string $state): Builder
    {
        $current = OpportunityFreshness::Current->value;
        $actionable = [OpportunityStatus::Open->value, OpportunityStatus::AwaitingApproval->value];

        return match ($state) {
            self::STATE_HIGH => $query->where('freshness', $current)->whereIn('status', $actionable)->where('impact', '>=', 4),
            self::STATE_NEW => $query->where('freshness', $current)->whereIn('status', $actionable)->where('first_detected_at', '>=', now()->subDays(7)),
            self::STATE_IN_PROGRESS => $query->where('freshness', $current)->where('status', OpportunityStatus::InProgress->value),
            self::STATE_SNOOZED => $query->where('freshness', $current)->where('status', OpportunityStatus::Snoozed->value),
            self::STATE_DISMISSED => $query->where('status', OpportunityStatus::Dismissed->value),
            self::STATE_RESOLVED => $query->where(function (Builder $q): void {
                $q->where('status', OpportunityStatus::Completed->value)
                    ->orWhere(function (Builder $q2): void {
                        $q2->where('freshness', OpportunityFreshness::Stale->value)
                            ->whereIn('status', [
                                OpportunityStatus::Open->value, OpportunityStatus::AwaitingApproval->value,
                                OpportunityStatus::InProgress->value, OpportunityStatus::Snoozed->value,
                            ]);
                    });
            }),
            self::STATE_ALL => $query,
            default => $query->where('freshness', $current)->whereIn('status', $actionable),
        };
    }

    /**
     * @param  array{state?: string, category?: string, module?: string, location?: string|int, q?: string}  $filters
     */
    public function paginate(Business $business, GrowthViewer $viewer, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->applyState($this->base($business, $viewer), $this->normalizeState($filters['state'] ?? null));

        $typeKeys = $this->typeKeysFor($filters);

        if ($typeKeys !== null) {
            $query->whereIn('type', $typeKeys === [] ? ['__none__'] : $typeKeys);
        }

        if (isset($filters['location']) && $filters['location'] !== '' && is_numeric($filters['location'])) {
            $query->where('location_id', (int) $filters['location']);
        }

        return $query
            ->orderByDesc('priority_score')
            ->orderByDesc('impact')
            ->orderBy('first_detected_at')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return \Illuminate\Support\Collection<int, Opportunity> */
    public function top(Business $business, GrowthViewer $viewer, int $limit = 5)
    {
        return $this->applyState($this->base($business, $viewer), self::STATE_OPEN)
            ->orderByDesc('priority_score')
            ->orderByDesc('impact')
            ->orderBy('first_detected_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function find(Business $business, GrowthViewer $viewer, string $uid): ?Opportunity
    {
        return $this->base($business, $viewer)->where('uid', $uid)->first();
    }

    /**
     * Counts and money for the header cards, computed ONLY over what the actor
     * may see. Money is reported separately for the two canonical kinds (deal
     * value waiting on the owner, and payments to collect), per single
     * currency; a mix of currencies reports no figure rather than a sum.
     *
     * @return array{
     *     open: int, high_impact: int, snoozed: int, resolved_this_month: int,
     *     pipeline: array{value_minor: int, currency: string, count: int}|null,
     *     receivables: array{value_minor: int, currency: string, count: int}|null
     * }
     */
    public function summary(Business $business, GrowthViewer $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $open = $this->applyState($this->base($business, $viewer), self::STATE_OPEN)->limit(self::CAP)->get(['type', 'impact', 'evidence']);

        $resolved = $this->applyState($this->base($business, $viewer), self::STATE_RESOLVED)
            ->where(function (Builder $q) use ($now): void {
                $q->where('completed_at', '>=', $now->startOfMonth())->orWhere('stale_at', '>=', $now->startOfMonth());
            })
            ->count();

        return [
            'open' => $open->count(),
            'high_impact' => $open->where('impact', '>=', 4)->count(),
            'snoozed' => $this->applyState($this->base($business, $viewer), self::STATE_SNOOZED)->count(),
            'resolved_this_month' => $resolved,
            'pipeline' => $this->money($open, self::PIPELINE_RULES),
            'receivables' => $this->money($open, self::RECEIVABLE_RULES),
        ];
    }

    /** @return array<string, int> counts per owner-facing state, for the filter chips */
    public function stateCounts(Business $business, GrowthViewer $viewer): array
    {
        $counts = [];

        foreach ([self::STATE_OPEN, self::STATE_HIGH, self::STATE_NEW, self::STATE_IN_PROGRESS, self::STATE_SNOOZED, self::STATE_RESOLVED, self::STATE_DISMISSED] as $state) {
            $counts[$state] = $this->applyState($this->base($business, $viewer), $state)->count();
        }

        return $counts;
    }

    /** The Locations that carry Growth Opportunities the actor may see (for the filter). */
    public function locationIds(Business $business, GrowthViewer $viewer): array
    {
        return $this->base($business, $viewer)->whereNotNull('location_id')->distinct()->pluck('location_id')->map(fn ($id) => (int) $id)->all();
    }

    public function normalizeState(?string $state): string
    {
        return in_array($state, self::STATES, true) ? $state : self::STATE_OPEN;
    }

    /**
     * Rule keys matching a category / module / search filter; null = no type filter.
     *
     * @return array<int, string>|null
     */
    private function typeKeysFor(array $filters): ?array
    {
        $category = $filters['category'] ?? '';
        $module = $filters['module'] ?? '';
        $search = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        if ($category === '' && $module === '' && $search === '') {
            return null;
        }

        $keys = [];

        foreach (GrowthRuleRegistry::all() as $key => $rule) {
            $d = $rule->definition();

            if ($category !== '' && $d->category->value !== $category) {
                continue;
            }

            if ($module !== '' && $d->sourceModule !== $module) {
                continue;
            }

            if ($search !== '' && ! str_contains(mb_strtolower($d->title . ' ' . $d->summary . ' ' . $d->category->label() . ' ' . $d->sourceModule), $search)) {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Opportunity>  $open
     * @param  array<int, string>  $ruleKeys
     * @return array{value_minor: int, currency: string, count: int}|null
     */
    private function money($open, array $ruleKeys): ?array
    {
        $sum = 0;
        $count = 0;
        $currencies = [];

        foreach ($open as $o) {
            if (! in_array($o->type, $ruleKeys, true)) {
                continue;
            }

            $value = $o->evidence[0]['observed_value'] ?? [];

            if (! isset($value['value_minor'], $value['currency'])) {
                continue;
            }

            $sum += (int) $value['value_minor'];
            $count += (int) ($value['count'] ?? 0);
            $currencies[$value['currency']] = true;
        }

        return ($sum > 0 && count($currencies) === 1)
            ? ['value_minor' => $sum, 'currency' => (string) array_key_first($currencies), 'count' => $count]
            : null;
    }
}
