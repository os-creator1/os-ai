<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\Opportunity;

/**
 * The Growth Center as it appears ON Home (Home = Growth): the few things an
 * owner should look at first, what is going well, and the business health
 * score as a secondary figure. It adds no data and no rules — it is a thin
 * reader over the same Growth readers the deep pages use, so Home and the
 * Growth routes can never disagree.
 *
 * Location ACL is applied by GrowthOpportunityReader in SQL, exactly as on the
 * deep pages; a Location-restricted actor gets their own recommendations and no
 * Business-wide score or positives.
 */
final class GrowthHomeBand
{
    public const LIMIT = 3;

    public function __construct(
        private readonly GrowthOpportunityReader $reader,
        private readonly GrowthOpportunityPresenter $presenter,
        private readonly GrowthScoreReader $scores,
        private readonly LocationAccessGuard $locationGuard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Business $business, int $actorUserId, string $workspaceUid, string $businessUid): array
    {
        $viewer = GrowthViewer::resolve($this->locationGuard, $actorUserId, $business);
        // Home is the overview: it carries no Location labels (the detail page does),
        // which keeps its read budget flat.
        $items = $this->reader->top($business, $viewer, self::LIMIT)
            ->map(fn (Opportunity $o) => $this->presenter->present($o, [], $workspaceUid, $businessUid))
            ->all();

        $latest = $viewer->maySeeScore() ? $this->scores->latest($business) : null;
        $movement = $latest !== null ? $this->scores->movement($latest, $this->scores->baseline($business, $latest)) : null;
        $route = fn (string $name) => route('customer.workspaces.businesses.growth.' . $name, [$workspaceUid, $businessUid]);

        return [
            'items' => $items,
            // top() and this count read the SAME open set, so a short list IS the total: one read saved.
            'open_count' => count($items) < self::LIMIT
                ? count($items)
                : $this->reader->applyState($this->reader->base($business, $viewer), GrowthOpportunityReader::STATE_OPEN)->count(),
            'positives' => $latest !== null ? $this->scores->positives($latest, self::LIMIT) : [],
            'score' => $latest?->overall_score !== null ? (int) $latest->overall_score : null,
            'delta' => $movement['delta'] ?? null,
            'days' => $movement['days'] ?? null,
            'has_evaluation' => $latest !== null || $items !== [],
            'engine_enabled' => (bool) config('opportunity.enabled', false),
            'urls' => [
                'all' => $route('opportunities.index'),
                'score' => $route('score'),
                'advisor' => $route('advisor'),
            ],
        ];
    }
}
