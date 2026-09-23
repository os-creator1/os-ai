<?php

namespace App\Library\Search\Sources;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\CrmOpportunitiesController;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Search\Contracts\SearchSource;
use App\Library\Search\SearchResult;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\CrmOpportunity;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Global Search over CRM Opportunities (Blueprint §24). Gated exactly as
 * CrmOpportunitiesController itself is: the same
 * CrmOpportunitiesController::VIEW_PERMISSION capability and the `crm`
 * entitlement, both checked before any row is read. Location-filtered per
 * Contract 08B's convention: a proven `location_id` must pass
 * LocationAccessGuard; NULL is an ordinary value the model's own docblock
 * names as expected and does not gate on its own.
 */
final class OpportunitySearchSource implements SearchSource
{
    public function __construct(
        private readonly LocationAccessGuard $locations,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /** @return list<SearchResult> */
    public function search(Business $business, string $query, User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows(CrmOpportunitiesController::VIEW_PERMISSION)) {
            return [];
        }

        $workspace = $business->workspace;

        if ($workspace === null) {
            return [];
        }

        if (! $this->entitlements->decide($workspace, $business, PlatformFeature::Crm->value, (int) $user->id)->allowed) {
            return [];
        }

        $like = '%' . addcslashes($query, '%_\\') . '%';

        $candidates = CrmOpportunity::query()
            ->where('business_id', $business->id)
            ->where('title', 'like', $like)
            ->orderByDesc('id')
            ->limit($limit * 4)
            ->get(['id', 'uid', 'title', 'location_id']);

        $results = [];

        foreach ($candidates as $opportunity) {
            if (count($results) >= $limit) {
                break;
            }

            if ($opportunity->location_id !== null) {
                $location = $opportunity->location;

                if ($location === null || ! $this->locations->userCanAccessLocation((int) $user->id, $location)) {
                    continue;
                }
            }

            $results[] = new SearchResult(
                domain: 'opportunities',
                title: (string) $opportunity->title,
                subtitle: '',
                url: route('customer.workspaces.businesses.crm.opportunities.show', [$workspace->uid, $business->uid, $opportunity->uid]),
                icon: 'kanban',
            );
        }

        return $results;
    }
}
