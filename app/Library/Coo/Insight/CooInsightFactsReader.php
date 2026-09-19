<?php

namespace App\Library\Coo\Insight;

use App\Enums\Dashboard\AttentionType;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Coo\BusinessSignalReader;
use App\Library\Coo\Context\CooContextEnvelope;
use App\Library\Dashboard\BusinessHomePresenter;
use App\Library\Dashboard\DashboardStatusReader;
use App\Library\Opportunity\OpportunityTypeRegistry;
use App\Models\Business;
use App\Models\Opportunity;
use App\Repositories\Contracts\BusinessLocationRepository;
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
 *
 * ---------------------------------------------------------------------------
 * Implementation Contract 19 §6.2 / R-7, sub-slice 19.B — WHOSE facts these are
 *
 * This reader now composes for ONE actor, described by a CooContextEnvelope,
 * and never composes a fact that actor may not read. CooFactAuthorization is
 * the single classifier; this class only obeys it.
 *
 * The mechanism is source selection, not post-filtering. When the actor's
 * authorized Location set does not cover the whole Business, every source that
 * reflects Location-bound data is NOT QUERIED — so the inaccessible Location is
 * unreadable and uncountable, rather than aggregated and then discarded. The
 * excluded keys are simply absent: no zeroes, no placeholders, no "not
 * available" note. Nothing in the facts, the fingerprint inputs or the prompt
 * says that anything was left out (R-7).
 *
 * WHAT IS LOCATION-BOUND, and the schema evidence for each claim:
 *  - new_contacts            `contacts.location_id`, enforced per Location by
 *                            ContactsController::locationAccessible().
 *  - conversations_started   `chat_boxes.location_id`, enforced per Location by
 *                            ChatBoxController.
 *  - messages_received       `reports` carries NO location column — and is
 *                            still treated as Location-bound. Every message is
 *                            to or from a Contact, and Contacts are
 *                            Location-bound; `reports` merely fails to record
 *                            the attribution, so the count cannot be PROVEN to
 *                            exclude an inaccessible Location's activity.
 *                            Absence of a column is not proof of safety.
 *  - opportunities           the RFC-002 `opportunities` table carries no
 *                            location column, but an opportunity's type and
 *                            evidence describe why it was raised, and that
 *                            vocabulary is source-controlled and grows. An
 *                            unclassifiable fact is an unproven fact, so the
 *                            queue is composed only at complete coverage.
 *  - attention              classified individually in CooFactAuthorization;
 *                            AutomationFailing and ConversationsAwaitingReply
 *                            are Location-bound in substance even though
 *                            their tables carry no location column.
 *
 * WHAT IS PROVABLY BUSINESS-WIDE-SAFE, and why:
 *  - visibility.website      `websites` is one row per Business with no
 *                            location column: published is a Business fact.
 *  - visibility.google       `business_google_connections` is the Business's
 *                            own OAuth link, no location column. (Its
 *                            per-Location sibling, business_google_locations,
 *                            is excluded — see CooFactAuthorization.)
 *  - the period window       dates and labels, derived from the request, carry
 *                            no customer data at all.
 */
final class CooInsightFactsReader
{
    public function __construct(
        private readonly BusinessSignalReader $signals,
        private readonly BusinessConversationReadModel $conversations,
        private readonly BusinessAnalyticsQueries $analytics,
        private readonly OpportunityRepository $opportunities,
        private readonly DashboardStatusReader $status,
        private readonly BusinessLocationRepository $locations,
    ) {
    }

    public function read(Business $business, AnalyticsDateRange $current, CooContextEnvelope $envelope): CooInsightFacts
    {
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $previous = BusinessDashboardAnalyticsPresenter::ranges($timezone, null, $current)['previous'];

        $authorization = CooFactAuthorization::resolve($envelope, $this->everyLocationIdOf($business));

        if ($authorization->mayComposeLocationBoundFacts()) {
            return $this->completeFacts($business, $current, $previous, $authorization);
        }

        return $this->businessWideFactsOnly($business, $current, $previous, $authorization);
    }

