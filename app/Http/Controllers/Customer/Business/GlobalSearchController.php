<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Search\GlobalSearchCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Blueprint §7/§24 — the top-bar Global Search endpoint. Authenticated,
 * Business-scoped by the same tenancy chain every other Business-scoped
 * controller carries (ResolvesBusinessTenancy); no single feature gates the
 * endpoint itself, because the four domains it aggregates each carry their
 * OWN independent permission/entitlement/Location authorization inside
 * GlobalSearchCoordinator's sources — an actor entitled to none of the four
 * simply receives four empty lists, never a 404 that would itself leak
 * whether any domain is entitled.
 */
class GlobalSearchController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(private readonly GlobalSearchCoordinator $coordinator)
    {
    }

    public function search(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        $query = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return response()->json([
            'query' => $query,
            'results' => $this->coordinator->search($business, $query, Auth::user()),
        ]);
    }
}
