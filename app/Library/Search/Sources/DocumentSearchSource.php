<?php

namespace App\Library\Search\Sources;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Search\Contracts\SearchSource;
use App\Library\Search\SearchResult;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Global Search over transactional documents (Contract 17 §12.G, Blueprint
 * §24). Gated by the exact §6.1 chain DocumentsController itself carries:
 * the single `payments_contracts` capability, the PaymentsContracts
 * entitlement, and — because a document's `business_location_id` is NEVER
 * null (§6.6) — LocationAccessGuard is mandatory for every candidate, no
 * null-passes exception the other three sources allow.
 *
 * NEVER RETURNED: access_token_hash, any public-link token material, the
 * Stripe account/connection identifier, any PaymentIntent/charge/refund id,
 * client_secret, provider event ids, or signature evidence — only the
 * document's own title and its safe navigation identity, the minimum the
 * result list needs.
 */
final class DocumentSearchSource implements SearchSource
{
    public function __construct(
        private readonly LocationAccessGuard $locations,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /** @return list<SearchResult> */
    public function search(Business $business, string $query, User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows('payments_contracts')) {
            return [];
        }

        $workspace = $business->workspace;

        if ($workspace === null) {
            return [];
        }

        if (! $this->entitlements->decide($workspace, $business, PlatformFeature::PaymentsContracts->value, (int) $user->id)->allowed) {
            return [];
        }

        $like = '%' . addcslashes($query, '%_\\') . '%';

        $candidates = BusinessDocument::query()
            ->where('business_id', $business->id)
            ->where('title', 'like', $like)
            ->orderByDesc('id')
            ->limit($limit * 4)
            ->get(['id', 'uid', 'title', 'kind', 'business_location_id']);

        $results = [];

        foreach ($candidates as $document) {
            if (count($results) >= $limit) {
                break;
            }

            // Mandatory, unconditional — a document's Location is never null.
            if ($document->business_location_id === null) {
                continue;
            }

            $location = $document->businessLocation;

            if ($location === null || ! $this->locations->userCanAccessLocation((int) $user->id, $location)) {
                continue;
            }

            $results[] = new SearchResult(
                domain: 'documents',
                title: (string) $document->title,
                subtitle: ucfirst((string) $document->kind->value),
                url: route('customer.workspaces.businesses.documents.show', [$workspace->uid, $business->uid, $document->uid]),
                icon: 'file-text',
            );
        }

        return $results;
    }
}
