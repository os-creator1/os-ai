<?php

namespace App\DTO\Coo;

use App\Library\Dashboard\BusinessStatusRow;
use App\Models\Opportunity;

/**
 * Unified Business Home and COO Decision Engine contract §6.2 — S1's one
 * normalized, read-only signal DTO for a single Business and a single
 * current/previous period pair.
 *
 * Every field is a canonical fact, sourced from an existing seam
 * (`BusinessSignalReader` composes them; this class holds no query of its
 * own and no formula). Home, the C-2 Next Best Move selector, the AI
 * eligibility check and the AI-3 cached insight all read this same DTO, so
 * none of them can disagree about what the Business's facts are.
 *
 * What is deliberately absent, because no canonical source exists for it
 * (contract §1.3): leads, bookings, revenue, conversions, Google ranking
 * facts, and any "replied" or "awaiting reply" conversation state — the
 * last two are `BusinessConversationReadModel` methods Slice H-4 owns, not
 * this one (contract §18, "BusinessConversationReadModel: H-4 is the only
 * writer"). Unknown remains unknown rather than approximated here.
 */
final class BusinessSignals
{
    public function __construct(
        public readonly int $businessId,
        public readonly BusinessStatusRow $status,
        public readonly SignalMetric $newContacts,
        public readonly SignalMetric $messagesReceived,
        public readonly SignalMetric $conversationsStarted,
        public readonly int $unreadConversations,
        public readonly ?Opportunity $opportunityQueueHead,
    ) {
    }
}
