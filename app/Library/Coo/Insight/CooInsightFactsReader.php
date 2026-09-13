<?php

namespace App\Library\Coo\Insight;

use App\Enums\Dashboard\AttentionType;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Coo\BusinessSignalReader;
use App\Library\Dashboard\BusinessHomePresenter;
use App\Library\Opportunity\OpportunityTypeRegistry;
use App\Models\Business;
use App\Models\Opportunity;
use App\Repositories\Contracts\OpportunityRepository;

/**
 * Slice AI-3 — the facts of one COO insight, for one Business and one Business
 * performance window, composed from canonical seams only.
 *
 * The three metrics and their classification are C-3's BusinessSignals, read
 * through BusinessSignalReader for exactly the window Home compares (the
 * selected window and the equal-length one before it). The raised Attention
 * types are BusinessHomePresenter::raisedAttentionTypes() over the same status
 * row, 2B's awaiting-reply count and B5's automation failures — the Home's own
 * rule. The Opportunity facts are the RFC-002 work queue. No SQL of its own,
 * no metric of its own.
 *
 * Opportunity evidence is reduced to its fact keys and the registry's static
 * wording for each: the stored observed value is never read, so nothing a
 * customer typed can reach a prompt this way.
 */
final class CooInsightFactsReader
{
    public function __construct(
        private readonly BusinessSignalReader $signals,
        private readonly BusinessConversationReadModel $conversations,
        private readonly BusinessAnalyticsQueries $analytics,
        private readonly OpportunityRepository $opportunities,
    ) {
    }

    public function read(Business $business, AnalyticsDateRange $current): CooInsightFacts
    {
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $previous = BusinessDashboardAnalyticsPresenter::ranges($timezone, null, $current)['previous'];

        $signals = $this->signals->read($business, $current, $previous);

        $automation = $this->analytics->automationKpis($business, $current);
        $awaiting = $this->conversations->awaitingReplyCount($business);

        $attention = array_map(
            fn (AttentionType $type): string => $type->value,
            BusinessHomePresenter::raisedAttentionTypes($signals->status, $automation?->failed() ?? 0, $awaiting),
        );
        $attention = array_values(array_unique($attention));
        sort($attention);

        $opportunities = [];

        foreach ($this->opportunities->topForCustomer($business, BusinessHomePresenter::RECOMMENDATION_QUEUE_CAP) as $opportunity) {
            $opportunities[] = $this->opportunityFacts($opportunity, $current);
        }

        usort($opportunities, fn (array $a, array $b): int => [$a['type'], $a['first_detected_in_period']] <=> [$b['type'], $b['first_detected_in_period']]);

        return new CooInsightFacts(
            businessId: (int) $business->id,
            periodKey: $current->cacheKey(),
            periodLabel: $current->label(),
            currentStart: $current->startLocal->format('Y-m-d'),
            currentEnd: $current->endLocal->format('Y-m-d'),
            previousStart: $previous->startLocal->format('Y-m-d'),
            previousEnd: $previous->endLocal->format('Y-m-d'),
            metrics: [
                'new_contacts' => $this->metric($signals->newContacts->current, $signals->newContacts->previous, $signals->newContacts->direction()->value),
                'conversations_started' => $this->metric($signals->conversationsStarted->current, $signals->conversationsStarted->previous, $signals->conversationsStarted->direction()->value),
                'messages_received' => $this->metric($signals->messagesReceived->current, $signals->messagesReceived->previous, $signals->messagesReceived->direction()->value),
            ],
            attention: $attention,
            opportunities: $opportunities,
            visibility: [
                'website' => $signals->status->websiteStatus,
                'google' => $signals->status->googleConnectionState,
            ],
        );
    }

    /** @return array{current: int, previous: int, direction: string} */
    private function metric(int $current, int $previous, string $direction): array
    {
        return ['current' => $current, 'previous' => $previous, 'direction' => $direction];
    }

    /** @return array{type: string, evidence_keys: array<int, string>, evidence: array<int, string>, first_detected_in_period: bool} */
    private function opportunityFacts(Opportunity $opportunity, AnalyticsDateRange $current): array
    {
        $type = (string) $opportunity->type;
        $workerKey = $opportunity->worker_key?->value ?? (string) $opportunity->worker_key;
        $templates = (array) (OpportunityTypeRegistry::get($workerKey, $type)['evidence_summary_templates'] ?? []);

        $keys = [];

        foreach (is_array($opportunity->evidence) ? $opportunity->evidence : [] as $item) {
            $key = is_array($item) ? ($item['fact_key'] ?? null) : null;

            // Only a key the registry itself defines: an unknown key carries no
            // trusted wording and is left out rather than passed through.
            if (is_string($key) && isset($templates[$key])) {
                $keys[] = $key;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        $detected = $opportunity->first_detected_at;

        return [
            'type' => $type,
            'evidence_keys' => $keys,
            'evidence' => array_values(array_map(fn (string $key): string => (string) $templates[$key], $keys)),
            'first_detected_in_period' => $detected !== null
                && $detected->greaterThanOrEqualTo($current->startUtc)
                && $detected->lessThan($current->endUtc),
        ];
    }
}
