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
 * website.pages_hidden_from_search:v1 — the published HOME page is marked "do not show in
 * search results" (starter pages are generated hidden by design, so other hidden pages alone are not a finding). The platform-path `noindex` that every hosted site carries until a custom
 * domain exists is a STATUS, not something the owner did, and is never counted here: only pages
 * whose OWN setting hides them.
 */
final class WebsiteHiddenFromSearchRule extends AbstractGrowthRule
{
    public function definition(): GrowthRuleDefinition
    {
        return new GrowthRuleDefinition(
            key: 'website.pages_hidden_from_search:v1',
            worker: OpportunityWorkerKey::Website,
            category: GrowthCategory::Website,
            sourceModule: 'website',
            domain: 'website',
            scope: 'business',
            title: 'Some website pages are hidden from search',
            summary: 'Published pages are set not to appear in search results.',
            factKey: 'website_pages_hidden_from_search',
            evidenceSummary: 'Published pages whose own setting asks search engines not to list them.',
            actionKey: 'growth_review_search_visibility',
            actionLabel: 'Review website',
            target: 'website',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: 2,
            minSample: 1,
            why: 'A page hidden from search cannot bring in customers who are looking for you.',
            expected: 'If hiding them was a mistake, switching the setting back lets them appear in search over time.',
            goalKeys: [BusinessGoal::LocalSeo->value, BusinessGoal::WebsiteConversion->value],
        );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $website = $facts->set('website');
        $hidden = (int) $website->get('pages_hidden_from_search', 0);

        if (! $website->get('published') || ! $website->get('home_hidden_from_search')) {
            return [];
        }

        return [0 => [
            'impact' => 3, 'urgency' => 2, 'effort' => 1, 'confidence' => 1.0,
            'evidence' => ['count' => $hidden, 'pages' => (int) $website->get('page_count', 0)],
        ]];
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        return $facts->set('website')->get('published') ? 1 : 0;
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['visible' => true];
    }

    public function headline(array $evidence): string
    {
        return 'Your home page is set to stay out of search results.';
    }

    public function positiveStatement(array $positive): ?string
    {
        return 'Your published pages are open to search results.';
    }
}
