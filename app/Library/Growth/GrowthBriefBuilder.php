<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Models\Business;
use App\Models\Opportunity;

/**
 * The Daily Brief (Growth Center §13, §39): a PRESENTATION of the same
 * canonical truth the rest of the Growth Center shows, never a second
 * recommendation engine —
 *
 *   top open Opportunities   (the engine's own priority order)
 *   + what's working         (the latest score snapshot's positive facts)
 *   + what changed           (stored period figures and checks that flipped)
 *
 * It needs no automation and no AI, and it is built per actor: a
 * Location-restricted viewer gets their own Opportunities and no Business-wide
 * positives or changes (those are aggregates over Locations they may not see).
 */
final class GrowthBriefBuilder
{
    public function __construct(
        private readonly GrowthOpportunityReader $reader,
        private readonly GrowthOpportunityPresenter $presenter,
        private readonly GrowthScoreReader $scores,
    ) {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, positives: array<int, string>, changes: array<int, array{kind: string, text: string}>, open_count: int, has_evaluation: bool}
     */
    public function build(Business $business, GrowthViewer $viewer, string $workspaceUid, string $businessUid, int $limit = 3): array
    {
        $names = GrowthOpportunityPresenter::locationNames($business->id);
        $items = $this->reader->top($business, $viewer, $limit)
            ->map(fn (Opportunity $o) => $this->presenter->present($o, $names, $workspaceUid, $businessUid))
            ->all();

        $latest = $viewer->maySeeScore() ? $this->scores->latest($business) : null;
        $baseline = $latest !== null ? $this->scores->baseline($business, $latest) : null;

        return [
            'items' => $items,
            'positives' => $latest !== null ? $this->scores->positives($latest, 3) : [],
            'changes' => $latest !== null ? $this->scores->changes($latest, $baseline) : [],
            'open_count' => $this->reader->summary($business, $viewer)['open'],
            'has_evaluation' => $latest !== null || $items !== [],
        ];
    }
}
