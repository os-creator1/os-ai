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

/** conversations.inbound_awaiting_reply:v1 — the customer wrote last, and the Business has not replied. */
final class ConversationsAwaitingReplyRule extends AbstractGrowthRule
{
    public function definition(): GrowthRuleDefinition
    {
        return new GrowthRuleDefinition(
            key: 'conversations.inbound_awaiting_reply:v1',
            worker: OpportunityWorkerKey::Sales,
            category: GrowthCategory::LeadResponse,
            sourceModule: 'conversations',
            domain: 'conversations',
            scope: 'location',
            title: 'Customer messages are waiting for a reply',
            summary: 'The customer sent the last message in these conversations and there has been no reply since.',
            factKey: 'conversations_awaiting_reply',
            evidenceSummary: 'Conversations whose newest message is from the customer, older than the reply window.',
            actionKey: 'growth_reply_to_conversations',
            actionLabel: 'Open conversations',
            target: 'conversations',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: 3,
            minSample: 3,
            why: 'A customer who writes and hears nothing back usually assumes you are not interested.',
            expected: 'Replying gives each customer an answer. Some of these may not need a reply, so use your judgement.',
            goalKeys: [BusinessGoal::LeadGeneration->value, BusinessGoal::SalesFollowup->value],
        );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $hours = $facts->thresholds->get('conversation_awaiting_hours');
        $out = [];

        foreach ($facts->set('conversations')->get('by_location', []) as $locationKey => $bucket) {
            $out[(int) $locationKey] = [
                'impact' => 4,
                'urgency' => 4,
                'effort' => 1,
                // A strong deterministic inference, not a direct fact: the
                // product cannot know whether a "thanks!" needs an answer.
                'confidence' => 0.8,
                'evidence' => $this->bucketEvidence($bucket, ['threshold_hours' => $hours, 'oldest_hours' => (int) $bucket['oldest_hours']]),
            ];
        }

        return $out;
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        return (int) $facts->set('conversations')->get('active_count', 0);
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['active' => $this->population($facts), 'hours' => $facts->thresholds->get('conversation_awaiting_hours')];
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);

        return GrowthMoney::plural($count, 'conversation has', 'conversations have')
            . ' had no reply for ' . (int) ($evidence['threshold_hours'] ?? 24) . '+ hours after the customer wrote.';
    }

    public function positiveStatement(array $positive): ?string
    {
        return $positive['active'] >= 3
            ? 'No customer message has been waiting more than ' . $positive['hours'] . ' hours for a reply.'
            : null;
    }
}