    /**
     * Complete coverage: the actor may read every Location, so a Business-wide
     * aggregate IS their aggregate and every source is composed exactly as
     * before 19.B.
     */
    private function completeFacts(
        Business $business,
        AnalyticsDateRange $current,
        AnalyticsDateRange $previous,
        CooFactAuthorization $authorization,
    ): CooInsightFacts {
        $signals = $this->signals->read($business, $current, $previous);
        $awaiting = $this->conversations->awaitingReplyCount($business);
        $automationFailures = $this->analytics->automationKpis($business, $current)?->failed() ?? 0;

        $attention = $authorization->permittedAttention(
            BusinessHomePresenter::raisedAttentionTypes($signals->status, $automationFailures, $awaiting)
        );

        $opportunities = [];

        foreach ($this->opportunities->topForCustomer($business, BusinessHomePresenter::RECOMMENDATION_QUEUE_CAP) as $opportunity) {
            $opportunities[] = $this->opportunityFacts($opportunity, $current);
        }

        usort($opportunities, fn (array $a, array $b): int => [$a['type'], $a['first_detected_in_period']] <=> [$b['type'], $b['first_detected_in_period']]);

        return $this->facts(
            business: $business,
            current: $current,
            previous: $previous,
            metrics: [
                'new_contacts' => $this->metric($signals->newContacts->current, $signals->newContacts->previous, $signals->newContacts->direction()->value),
                'conversations_started' => $this->metric($signals->conversationsStarted->current, $signals->conversationsStarted->previous, $signals->conversationsStarted->direction()->value),
                'messages_received' => $this->metric($signals->messagesReceived->current, $signals->messagesReceived->previous, $signals->messagesReceived->direction()->value),
            ],
            attention: $attention,
            opportunities: $opportunities,
            websiteStatus: $signals->status->websiteStatus,
            googleState: $signals->status->googleConnectionState,
        );
    }

    /**
     * Partial (or no) coverage: the actor may read some Locations but not all,
     * so no source that reflects Location-bound data is touched.
     *
     * BusinessSignalReader is deliberately NOT called here. It reads contacts,
     * conversations, messages and the Opportunity queue head in one pass, and
     * calling it would compute exactly the numbers this actor may not have —
     * "computed and discarded" is still counted. Neither is the automation KPI
     * read, for the same reason. Only the per-Business status row is read,
     * through the same canonical seam BusinessSignalReader itself uses, so the
     * two paths can never disagree about what the status says.
     */
    private function businessWideFactsOnly(
        Business $business,
        AnalyticsDateRange $current,
        AnalyticsDateRange $previous,
        CooFactAuthorization $authorization,
    ): CooInsightFacts {
        $status = $this->status->forBusiness((int) $business->id);

        // Awaiting-reply (chat_boxes.location_id) and automation failures
        // (automation_executions.contact_id -> contacts.location_id) are both
        // Location-bound, so neither is read: zero says "this input
        // contributed nothing". The Attention filter below independently drops
        // both types as well, so a future edit that made either raisable some
        // other way still could not narrate them.
        $attention = $authorization->permittedAttention(
            BusinessHomePresenter::raisedAttentionTypes($status, 0, 0)
        );

        return $this->facts(
            business: $business,
            current: $current,
            previous: $previous,
            // No metrics at all. Absent, not zeroed: a zero would itself be a
            // claim about Locations this actor cannot read (R-7).
            metrics: [],
            attention: $attention,
            opportunities: [],
            websiteStatus: $status->websiteStatus,
            googleState: $status->googleConnectionState,
        );
    }

    /**
     * Every Location of the Business, read once. This is the set the actor's
     * authorization is measured against; it is never used to widen anything,
     * only to decide whether the actor's own set already covers it.
     *
     * @return array<int, int>
     */
    private function everyLocationIdOf(Business $business): array
    {
        return $this->locations->forBusiness($business)
            ->map(static fn (mixed $location): int => (int) $location->id)
            ->all();
    }

    /**
     * @param  array<string, array{current: int, previous: int, direction: string}>  $metrics
     * @param  array<int, AttentionType>  $attention
     * @param  array<int, array<string, mixed>>  $opportunities
     */
    private function facts(
        Business $business,
        AnalyticsDateRange $current,
        AnalyticsDateRange $previous,
        array $metrics,
        array $attention,
        array $opportunities,
        ?string $websiteStatus,
        ?string $googleState,
    ): CooInsightFacts {
        $attentionValues = array_map(static fn (AttentionType $type): string => $type->value, $attention);
        $attentionValues = array_values(array_unique($attentionValues));
        sort($attentionValues);

        return new CooInsightFacts(
            businessId: (int) $business->id,
            periodKey: $current->cacheKey(),
            periodLabel: $current->label(),
            currentStart: $current->startLocal->format('Y-m-d'),
            currentEnd: $current->endLocal->format('Y-m-d'),
            previousStart: $previous->startLocal->format('Y-m-d'),
            previousEnd: $previous->endLocal->format('Y-m-d'),
            metrics: $metrics,
            attention: $attentionValues,
            opportunities: $opportunities,
            visibility: [
                'website' => $websiteStatus,
                'google' => $googleState,
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
