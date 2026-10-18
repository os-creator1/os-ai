<?php

namespace App\Library\Search\Sources;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\CrmOpportunitiesController;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Crm\CrmLocationScope;
use App\Library\Search\Contracts\SearchSource;
use App\Library\Search\SearchResult;
use App\Models\Business;
use App\Models\CrmOpportunity;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Global Search over CRM Opportunities (Blueprint §24). Gated exactly as
 * CrmOpportunitiesController itself is: the same
 * CrmOpportunitiesController::VIEW_PERMISSION capability and the `crm`
 * entitlement, both checked before any row is read. Location-filtered per
 * Contract 08B's convention: a proven `location_id` must be at a Location
 * LocationAccessGuard lets the actor reach; NULL is an ordinary value the
 * model's own docblock names as expected and does not gate on its own. The reach
 * is pushed into the SQL before the result limit (see ContactSearchSource).
 */
final class OpportunitySearchSource implements SearchSource
{
    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly CrmLocationScope $scope,
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

        $query = CrmOpportunity::query()->where('business_id', $business->id);
        $this->scope->restrict($query, $business, (int) $user->id, 'crm_opportunities.location_id');

        $candidates = $query
            ->where('title', 'like', $like)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'uid', 'title', 'location_id']);

        $results = [];

        foreach ($candidates as $opportunity) {
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
