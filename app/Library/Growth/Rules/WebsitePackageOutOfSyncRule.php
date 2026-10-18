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
 * website.package_out_of_sync:v1 — the published Website shows package details
 * that Packages & Products has since changed or removed.
 *
 * The verdict is the Website module's own (WebsiteCatalogReferences::staleness):
 * Growth only counts what that seam reports. Without a published Website there
 * is nothing to be out of sync, so the rule is not judgeable.
 */
final class WebsitePackageOutOfSyncRule extends AbstractGrowthRule
{
    public function definition(): GrowthRuleDefinition
    {
        return new GrowthRuleDefinition(
            key: 'website.package_out_of_sync:v1',
            worker: OpportunityWorkerKey::Website,
            category: GrowthCategory::Website,
            sourceModule: 'website',
            domain: 'website',
            scope: 'business',
            title: 'Your website shows out-of-date packages',
            summary: 'Package details on your live website no longer match Packages & Products.',
            factKey: 'website_package_out_of_sync',
            evidenceSummary: 'The published Website references packages that were changed or removed after it was published.',
            actionKey: 'growth_review_website_packages',
            actionLabel: 'Review website',
            target: 'website',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: 2,
            minSample: 1,
            why: 'Customers read your prices and package details on the website. If they are out of date, you may quote one thing and be asked about another.',
            expected: 'Once you update the website, what customers see matches what you actually offer.',
            goalKeys: [BusinessGoal::WebsiteConversion->value, BusinessGoal::LeadGeneration->value],
        );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $website = $facts->set('website');
        $changed = (int) $website->get('package_changed_count', 0);
        $removed = (int) $website->get('package_removed_count', 0);

        if (! $website->get('published') || $changed + $removed === 0) {
            return [];
        }

        return [0 => [
            'impact' => 3, 'urgency' => 2, 'effort' => 2, 'confidence' => 1.0,
            'evidence' => ['count' => $changed + $removed, 'changed' => $changed, 'removed' => $removed],
        ]];
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        return $facts->set('website')->get('published') ? 1 : 0;
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['in_sync' => true];
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);
        $removed = (int) ($evidence['removed'] ?? 0);

        return $removed > 0
            ? sprintf('%d package%s on your live website %s changed or removed since it was published.', $count, $count === 1 ? '' : 's', $count === 1 ? 'was' : 'were')
            : sprintf('%d package%s on your live website %s changed since it was published.', $count, $count === 1 ? '' : 's', $count === 1 ? 'has' : 'have');
    }

    public function positiveStatement(array $positive): ?string
    {
        return 'Your website matches your current packages.';
    }
}
