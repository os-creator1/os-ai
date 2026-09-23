<?php

namespace App\Library\Search\Contracts;

use App\Models\Business;
use App\Models\User;

/**
 * One domain Global Search searches (Blueprint §24): Contacts, Opportunities,
 * Conversations, transactional documents. A future domain joins by
 * implementing this and being tagged for GlobalSearchCoordinator — the same
 * extension shape TimelineSource already established for the Conversations
 * timeline.
 *
 * A source MUST:
 *   - read only rows of the given $business (every query `business_id = ?`);
 *   - check its own permission AND, where one applies, its own entitlement
 *     BEFORE running any row query — an unentitled or unpermitted actor gets
 *     an empty list, not a filtered one;
 *   - apply LocationAccessGuard per candidate row before it is ever returned,
 *     never after, and never in client-side script;
 *   - return at most $limit results;
 *   - return [] rather than guess when authorization cannot be confirmed.
 */
interface SearchSource
{
    /** @return list<\App\Library\Search\SearchResult> */
    public function search(Business $business, string $query, User $user, int $limit): array;
}
