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
 * citations.needs_attention:v1 and citations.directories_not_checked:v1.
 * "Not checked" and "mismatch" are separate findings by design: a directory
 * nobody has looked at is never reported as a mismatch (Growth Center §23).
 */
final class CitationRules extends AbstractGrowthRule
{
    public function __construct(private readonly bool $notChecked = false)
    {
    }

    public static function notChecked(): self
    {
        return new self(true);
    }

    public function definition(): GrowthRuleDefinition
    {
        return $this->notChecked
            ? new GrowthRuleDefinition(
                key: 'citations.directories_not_checked:v1',
                worker: OpportunityWorkerKey::Seo,
                category: GrowthCategory::LocalPresence,
                sourceModule: 'citations',
                domain: 'citations',
                scope: 'location',
                title: 'Important directories have not been checked',
                summary: 'You have not recorded whether your business is listed correctly in these directories.',
                factKey: 'directories_not_checked',
                evidenceSummary: 'Priority directories with no citation recorded, or still being set up, for this location.',
                actionKey: 'growth_check_directories',
                actionLabel: 'Check directories',
                target: 'seo.citations',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 1,
                minSample: 1,
                why: 'Customers find local businesses in directories. Consistent listings help them trust the details they find.',
                expected: 'Checking tells you whether your name, phone and address are listed correctly.',
                goalKeys: [BusinessGoal::LocalSeo->value],
            )
            : new GrowthRuleDefinition(
                key: 'citations.needs_attention:v1',
                worker: OpportunityWorkerKey::Seo,
                category: GrowthCategory::LocalPresence,
                sourceModule: 'citations',
                domain: 'citations',
                scope: 'location',
                title: 'A directory listing does not match your details',
                summary: 'The name, phone or address you recorded for a directory differs from your business details, or the listing is marked as needing correction.',
                factKey: 'citations_need_attention',
                evidenceSummary: 'Recorded directory listings that differ from the business name, phone or address, or are marked as needing correction.',
                actionKey: 'growth_fix_citations',
                // Business OS cannot edit a directory listing; the owner reviews it on the Citations page.
                actionLabel: 'Review listings',
                target: 'seo.citations',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 2,
                minSample: 1,
                why: 'Customers who find the wrong phone number or address may never reach you.',
                expected: 'Once you correct the listing on the directory, the details customers see are consistent.',
                goalKeys: [BusinessGoal::LocalSeo->value, BusinessGoal::Reputation->value],
            );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $out = [];

        foreach ($facts->set('citations')->get('locations', []) as $locationId => $row) {
            $count = $this->notChecked ? $row['not_checked'] : $row['needs_attention'];

            if ($count === 0) {
                continue;
            }

            $out[(int) $locationId] = [
                'impact' => $this->notChecked ? 2 : 3,
                'urgency' => $this->notChecked ? 1 : 2,
                'effort' => 2,
                'confidence' => 1.0,
                'evidence' => ['count' => $count, 'directories' => $row['directories']],
            ];
        }

        return $out;
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        $locations = $facts->set('citations')->get('locations', []);

        if ($this->notChecked) {
            return count($locations);
        }

        // "Nothing conflicts" only means something where at least one listing
        // was actually recorded — an all-unchecked Location says nothing.
        return count(array_filter($locations, fn (array $l) => $l['directories'] - $l['not_checked'] > 0));
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['locations' => $this->population($facts)];
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);

        return $this->notChecked
            ? GrowthMoney::plural($count, 'priority directory has', 'priority directories have') . ' not been checked.'
            : GrowthMoney::plural($count, 'directory listing needs', 'directory listings need') . ' attention.';
    }

    public function positiveStatement(array $positive): ?string
    {
        return $this->notChecked ? null : 'No recorded directory listing conflicts with your business details.';
    }
}
