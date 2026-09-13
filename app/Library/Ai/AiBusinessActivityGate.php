<?php

namespace App\Library\Ai;

use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Contract §8.1 / §10.3 C-8 (Correction 2) — the dormancy gate.
 *
 * Scheduled, Business-scoped product AI is never spent on a Business
 * nobody is using. "Dormant" is the contract's own list and nothing else:
 * no new contact, no conversation, no incoming message, no automation run
 * and no member visit in `config('coo.dormant_after_days', 30)` days.
 *
 * Every figure comes from the seam that already owns that table — B5's
 * BusinessAnalyticsQueries for contacts, received messages and automation
 * runs, the canonical BusinessConversationReadModel for conversations, and
 * H-2's own `business_home_visits` marker for a member being present. This
 * class adds no query of its own to any of those tables, and in particular
 * never reads chat_boxes directly: that door belongs to 2B, and H-4 owns
 * its evolution.
 *
 * The gate is deliberately generous — ANY one signal makes a Business
 * active. Refusing AI for a Business that is quietly working would be
 * worse than paying for one that is quietly idle.
 *
 * Two things are explicitly NOT gated here, per the contract:
 *  - a customer's own explicit interactive request, which is not scheduled
 *    work and is never refused for dormancy alone;
 *  - Workspace-level AI with no Business at all (agency prospecting),
 *    which has no Business whose dormancy could be asked about.
 */
final class AiBusinessActivityGate
{
    public function __construct(
        private readonly BusinessAnalyticsQueries $analytics,
        private readonly BusinessConversationReadModel $conversations,
    ) {
    }

    public function isDormant(Business $business, ?CarbonImmutable $now = null): bool
    {
        return ! $this->hasRecentActivity($business, $now);
    }

    /**
     * Any one canonical signal inside the window ends the enquiry — the
     * cheapest are asked first, and the method returns on the first hit.
     */
    public function hasRecentActivity(Business $business, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $days = max(1, (int) config('coo.dormant_after_days', 30));
        $since = $now->subDays($days);

        // Contacts and incoming messages: one B5 statement for both.
        $counts = $this->analytics->countsBetween($business, $since, $now);

        if (($counts['newContacts'] ?? 0) > 0 || ($counts['messagesReceived'] ?? 0) > 0) {
            return true;
        }

        // Conversations, through the canonical read model and only through
        // it. A thread opened inside the window counts (startedCount), and so
        // does a customer writing again in a thread opened long before it
        // (incomingCount, which H-4 added to that same class): a running
        // conversation is the plainest evidence a Business is in use, and the
        // started-count alone would call such a Business dormant.
        if ($this->conversations->startedCount($business, $since, $now) > 0
            || $this->conversations->incomingCount($business, $since, $now) > 0) {
            return true;
        }

        // Automation runs. The seam returns null where the Business has no
        // automation surface at all, which is simply no evidence either way.
        $automation = $this->analytics->automationCountsBetween($business, $since, $now);

        if ($automation !== null && (((int) ($automation['completed'] ?? 0)) + ((int) ($automation['failed'] ?? 0))) > 0) {
            return true;
        }

        // A member being present, from H-2's visit marker: the canonical
        // record that somebody opened this Business's Home.
        return DB::table('business_home_visits')
            ->where('business_id', $business->id)
            ->where('current_visit_last_seen_at', '>=', $since)
            ->exists();
    }
}
