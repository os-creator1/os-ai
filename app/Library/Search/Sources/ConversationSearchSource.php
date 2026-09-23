<?php

namespace App\Library\Search\Sources;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Search\Contracts\SearchSource;
use App\Library\Search\SearchResult;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Global Search over Conversations (Blueprint §24). Gated exactly as
 * ChatBoxController itself: the `chat_box` capability and the
 * `conversations` entitlement, both checked before any row is read.
 * Location-filtered per Contract 08B — the SAME rule
 * ChatBoxController::resolveBusinessChatBox() already applies: a proven
 * `location_id` must pass LocationAccessGuard, re-derived from persistence;
 * a NULL `location_id` never gates on its own.
 *
 * There is no single canonical "open this one conversation" URL (the inbox
 * is a one-page app that loads a conversation by AJAX), so a result opens
 * the Conversations inbox itself — the existing screen, not a new one.
 */
final class ConversationSearchSource implements SearchSource
{
    public function __construct(
        private readonly LocationAccessGuard $locations,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /** @return list<SearchResult> */
    public function search(Business $business, string $query, User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows('chat_box')) {
            return [];
        }

        $workspace = $business->workspace;

        if ($workspace === null) {
            return [];
        }

        if (! $this->entitlements->decide($workspace, $business, PlatformFeature::Conversations->value, (int) $user->id)->allowed) {
            return [];
        }

        $digits = (string) preg_replace('/\D+/', '', $query);

        if ($digits === '') {
            return [];
        }

        $candidates = ChatBox::query()
            ->where('business_id', $business->id)
            ->where('to', 'like', '%' . $digits . '%')
            ->orderByDesc('updated_at')
            ->limit($limit * 4)
            ->get(['id', 'uid', 'to', 'location_id']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $names = ChatBox::displayNamesFor($business, $candidates);
        $indexUrl = route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]);

        $results = [];

        foreach ($candidates as $box) {
            if (count($results) >= $limit) {
                break;
            }

            if ($box->location_id !== null) {
                $location = $box->location;

                if ($location === null || ! $this->locations->userCanAccessLocation((int) $user->id, $location)) {
                    continue;
                }
            }

            $name = trim((string) ($names[$box->id] ?? ''));

            $results[] = new SearchResult(
                domain: 'conversations',
                title: $name !== '' ? $name : (string) $box->to,
                subtitle: $name !== '' ? (string) $box->to : '',
                url: $indexUrl,
                icon: 'message-square',
            );
        }

        return $results;
    }
}
