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
 * website.not_published:v1 — no published Website, and no external site on the
 * Business profile either. A Business that markets from a site it hosts
 * elsewhere is NOT told its platform Website is unpublished.
 */
final class WebsiteNotPublishedRule extends AbstractGrowthRule
{
    public function definition(): GrowthRuleDefinition
    {
        return new GrowthRuleDefinition(
            key: 'website.not_published:v1',
            worker: OpportunityWorkerKey::Website,
            category: GrowthCategory::Website,
            sourceModule: 'website',
            domain: 'website',
            scope: 'business',
            title: 'Your website is not published',
            summary: 'Customers searching for you cannot find a live website yet.',
            factKey: 'website_not_published',
            evidenceSummary: 'The Business has no published Website and no external website on its profile.',
            actionKey: 'growth_publish_website',
            actionLabel: 'Open website',
            target: 'website',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: 3,
            minSample: 0,
            why: 'Most local customers check a website before they call or book. Without one you are invisible to them.',
            expected: 'A published website gives customers a place to find you, book and contact you.',
            goalKeys: [BusinessGoal::WebsiteConversion->value, BusinessGoal::LocalSeo->value, BusinessGoal::LeadGeneration->value],
        );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $website = $facts->set('website');

        if ($website->get('published') || $website->get('has_external_site')) {
            return [];
        }

        return [0 => [
            'impact' => 5, 'urgency' => 3, 'effort' => 3, 'confidence' => 1.0,
            'evidence' => ['count' => 1, 'website_exists' => (bool) $website->get('exists'), 'status' => $website->get('status')],
        ]];
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        // A site (platform or external) either exists or it does not: always judgeable.
        return 1;
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['published' => (bool) $facts->set('website')->get('published')];
    }

    public function headline(array $evidence): string
    {
        return ! empty($evidence['website_exists'])
            ? 'Your website is still a draft, so customers cannot see it.'
            : 'You do not have a published website yet.';
    }

    public function positiveStatement(array $positive): ?string
    {
        return $positive['published'] ? 'Your website is published.' : null;
    }
}
