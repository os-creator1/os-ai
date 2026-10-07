<?php

declare(strict_types=1);

namespace App\Library\Growth\Rules;

use App\Enums\Business\BusinessGoal;
use App\Enums\Growth\GrowthActionSafetyClass;
use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthRuleDefinition;

/**
 * Keyword rank rules over STORED rank observations (domain `rank`). All Business-wide, all
 * read-only (they open the keywords page). Gains are never a finding: they are the positive
 * statement of the drop rule ("N keywords moved up").
 */
final class RankRules extends AbstractGrowthRule
{
    public const NEAR_TOP_10 = 'near_top10';
    public const DROP = 'drop';
    public const STALE = 'stale';
    public const UNTRACKED = 'untracked';

    public function __construct(private readonly string $kind = self::DROP)
    {
    }

    /** @return list<self> */
    public static function all(): array
    {
        return array_map(fn (string $k) => new self($k), [self::NEAR_TOP_10, self::DROP, self::STALE, self::UNTRACKED]);
    }

    public function definition(): GrowthRuleDefinition
    {
        $def = fn (string $key, string $title, string $summary, string $factKey, string $evidence, string $label, int $weight, int $minSample, string $why, string $expected, string $action) => new GrowthRuleDefinition(
            key: $key,
            worker: OpportunityWorkerKey::Seo,
            category: GrowthCategory::Seo,
            sourceModule: 'seo',
            domain: 'rank',
            scope: 'business',
            title: $title,
            summary: $summary,
            factKey: $factKey,
            evidenceSummary: $evidence,
            actionKey: $action,
            actionLabel: $label,
            target: 'seo.keywords',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: $weight,
            minSample: $minSample,
            why: $why,
            expected: $expected,
            goalKeys: [BusinessGoal::LocalSeo->value, BusinessGoal::LeadGeneration->value],
        );

        return match ($this->kind) {
            self::NEAR_TOP_10 => $def('seo.rank_just_outside_top_10:v1', 'Some keywords are close to the first page', 'A few tracked keywords rank just outside the top 10 results.', 'rank_near_top10', 'Tracked keywords whose latest organic position is 11 to 20.', 'View keywords', 1, 3, 'Results on page one get most of the clicks; positions 11 to 20 are one good improvement away.', 'Improving the matching page can move these onto the first page. Nothing is changed for you.', 'growth_review_near_top_keywords'),
            self::STALE => $def('seo.rank_data_stale:v1', 'Keyword rankings have not been checked lately', 'Some tracked keywords have not been re-checked for a while.', 'rank_stale', 'Tracking keywords not checked within the staleness window.', 'View keywords', 1, 1, 'Rankings you cannot see updating are rankings you cannot act on.', 'Once checks resume, movement shows up here again.', 'growth_check_rank_tracking'),
            self::UNTRACKED => $def('seo.keywords_not_tracked:v1', 'Your keywords are not tracked for ranking yet', 'You have search keywords but none is being tracked for its position in search results.', 'rank_untracked', 'Active keywords with no rank tracking.', 'Track keywords', 1, 1, 'Tracking shows whether your SEO work is moving you up or down.', 'Choosing keywords to track starts the checks. You pick which; nothing is tracked for you.', 'growth_track_keywords'),
            default => $def('seo.meaningful_rank_drop:v1', 'Some keywords dropped in search results', 'Tracked keywords fell several places or out of the results since the last check.', 'rank_drop', 'Tracked keywords that fell several positions or dropped out of the results.', 'View keywords', 3, 3, 'A fall in rankings usually means fewer people finding you.', 'Reviewing those pages shows what to fix. Nothing is changed for you.', 'growth_review_rank_drops'),
        };
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $rank = $facts->set('rank');

        [$key, $impact, $urgency, $effort] = match ($this->kind) {
            self::NEAR_TOP_10 => ['near_top10', 2, 1, 3],
            self::STALE => ['stale', 2, 2, 1],
            self::UNTRACKED => ['untracked', 2, 1, 2],
            default => ['drops', 4, 3, 2],
        };

        $count = (int) $rank->get($key, 0);

        if ($count === 0) {
            return [];
        }

        // Missing targets are only a finding while there are keywords to track and nothing tracked.
        if ($this->kind === self::UNTRACKED && (int) $rank->get('tracked', 0) > 0) {
            return [];
        }

        return [0 => ['impact' => $impact, 'urgency' => $urgency, 'effort' => $effort, 'confidence' => 1.0, 'evidence' => ['count' => $count]]];
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        $rank = $facts->set('rank');

        return match ($this->kind) {
            self::UNTRACKED => (int) $rank->get('keyword_count', 0),
            self::STALE => (int) $rank->get('tracked', 0),
            default => (int) $rank->get('observed', 0),
        };
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        if ($this->kind !== self::DROP) {
            return null;
        }

        $gains = (int) $facts->set('rank')->get('gains', 0);

        return $gains > 0 ? ['gains' => $gains] : null;
    }

    public function headline(array $evidence): string
    {
        $n = (int) ($evidence['count'] ?? 0);
        $s = $n === 1 ? '' : 's';

        return match ($this->kind) {
            self::NEAR_TOP_10 => sprintf('%d tracked keyword%s %s just outside the top 10.', $n, $s, $n === 1 ? 'is' : 'are'),
            self::STALE => sprintf('%d tracked keyword%s %s not been checked recently.', $n, $s, $n === 1 ? 'has' : 'have'),
            self::UNTRACKED => 'None of your keywords is being tracked for ranking yet.',
            default => sprintf('%d tracked keyword%s dropped in search results.', $n, $s),
        };
    }

    public function positiveStatement(array $positive): ?string
    {
        $n = (int) ($positive['gains'] ?? 0);

        return $n > 0 ? sprintf('%d tracked keyword%s moved up in search results.', $n, $n === 1 ? '' : 's') : null;
    }
}
