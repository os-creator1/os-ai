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
 * reviews.no_review_link:v1 and reviews.no_recent_requests:v1 — workflow
 * facts only. No rating, review count, sentiment, review gating or reward is
 * ever read or implied: the current Reviews product tracks a manual link per
 * Location and a ledger of requests, and nothing else.
 */
final class ReputationRules extends AbstractGrowthRule
{
    public function __construct(private readonly bool $requests = false)
    {
    }

    public static function noRecentRequests(): self
    {
        return new self(true);
    }

    public function definition(): GrowthRuleDefinition
    {
        return $this->requests
            ? new GrowthRuleDefinition(
                key: 'reviews.no_recent_requests:v1',
                worker: OpportunityWorkerKey::Reputation,
                category: GrowthCategory::Reviews,
                sourceModule: 'reviews',
                domain: 'reviews',
                scope: 'location',
                title: 'No review requests recorded recently',
                summary: 'This location has a review link but nobody has been asked for a review in a while.',
                factKey: 'no_recent_review_requests',
                evidenceSummary: 'Locations with a review link and no review request recorded in the recent window.',
                actionKey: 'growth_request_reviews',
                actionLabel: 'Ask for reviews',
                target: 'seo.reviews',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 2,
                minSample: 1,
                why: 'Recent reviews help new customers trust you. Happy customers rarely leave one unless they are asked.',
                expected: 'Asking recent customers gives them an easy way to share their experience. Whether they do is up to them.',
                goalKeys: [BusinessGoal::Reputation->value],
            )
            : new GrowthRuleDefinition(
                key: 'reviews.no_review_link:v1',
                worker: OpportunityWorkerKey::Reputation,
                category: GrowthCategory::Reviews,
                sourceModule: 'reviews',
                domain: 'reviews',
                scope: 'location',
                title: 'No review link is saved for this location',
                summary: 'Without a review link there is nothing to send customers when you ask for a review.',
                factKey: 'no_review_link',
                evidenceSummary: 'Active locations with no review link saved.',
                actionKey: 'growth_set_review_link',
                actionLabel: 'Add review link',
                target: 'seo.reviews',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 2,
                minSample: 1,
                why: 'A direct review link is the easiest thing to hand a happy customer.',
                expected: 'With a link saved, you can start asking customers for reviews.',
                goalKeys: [BusinessGoal::Reputation->value, BusinessGoal::LocalSeo->value],
            );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $out = [];

        foreach ($facts->set('reviews')->get('locations', []) as $locationId => $row) {
            if ($this->requests) {
                if ($row['has_link'] && $row['requests_in_window'] === 0) {
                    $out[(int) $locationId] = [
                        'impact' => 3, 'urgency' => 2, 'effort' => 2, 'confidence' => 1.0,
                        'evidence' => ['count' => 1, 'window_days' => $facts->thresholds->get('review_request_lookback_days')],
                    ];
                }

                continue;
            }

            if (! $row['has_link']) {
                $out[(int) $locationId] = [
                    'impact' => 3, 'urgency' => 2, 'effort' => 1, 'confidence' => 1.0,
                    'evidence' => ['count' => 1],
                ];
            }
        }

        return $out;
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        $locations = $facts->set('reviews')->get('locations', []);

        return $this->requests
            ? count(array_filter($locations, fn (array $l) => $l['has_link']))
            : count($locations);
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['locations' => $this->population($facts)];
    }

    public function headline(array $evidence): string
    {
        return $this->requests
            ? 'No review request has been recorded in the last ' . (int) ($evidence['window_days'] ?? 30) . ' days.'
            : 'This location has no review link saved.';
    }

    public function positiveStatement(array $positive): ?string
    {
        return $this->requests ? 'You have asked customers for reviews recently.' : 'A review link is saved for every location.';
    }
}
