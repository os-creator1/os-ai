<?php

namespace App\Library\Crm;

use App\Enums\Crm\CrmContactStatus;
use App\Enums\Crm\CrmOpportunityStatus;
use App\Library\Contacts\ContactDirectory;
use App\Models\Business;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The CRM board read model: one Business, one pipeline, its active stages as
 * columns and the deals in them.
 *
 * A FIXED NUMBER OF QUERIES, whatever the pipeline holds: the stages, one grouped
 * count/value per stage, the cards (at most CARDS_PER_STAGE per column, chosen by
 * a window function so a crowded column never starves the others), and the
 * contacts' names in two more. Every read is scoped by the Business.
 *
 * LOCATION AUTHORITY. When the board is read on behalf of a person
 * ($actorUserId), a deal at a Location that person may not reach is not in any
 * column, count, total, value sum or search match — CrmLocationScope pushes the
 * reach into the same SQL, so the query count is unchanged by rows.
 *
 * Closed deals whose stage has since been archived have no column to sit in;
 * when the filter asks for closed deals they are gathered under one trailing
 * "Archived stages" column instead of silently disappearing.
 */
final class CrmBoard
{
    public const CARDS_PER_STAGE = 50;

    public const ARCHIVED_COLUMN = 'archived';

    public function __construct(private readonly ContactDirectory $contacts)
    {
    }

    /** @return Collection<int, CrmPipeline> */
    public function pipelines(Business $business): Collection
    {
        return CrmPipeline::query()->forBusiness($business)->active()->orderBy('position')->orderBy('id')->get();
    }

    /**
     * @return array{
     *     columns: list<array{key: string, stage: ?CrmPipelineStage, name: string, count: int, value_minor: int, cards: list<array<string, mixed>>}>,
     *     total: int,
     *     currency: ?string
     * }
     */
    public function board(Business $business, CrmPipeline $pipeline, CrmBoardFilters $filters, ?int $actorUserId = null): array
    {
        $stages = CrmPipelineStage::query()
            ->where('business_id', $business->id)
            ->where('pipeline_id', $pipeline->id)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $totals = $this->filtered($business, $pipeline, $filters, $actorUserId)
            ->groupBy('stage_id')
            ->selectRaw('stage_id, count(*) as deals, coalesce(sum(value_minor), 0) as value_minor')
            ->get()
            ->keyBy('stage_id');

        $ranked = $this->filtered($business, $pipeline, $filters, $actorUserId)
            ->select('crm_opportunities.*')
            ->selectRaw('row_number() over (partition by stage_id order by stage_entered_at desc, id desc) as board_rank');

        $cards = CrmOpportunity::query()
            ->fromSub($ranked, 'crm_opportunities')
            ->where('board_rank', '<=', self::CARDS_PER_STAGE)
            ->orderBy('stage_entered_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $people = $this->contacts->summaries($business, $cards->pluck('contact_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(), $actorUserId);
        $activeIds = $stages->pluck('id')->map(fn ($id) => (int) $id)->all();

        $columns = [];

        foreach ($stages as $stage) {
            $columns[] = $this->column((string) $stage->uid, $stage, $stage->name, [$stage->id], $totals, $cards, $people);
        }

        $archivedIds = $totals->keys()->map(fn ($id) => (int) $id)->reject(fn (int $id) => in_array($id, $activeIds, true))->values()->all();

        if ($archivedIds !== []) {
            $columns[] = $this->column(self::ARCHIVED_COLUMN, null, 'Archived stages', $archivedIds, $totals, $cards, $people);
        }

        return [
            'columns' => $columns,
            'total' => (int) $totals->sum('deals'),
            'currency' => $business->currency_code,
        ];
    }

    /**
     * The header total for one column, e.g. "3 · USD 1,200". The ONE spelling of it:
     * the board renders it and the move endpoint returns it, so a canonical total
     * pasted into a column header reads exactly like the server-rendered one.
     */
    public static function totalLabel(int $count, int $valueMinor, ?string $currency): string
    {
        return $count . ($valueMinor > 0 ? ' · ' . CrmMoney::format($valueMinor, $currency) : '');
    }

