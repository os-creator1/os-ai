<?php

namespace App\Http\Controllers\Customer\Business;

use App\Exceptions\Seo\SeoKeywordException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Seo\SeoKeywordCoverageReader;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Seo\SeoLocationScope;
use App\Library\Seo\SeoPublishedContentReader;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoKeyword;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Contract 18 Sub-slice D — SEO keyword management and Core on-page coverage.
 *
 * NOT the legacy inbound-SMS Keywords controller. Different domain, models,
 * permissions and routes: everything here is `customer.workspaces.businesses.
 * seo.keywords.*`, gated by view_seo / manage_seo, never `customer.keywords.*`
 * and never `view_keywords`.
 *
 * Every action runs the mandatory chain (Contract 18 §10.1): Workspace →
 * Business → userCanAccessBusiness() → active Business → the
 * SeoBasicVisibility entitlement decision (404 for every failure, and 404 for
 * every tier while the feature is Planned) → the capability (view_seo to read,
 * manage_seo to write). Tenancy and entitlement come FIRST, so a caller learns
 * nothing about the surface before they are entitled to it.
 *
 * LOCATION ACCESS is never decided here: reads go through SeoKeywordManager
 * (LocationAccessGuard, filter-before-count) and every write is re-authorized
 * by the manager under a Business row lock. A keyword uid or Location uid the
 * actor cannot reach is a 404, exactly like one that does not exist.
 *
 * Writes only seo_keywords, through SeoKeywordManager. Nothing here touches a
 * Business, Location, Website or Google row, calls a provider, or calls AI.
 */
class SeoKeywordsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesSeoBusinessTenancy;

    public function __construct(
        private readonly SeoKeywordManager $keywords,
        private readonly SeoLocationScope $locationScope,
        private readonly SeoPublishedContentReader $publishedContent,
        private readonly SeoKeywordCoverageReader $coverage,
    ) {
    }

    /** Named `listing`, not `index`: CustomerBaseController already declares index(). */
    public function listing(string $workspaceUid, string $businessUid): View
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        $actorId = (int) Auth::id();

        // Location access first; every count and list below is computed from
        // this already-filtered set.
        $accessibleLocations = $this->locationScope->accessibleLocations($actorId, $business);
        $accessibleIds = $accessibleLocations->pluck('id')->map(fn ($id) => (int) $id)->all();

        $keywords = $this->keywords->listVisible($actorId, $business, $accessibleIds);
        $active = $keywords->filter(fn (SeoKeyword $k) => $k->isActive());

        return view('customer.business.seo.keywords', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'keywords' => $keywords,
            'coverage' => $this->coverage->forKeywords($active, $this->publishedContent->forBusiness($business)),
            'locations' => $accessibleLocations->filter(fn (BusinessLocation $l) => $l->isActive())->values(),
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $input = $this->validated($request);
        $actorId = (int) Auth::id();
        $location = $this->resolveLocation($actorId, $business, $input['location_uid'] ?? null);

        try {
            $this->keywords->create($actorId, $business, (string) $input['phrase'], $location);
        } catch (SeoKeywordException $e) {
            return $this->refused($workspaceUid, $businessUid, $e);
        }

        return $this->done($workspaceUid, $businessUid, 'Keyword added.');
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $keywordUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $actorId = (int) Auth::id();
        $keyword = $this->keywords->findAccessible($actorId, $business, $keywordUid);
        abort_if($keyword === null, 404);

        $input = $this->validated($request);
        $location = $this->resolveLocation($actorId, $business, $input['location_uid'] ?? null);

        try {
            $this->keywords->update($actorId, $business, $keyword, (string) $input['phrase'], $location);
        } catch (SeoKeywordException $e) {
            return $this->refused($workspaceUid, $businessUid, $e);
        }

        return $this->done($workspaceUid, $businessUid, 'Keyword updated.');
    }

    public function archive(string $workspaceUid, string $businessUid, string $keywordUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $actorId = (int) Auth::id();
        $keyword = $this->keywords->findAccessible($actorId, $business, $keywordUid);
        abort_if($keyword === null, 404);

        try {
            $this->keywords->archive($actorId, $business, $keyword);
        } catch (SeoKeywordException $e) {
            return $this->refused($workspaceUid, $businessUid, $e);
        }

        return $this->done($workspaceUid, $businessUid, 'Keyword archived.');
    }

    public function reactivate(string $workspaceUid, string $businessUid, string $keywordUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $actorId = (int) Auth::id();
        $keyword = $this->keywords->findAccessible($actorId, $business, $keywordUid);
        abort_if($keyword === null, 404);

        try {
            $this->keywords->reactivate($actorId, $business, $keyword);
        } catch (SeoKeywordException $e) {
            return $this->refused($workspaceUid, $businessUid, $e);
        }

        return $this->done($workspaceUid, $businessUid, 'Keyword reactivated.');
    }

    /**
     * Input shape only, validated AFTER the tenancy/entitlement chain and the
     * capability gate — never in a FormRequest, which would run first and turn
     * an invalid POST from an unentitled caller into a validation redirect
     * instead of the fail-closed 404. Authority is not decided here.
     *
     * @return array{phrase: string, location_uid?: string|null}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'phrase' => ['required', 'string', 'max:' . SeoKeywordManager::MAX_PHRASE_LENGTH],
            'location_uid' => ['nullable', 'string', 'max:64'],
        ]);
    }

    /**
     * A submitted Location uid is resolved ONLY among the Locations this actor
     * may access. A guessed foreign or inaccessible uid is a 404, so it cannot
     * be used to probe for Locations.
     */
    private function resolveLocation(int $actorId, Business $business, mixed $uid): ?BusinessLocation
    {
        if (! is_string($uid) || $uid === '') {
            return null;
        }

        $location = $this->locationScope->accessibleLocations($actorId, $business)->firstWhere('uid', $uid);

        abort_if($location === null, 404);

        return $location;
    }

    private function done(string $workspaceUid, string $businessUid, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid])
            ->with('status', 'success')
            ->with('message', $message);
    }

    private function refused(string $workspaceUid, string $businessUid, SeoKeywordException $e): RedirectResponse
    {
        // An access refusal is the same 404 as an unknown keyword.
        abort_if($e->reason === SeoKeywordException::ACCESS_DENIED, 404);

        return redirect()
            ->route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid])
            ->with('status', 'error')
            ->with('message', $e->customerMessage());
    }
}
