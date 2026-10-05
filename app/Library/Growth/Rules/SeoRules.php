<?php

declare(strict_types=1);

namespace App\Library\Growth\Rules;

use App\Enums\Business\BusinessGoal;
use App\Enums\Growth\GrowthActionSafetyClass;
use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthMoney;
use App\Library\Growth\GrowthRuleDefinition;

/**
 * The SEO rules — each judged by the canonical SEO readers' outputs (see
 * GrowthSeoFactReader and GrowthRankFactReader), never by a score, an estimated
 * traffic figure or revenue: only what the published Website revision, the
 * audit rows and the STORED rank observations actually say.
 *
 *   seo.keywords_not_covered:v2   tracked keywords no published page mentions. ONE
 *                                 Business-wide finding: a keyword missing from the
 *                                 site is a site-wide content gap, so it is never
 *                                 split into one finding per Location (v1 did).
 *   seo.technical_findings:v1     the latest completed audit of the published site.
 *   seo.meaningful_rank_drop:v1   tracked keywords whose latest completed organic
 *                                 check is meaningfully worse than the one before.
 *   seo.rank_just_outside_top_10:v1  tracked keywords at organic position 11-20.
 *
 * NO DUPLICATE FOR ONE ROOT PROBLEM. A keyword the website never mentions is
 * reported once, as a coverage gap; the rank rules skip it. A keyword that has
 * dropped is reported by the drop rule only, not again as "just outside the top
 * 10". Rank rules use stored observations only: Growth never calls a provider.
 */
final class SeoRules extends AbstractGrowthRule
{
    private const COVERAGE = 'coverage';

    private const TECHNICAL = 'technical';

    private const RANK_DROP = 'rank_drop';

    private const RANK_NEAR_TOP = 'rank_near_top';

    /** Most phrases carried in one finding's evidence. */
    private const PHRASE_CAP = 5;

    public function __construct(private readonly string $kind = self::COVERAGE)
    {
    }

    public static function technical(): self
    {
        return new self(self::TECHNICAL);
    }

    public static function rankDrop(): self
    {
        return new self(self::RANK_DROP);
    }

    public static function rankJustOutsideTopTen(): self
    {
        return new self(self::RANK_NEAR_TOP);
    }