    /**
     * Canonical count and value of the given stages under the board's current filters
     * — what the move endpoint returns so the browser can correct two header
     * numbers without re-rendering anything.
     *
     * @param  list<int>  $stageIds
     * @return array<int, array{count: int, value_minor: int}> keyed by stage id
     */
    public function stageTotals(Business $business, CrmPipeline $pipeline, CrmBoardFilters $filters, array $stageIds, ?int $actorUserId = null): array
    {
        $rows = $this->filtered($business, $pipeline, $filters, $actorUserId)
            ->whereIn('stage_id', $stageIds)
            ->groupBy('stage_id')
            ->selectRaw('stage_id, count(*) as deals, coalesce(sum(value_minor), 0) as value_minor')
            ->get()
            ->keyBy('stage_id');

        $totals = [];

        foreach ($stageIds as $id) {
            $totals[$id] = ['count' => (int) ($rows[$id]->deals ?? 0), 'value_minor' => (int) ($rows[$id]->value_minor ?? 0)];
        }

        return $totals;
    }

    /**
     * @param  list<int>  $stageIds
     * @param  Collection<int|string, object>  $totals
     * @param  Collection<int, CrmOpportunity>  $cards
     * @param  array<int, array{uid: string, name: ?string, phone: string}>  $people
     * @return array{key: string, stage: ?CrmPipelineStage, name: string, count: int, value_minor: int, cards: list<array<string, mixed>>}
     */
    private function column(string $key, ?CrmPipelineStage $stage, string $name, array $stageIds, Collection $totals, Collection $cards, array $people): array
    {
        $count = 0;
        $value = 0;

        foreach ($stageIds as $id) {
            $count += (int) ($totals[$id]->deals ?? 0);
            $value += (int) ($totals[$id]->value_minor ?? 0);
        }

        return [
            'key' => $key,
            'stage' => $stage,
            'name' => $name,
            'count' => $count,
            'value_minor' => $value,
            'cards' => $cards
                ->filter(fn (CrmOpportunity $deal) => in_array((int) $deal->stage_id, $stageIds, true))
                ->map(fn (CrmOpportunity $deal) => [
                    'uid' => (string) $deal->uid,
                    'title' => (string) $deal->title,
                    'value' => CrmMoney::format($deal->value_minor, $deal->currency_code),
                    'value_minor' => (int) $deal->value_minor,
                    'status' => $deal->status,
                    'contact_status' => $deal->contact_status,
                    'stage_entered_at' => $deal->stage_entered_at,
                    'contact' => $deal->contact_id !== null ? ($people[(int) $deal->contact_id] ?? null) : null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function filtered(Business $business, CrmPipeline $pipeline, CrmBoardFilters $filters, ?int $actorUserId): Builder
    {
        $query = CrmOpportunity::query()
            ->where('crm_opportunities.business_id', $business->id)
            ->where('crm_opportunities.pipeline_id', $pipeline->id);

        if ($actorUserId !== null) {
            app(CrmLocationScope::class)->restrict($query, $business, $actorUserId, 'crm_opportunities.location_id');
        }

        if ($filters->status !== CrmBoardFilters::STATUS_ALL) {
            $query->where('status', CrmOpportunityStatus::from($filters->status)->value);
        }

        if ($filters->contactStatus !== CrmBoardFilters::CONTACT_STATUS_ANY) {
            $query->where('contact_status', CrmContactStatus::from($filters->contactStatus)->value);
        }

        if ($filters->search !== '') {
            $like = '%' . addcslashes($filters->search, '%_\\') . '%';
            $matching = $this->contacts->matchingContactIds($business, $filters->search, $actorUserId);

            $query->where(function (Builder $where) use ($like, $matching) {
                $where->where('title', 'like', $like)->orWhereIn('contact_id', $matching);
            });
        }

        return $query;
    }
}
