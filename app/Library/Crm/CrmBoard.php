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
    public function board(Business $business, CrmPipeline $pipeline, CrmBoardFilters $filters): array
    {
        $stages = CrmPipelineStage::query()
            ->where('business_id', $business->id)
            ->where('pipeline_id', $pipeline->id)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $totals = $this->filtered($business, $pipeline, $filters)
            ->groupBy('stage_id')
            ->selectRaw('stage_id, count(*) as deals, coalesce(sum(value_minor), 0) as value_minor')
            ->get()
            ->keyBy('stage_id');

        $ranked = $this->filtered($business, $pipeline, $filters)
            ->select('crm_opportunities.*')
            ->selectRaw('row_number() over (partition by stage_id order by stage_entered_at desc, id desc) as board_rank');

        $cards = CrmOpportunity::query()
            ->fromSub($ranked, 'crm_opportunities')
            ->where('board_rank', '<=', self::CARDS_PER_STAGE)
            ->orderBy('stage_entered_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $people = $this->contacts->summaries($business, $cards->pluck('contact_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all());
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
                    'status' => $deal->status,
                    'contact_status' => $deal->contact_status,
                    'stage_entered_at' => $deal->stage_entered_at,
                    'contact' => $deal->contact_id !== null ? ($people[(int) $deal->contact_id] ?? null) : null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function filtered(Business $business, CrmPipeline $pipeline, CrmBoardFilters $filters): Builder
    {
        $query = CrmOpportunity::query()
            ->where('crm_opportunities.business_id', $business->id)
            ->where('crm_opportunities.pipeline_id', $pipeline->id);

        if ($filters->status !== CrmBoardFilters::STATUS_ALL) {
            $query->where('status', CrmOpportunityStatus::from($filters->status)->value);
        }

        if ($filters->contactStatus !== CrmBoardFilters::CONTACT_STATUS_ANY) {
            $query->where('contact_status', CrmContactStatus::from($filters->contactStatus)->value);
        }

        if ($filters->search !== '') {
            $like = '%' . addcslashes($filters->search, '%_\\') . '%';
            $matching = $this->contacts->matchingContactIds($business, $filters->search);

            $query->where(function (Builder $where) use ($like, $matching) {
                $where->where('title', 'like', $like)->orWhereIn('contact_id', $matching);
            });
        }

        return $query;
    }
}