    public function definition(): GrowthRuleDefinition
    {
        return match ($this->kind) {
            self::TECHNICAL => new GrowthRuleDefinition(
                key: 'seo.technical_findings:v1',
                worker: OpportunityWorkerKey::Seo,
                category: GrowthCategory::Seo,
                sourceModule: 'seo',
                domain: 'seo',
                scope: 'business',
                title: 'Your website has technical SEO issues to fix',
                summary: 'The latest site audit found problems that can keep search engines from understanding your pages.',
                factKey: 'technical_seo_findings',
                evidenceSummary: 'Critical and warning findings from the latest audit of the published website.',
                actionKey: 'growth_fix_seo_findings',
                actionLabel: 'See audit',
                target: 'seo.audit',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 2,
                minSample: 1,
                why: 'Missing titles, descriptions or alt text make it harder for the right customers to find the right page.',
                expected: 'Fixing these makes your pages clearer to search engines. It does not guarantee a ranking.',
                goalKeys: [BusinessGoal::LocalSeo->value, BusinessGoal::WebsiteConversion->value],
            ),
            self::RANK_DROP => new GrowthRuleDefinition(
                key: 'seo.meaningful_rank_drop:v1',
                worker: OpportunityWorkerKey::Seo,
                category: GrowthCategory::Seo,
                sourceModule: 'rank',
                domain: 'rank',
                scope: 'location',
                title: 'Some keywords you track have dropped in Google',
                summary: 'These tracked keywords rank lower than at the previous check, or no longer appear in the results we check.',
                factKey: 'rank_drop',
                evidenceSummary: 'Tracked keywords whose latest completed Google check is meaningfully worse than the one before.',
                actionKey: 'growth_review_rank_drops',
                actionLabel: 'View keywords',
                target: 'seo.keywords',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 2,
                minSample: 1,
                why: 'A keyword that slips down the results is seen by fewer of the people searching for it.',
                expected: 'Reviewing the page for that keyword gives you a place to start. Rankings move for many reasons and a recovery is not guaranteed.',
                goalKeys: [BusinessGoal::LocalSeo->value],
            ),
            self::RANK_NEAR_TOP => new GrowthRuleDefinition(
                key: 'seo.rank_just_outside_top_10:v1',
                worker: OpportunityWorkerKey::Seo,
                category: GrowthCategory::Seo,
                sourceModule: 'rank',
                domain: 'rank',
                scope: 'location',
                title: 'Some keywords are just outside the first page of Google',
                summary: 'These tracked keywords rank between 11 and 20 for your website, so a small improvement could bring them onto the first page.',
                factKey: 'rank_just_outside_top_10',
                evidenceSummary: 'Tracked keywords whose latest completed Google check is between position 11 and 20.',
                actionKey: 'growth_improve_near_top_keywords',
                actionLabel: 'View keywords',
                target: 'seo.keywords',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 1,
                minSample: 1,
                why: 'Most people never look beyond the first page of results.',
                expected: 'Improving the page for that keyword may help it reach the first page. A better position is not guaranteed.',
                goalKeys: [BusinessGoal::LocalSeo->value],
            ),
            default => new GrowthRuleDefinition(
                key: 'seo.keywords_not_covered:v2',
                worker: OpportunityWorkerKey::Seo,
                category: GrowthCategory::Seo,
                sourceModule: 'seo',
                domain: 'seo',
                scope: 'business',
                title: 'Some keywords you track are not on your website',
                summary: 'These tracked keywords do not appear in the titles, descriptions or text of any published page that search engines can list.',
                factKey: 'keywords_not_covered',
                evidenceSummary: 'Active tracked keywords with no coverage in the published website.',
                actionKey: 'growth_cover_keywords',
                actionLabel: 'View keywords',
                target: 'seo.keywords',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 2,
                minSample: 1,
                why: 'A page that never mentions what customers search for is unlikely to appear for it.',
                expected: 'Adding the wording to a relevant page makes it possible to appear for that search. Rankings are not guaranteed.',
                goalKeys: [BusinessGoal::LocalSeo->value],
            ),
        };
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        return match ($this->kind) {
            self::TECHNICAL => $this->detectTechnical($facts),
            self::RANK_DROP => $this->detectRank($facts, 'drops', [], 3, 3, 3, 0.8),
            self::RANK_NEAR_TOP => $this->detectRank($facts, 'near_top', $this->droppedIds($facts), 2, 2, 3, 0.8),
            default => $this->detectCoverage($facts),
        };
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        $seo = $facts->set('seo');
        $rank = $facts->set('rank');

        return match ($this->kind) {
            self::TECHNICAL => $seo->get('audit_ran') ? 1 : 0,
            // A drop can only be judged where a fresh result AND an earlier one exist.
            self::RANK_DROP => (int) $rank->get('comparable_count', 0),
            self::RANK_NEAR_TOP => (int) $rank->get('judged_count', 0),
            default => $seo->get('coverage_known') ? (int) $seo->get('keyword_count', 0) : 0,
        };
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return match ($this->kind) {
            self::RANK_DROP => ['improved' => (int) $facts->set('rank')->get('improved_count', 0)],
            self::RANK_NEAR_TOP => ['top10' => (int) $facts->set('rank')->get('top10_count', 0)],
            default => ['covered' => (int) $facts->set('seo')->get('covered_count', 0)],
        };
    }

    /** @return array<int, array{impact: int, urgency: int, effort: int, confidence: float, evidence: array<string, mixed>}> */
    private function detectTechnical(GrowthFactSnapshot $facts): array
    {
        $seo = $facts->set('seo');
        $findings = $seo->get('audit_findings', ['critical' => 0, 'warning' => 0, 'rules' => []]);
        $total = $findings['critical'] + $findings['warning'];

        if (! $seo->get('audit_ran') || $total === 0) {
            return [];
        }

        return [0 => [
            'impact' => $findings['critical'] > 0 ? 4 : 2,
            'urgency' => 2, 'effort' => 3, 'confidence' => 1.0,
            'evidence' => ['count' => $total, 'critical' => $findings['critical'], 'warning' => $findings['warning'], 'rules' => $findings['rules']],
        ]];
    }

