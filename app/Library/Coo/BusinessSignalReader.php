<?php

namespace App\Library\Coo;

use App\DTO\Coo\BusinessSignals;
use App\DTO\Coo\SignalMetric;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Dashboard\DashboardStatusReader;
use App\Models\Business;
use App\Repositories\Contracts\OpportunityRepository;

/**
 * Unified Business Home and COO Decision Engine contract §6.2 — S1.
 *
 * Composes existing seams into one normalized `BusinessSignals` DTO. It
 * contains no SQL formula of its own: every fact is read through the
 * canonical class that already owns that truth, so Home, the C-2 Next Best
 * Move selector and the AI eligibility/insight layers (AI-3) can never
 * disagree about a Business's numbers.
 *
 * Canonical seams composed, and nothing else:
 *
 *   - `DashboardStatusReader`        — wallet/billing, website, Google status
 *   - `BusinessAnalyticsQueries`     — the same B5 methods
 *                                      `BusinessDashboardAnalyticsPresenter`
 *                                      calls, for both the current and the
 *                                      previous period the caller supplies
 *   - `BusinessConversationReadModel` — Slice 2B's Conversations seam; the
 *                                      only permitted reader of `chat_boxes`
 *   - `OpportunityRepository`        — the RFC-002 work-queue head
 *
 * `chat_boxes` is never queried directly here (contract §1.2). "Replied" and
 * "awaiting reply" conversation facts are intentionally absent: no canonical
 * `BusinessConversationReadModel` method for either exists yet, and that
 * file's own only writer is Slice H-4 (contract §18) — adding one here would
 * create the exact cross-slice seam duplication this reader exists to
 * prevent. Unknown remains unknown until H-4 lands.
 */
final class BusinessSignalReader
{
    public function __construct(
        private readonly DashboardStatusReader $status,
        private readonly BusinessAnalyticsQueries $analytics,
        private readonly BusinessConversationReadModel $conversations,
        private readonly OpportunityRepository $opportunities,
    ) {
    }

    public function read(Business $business, AnalyticsDateRange $current, AnalyticsDateRange $previous): BusinessSignals
    {
        $businessId = (int) $business->id;

        $status = $this->status->forBusiness($businessId);

        $currentContacts = $this->analytics->contactKpis($business, $current)['kpis']->newInRange;
        $previousContacts = $this->analytics->contactKpis($business, $previous)['kpis']->newInRange;

        $currentMessages = $this->analytics->messageKpis($business, $current)['kpis']->inbound;
        $previousMessages = $this->analytics->messageKpis($business, $previous)['kpis']->inbound;

        $currentConversations = $this->conversations->startedCount($business, $current->startUtc, $current->endUtc);
        $previousConversations = $this->conversations->startedCount($business, $previous->startUtc, $previous->endUtc);

        $unreadConversations = $this->conversations->unreadCount($business);

        $opportunityQueueHead = $this->opportunities->topForCustomer($business, 1)->first();

        return new BusinessSignals(
            businessId: $businessId,
            status: $status,
            newContacts: new SignalMetric($currentContacts, $previousContacts),
            messagesReceived: new SignalMetric($currentMessages, $previousMessages),
            conversationsStarted: new SignalMetric($currentConversations, $previousConversations),
            unreadConversations: $unreadConversations,
            opportunityQueueHead: $opportunityQueueHead,
        );
    }
}
