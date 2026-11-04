<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthCategory;
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
    public function build(Business $business, int $actorUserId, string $workspaceUid, string $businessUid, bool $seoFirst = false): array
    {
        $viewer = GrowthViewer::resolve($this->locationGuard, $actorUserId, $business);
        // Home is the overview: it carries no Location labels (the detail page does),
        // which keeps its read budget flat.
        $top = $this->reader->top($business, $viewer, self::LIMIT);
        $items = $top
            ->map(fn (Opportunity $o) => $this->presenter->present($o, [], $workspaceUid, $businessUid))
            ->all();

        $head = self::preferredHead($top, $seoFirst);
        $promoted = null;

        // Growth plan only: the top rows hold no eligible SEO candidate, the queue is longer than what was read, and the first row is not
        // an operational lead-response problem — so look for the highest-priority one candidate beyond them (one narrow, same-filter query).
        if ($seoFirst && $head !== null && $head === $top->first() && ! self::isSeoCandidate($head) && self::category($head) !== GrowthCategory::LeadResponse && $top->count() >= self::LIMIT) {
            $beyond = $this->reader->topOfTypes($business, $viewer, self::seoRuleKeys());

            if ($beyond !== null) {
                $head = $beyond;
                // Presented exactly like a listed item so Home can enrich the move; it is NOT added to 'items' or 'open_count'.
                $promoted = $this->presenter->present($beyond, [], $workspaceUid, $businessUid);
            }
        }

        $latest = $viewer->maySeeScore() ? $this->scores->latest($business) : null;
        $movement = $latest !== null ? $this->scores->movement($latest, $this->scores->baseline($business, $latest)) : null;
        $route = fn (string $name) => route('customer.workspaces.businesses.growth.' . $name, [$workspaceUid, $businessUid]);

        return [
            'items' => $items,
            // The raw head of the same, ACL-scoped read — the one candidate the Home move selector
            // weighs against status exceptions. Never rendered; Home's primary move is chosen by
            // NextBestMoveSelector, not by this band.
            'head' => $head,
            'promoted' => $promoted,
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

    /**
     * The one candidate the Home move selector weighs. Growth plan only ($seoFirst): the highest-priority open website / SEO /
     * local-visibility / reputation opportunity among the ALREADY-read, ACL-scoped, already-ordered top rows — no new read and no
     * re-scoring. A top row that is a lead-response problem is operational and keeps its place; with no eligible SEO row (or the
     * flag off) the head is the queue's first row, exactly as before. Only opportunities that exist (i.e. have evidence) can be chosen.
     *
     * @param  \Illuminate\Support\Collection<int, Opportunity>  $top
     */
    public static function preferredHead($top, bool $seoFirst): ?Opportunity
    {
        $first = $top->first();

        if (! $seoFirst || $first === null || self::category($first) === GrowthCategory::LeadResponse) {
            return $first;
        }

        return $top->first(fn (Opportunity $o): bool => self::isSeoCandidate($o)) ?? $first;
    }

    private const SEO_CATEGORIES = [GrowthCategory::Seo, GrowthCategory::Website, GrowthCategory::LocalPresence, GrowthCategory::Reviews];

    private static function isSeoCandidate(Opportunity $o): bool
    {
        return in_array(self::category($o), self::SEO_CATEGORIES, true);
    }

    /** @return array<int, string> */
    private static function seoRuleKeys(): array
    {
        $keys = [];

        foreach (GrowthRuleRegistry::all() as $key => $rule) {
            if (in_array($rule->definition()->category, self::SEO_CATEGORIES, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private static function category(Opportunity $o): ?GrowthCategory
    {
        return GrowthRuleRegistry::find((string) $o->type)?->definition()->category;
    }
}