    /**
     * ONE Business-wide finding. A keyword missing from the published site is a
     * site-wide content gap (a Location-attributed keyword is checked against the
     * whole site), so it is counted once however many Locations repeat the phrase.
     * Only Business-wide keywords' phrases ride along: a Location's own keyword
     * text never appears on a finding every member of the Business can see.
     *
     * @return array<int, array{impact: int, urgency: int, effort: int, confidence: float, evidence: array<string, mixed>}>
     */
    private function detectCoverage(GrowthFactSnapshot $facts): array
    {
        $seo = $facts->set('seo');

        if (! $seo->get('coverage_known')) {
            return [];
        }

        $total = (int) $seo->get('not_covered_total', 0);

        if ($total === 0) {
            return [];
        }

        return [0 => [
            'impact' => 3, 'urgency' => 2, 'effort' => 3,
            // Coverage is a text match against the published pages; a page might
            // cover the idea in different words, so this is an inference.
            'confidence' => 0.8,
            // Phrases are owner-typed text; the engine's evidence validator
            // rejects markup outright, so anything carrying it is left out
            // (the count still includes it).
            'evidence' => ['count' => $total, 'phrases' => $this->cleanPhrases((array) $seo->get('not_covered_examples', []))],
        ]];
    }

    /**
     * One finding per Location (0 = Business-wide keywords), from the STORED
     * observations the rank reader summarised. A keyword the website never
     * mentions is the coverage rule's problem and is skipped here.
     *
     * @param  array<int, true>  $alsoSkip  keyword ids already explained by another rank finding
     * @return array<int, array{impact: int, urgency: int, effort: int, confidence: float, evidence: array<string, mixed>}>
     */
    private function detectRank(GrowthFactSnapshot $facts, string $factKey, array $alsoSkip, int $impact, int $urgency, int $effort, float $confidence): array
    {
        $uncovered = array_flip(array_map('intval', (array) $facts->set('seo')->get('not_covered_ids', [])));
        $byLocation = [];

        foreach ((array) $facts->set('rank')->get($factKey, []) as $row) {
            $id = (int) $row['id'];

            if (isset($uncovered[$id]) || isset($alsoSkip[$id])) {
                continue;
            }

            $key = (int) $row['location'];
            $byLocation[$key] ??= ['count' => 0, 'phrases' => []];
            $byLocation[$key]['count']++;
            $byLocation[$key]['phrases'][] = (string) $row['phrase'];
        }

        $out = [];

        foreach ($byLocation as $key => $row) {
            $out[$key] = [
                'impact' => $impact, 'urgency' => $urgency, 'effort' => $effort, 'confidence' => $confidence,
                'evidence' => ['count' => $row['count'], 'phrases' => $this->cleanPhrases($row['phrases'])],
            ];
        }

        return $out;
    }

    /** @return array<int, true> keyword ids the drop rule already reports */
    private function droppedIds(GrowthFactSnapshot $facts): array
    {
        $ids = [];

        foreach ((array) $facts->set('rank')->get('drops', []) as $row) {
            $ids[(int) $row['id']] = true;
        }

        return $ids;
    }

    /**
     * @param  array<int, mixed>  $phrases
     * @return array<int, string>
     */
    private function cleanPhrases(array $phrases): array
    {
        $clean = array_filter(array_map('strval', $phrases), fn (string $p) => $p === strip_tags($p));

        return array_slice(array_values($clean), 0, self::PHRASE_CAP);
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);

        return match ($this->kind) {
            self::TECHNICAL => GrowthMoney::plural($count, 'technical SEO issue was', 'technical SEO issues were') . ' found on your website.',
            self::RANK_DROP => GrowthMoney::plural($count, 'tracked keyword has', 'tracked keywords have') . ' dropped in Google since the previous check.',
            self::RANK_NEAR_TOP => GrowthMoney::plural($count, 'tracked keyword is', 'tracked keywords are') . ' just outside the first page of Google.',
            default => GrowthMoney::plural($count, 'tracked keyword is', 'tracked keywords are') . ' not mentioned on your published website.',
        };
    }

    public function positiveStatement(array $positive): ?string
    {
        return match ($this->kind) {
            self::RANK_DROP => ($positive['improved'] ?? 0) >= 1
                ? GrowthMoney::plural($positive['improved'], 'tracked keyword moved', 'tracked keywords moved') . ' up in Google since the previous check.'
                : null,
            self::RANK_NEAR_TOP => ($positive['top10'] ?? 0) >= 1
                ? GrowthMoney::plural($positive['top10'], 'tracked keyword is', 'tracked keywords are') . ' on the first page of Google.'
                : null,
            self::TECHNICAL => null,
            default => ($positive['covered'] ?? 0) >= 3
                ? GrowthMoney::plural($positive['covered'], 'tracked keyword appears', 'tracked keywords appear') . ' on your published website.'
                : null,
        };
    }
}
