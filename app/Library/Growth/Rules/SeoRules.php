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
 * seo.keywords_not_covered:v1 and seo.technical_findings:v1 — both judged by
 * the canonical SEO readers' outputs (see GrowthSeoFactReader). No visibility
 * "score", no estimated traffic or revenue: only what the published Website
 * revision and the audit rows actually say.
 */
final class SeoRules extends AbstractGrowthRule
{
    public function __construct(private readonly bool $technical = false)
    {
    }

    public static function technical(): self
    {
        return new self(true);
    }

    public function definition(): GrowthRuleDefinition
    {
        return $this->technical
            ? new GrowthRuleDefinition(
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
            )
            : new GrowthRuleDefinition(
                key: 'seo.keywords_not_covered:v1',
                worker: OpportunityWorkerKey::Seo,
                category: GrowthCategory::Seo,
                sourceModule: 'seo',
                domain: 'seo',
                scope: 'location',
                title: 'Some keywords you track are not on your website',
                summary: 'These tracked keywords do not appear in the titles, descriptions or text of any published page.',
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
            );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $seo = $facts->set('seo');

        if ($this->technical) {
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

        if (! $seo->get('coverage_known')) {
            return [];
        }

        $out = [];

        foreach ($seo->get('not_covered', []) as $locationKey => $row) {
            $out[(int) $locationKey] = [
                'impact' => 3, 'urgency' => 2, 'effort' => 3,
                // Coverage is a text match against the published pages; a page might
                // cover the idea in different words, so this is an inference.
                'confidence' => 0.8,
                // Phrases are owner-typed text; the engine's evidence validator
                // rejects markup outright, so anything carrying it is left out
                // (the count still includes it).
                'evidence' => ['count' => $row['count'], 'phrases' => array_values(array_filter($row['phrases'], fn (string $p) => $p === strip_tags($p)))],
            ];
        }

        return $out;
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        $seo = $facts->set('seo');

        if ($this->technical) {
            return $seo->get('audit_ran') ? 1 : 0;
        }

        return $seo->get('coverage_known') ? (int) $seo->get('keyword_count', 0) : 0;
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['covered' => (int) $facts->set('seo')->get('covered_count', 0)];
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);

        if ($this->technical) {
            return GrowthMoney::plural($count, 'technical SEO issue was', 'technical SEO issues were') . ' found on your website.';
        }

        return GrowthMoney::plural($count, 'tracked keyword is', 'tracked keywords are') . ' not mentioned on your published website.';
    }

    public function positiveStatement(array $positive): ?string
    {
        return ! $this->technical && $positive['covered'] >= 3
            ? GrowthMoney::plural($positive['covered'], 'tracked keyword appears', 'tracked keywords appear') . ' on your published website.'
            : null;
    }
}
