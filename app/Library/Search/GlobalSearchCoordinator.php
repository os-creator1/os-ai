<?php

namespace App\Library\Search;

use App\Models\Business;
use App\Models\User;

/**
 * Blueprint §7/§24 — the top-bar Global Search: Contacts, Opportunities,
 * Conversations and transactional documents (Contract 17 §12.G), searched
 * inside ONE currently-selected Business only. Holds no policy of its own —
 * every domain's permission, entitlement and Location check lives in its own
 * SearchSource, exactly as MenuEntitlements holds none of EntitlementManager's.
 *
 * A future domain joins by implementing SearchSource and being added here —
 * the same shape ContactActivityTimeline already established for timeline
 * sources.
 */
final class GlobalSearchCoordinator
{
    /** Which switchable module owns each search domain (documents belong to no switchable module). */
    private const MODULE_BY_SOURCE = [
        'contacts' => \App\Enums\Workspace\WorkspaceMemberModule::Contacts,
        'opportunities' => \App\Enums\Workspace\WorkspaceMemberModule::Crm,
        'conversations' => \App\Enums\Workspace\WorkspaceMemberModule::Conversations,
    ];

    /** Results per domain. Small and bounded — a top-bar dropdown, not a search page. */
    public const PER_SOURCE_LIMIT = 5;

    /** @var array<string, Contracts\SearchSource> */
    private readonly array $sources;

    public function __construct(
        Sources\ContactSearchSource $contacts,
        Sources\OpportunitySearchSource $opportunities,
        Sources\ConversationSearchSource $conversations,
        Sources\DocumentSearchSource $documents,
    ) {
        $this->sources = [
            'contacts' => $contacts,
            'opportunities' => $opportunities,
            'conversations' => $conversations,
            'documents' => $documents,
        ];
    }

    /**
     * @return array<string, list<SearchResult>> domain key => results, every
     *                                            key always present even when empty
     */
    public function search(Business $business, string $query, User $user): array
    {
        $query = trim($query);

        $grouped = [];

        // Per-member module visibility: a domain the owner switched off for this person is never
        // searched (so its records cannot surface through the top-bar search either).
        $hidden = app(\App\Library\Workspace\MemberModuleVisibility::class)->hiddenFor((int) $user->id, (int) $business->workspace_id);

        foreach ($this->sources as $key => $source) {
            $module = self::MODULE_BY_SOURCE[$key] ?? null;
            $searchable = $query !== '' && ! ($module !== null && in_array($module, $hidden, true));

            $grouped[$key] = $searchable ? $source->search($business, $query, $user, self::PER_SOURCE_LIMIT) : [];
        }

        return $grouped;
    }
}
