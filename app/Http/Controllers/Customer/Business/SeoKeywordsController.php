<?php

namespace App\Http\Controllers\Customer\Business;

use App\Exceptions\Seo\SeoKeywordException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Seo\Rank\SeoRankDashboardReader;
use App\Library\Seo\Rank\SeoRankEntitlement;
use App\Library\Seo\Rank\SeoRankException;
use App\Library\Seo\Rank\SeoRankLocationCatalog;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Library\Seo\Rank\SeoRankTrackingBudget;
use App\Jobs\Seo\ScheduleSeoRankChecks;
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
        private readonly SeoRankDashboardReader $rankDashboard,
        private readonly SeoRankEntitlement $rankEntitlement,
        private readonly SeoRankTargetManager $rankTargets,
        private readonly SeoRankTrackingBudget $rankBudget,
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

        // Rank tracking is its own entitlement: without it the page is still the
        // keyword list with Website coverage, and rank columns show "—".
        $rankPlan = $this->rankEntitlement->planFor($business);
        $rank = $this->rankDashboard->build($business, $active->values(), $rankPlan);

        return view('customer.business.seo.keywords', [
            'rank' => $rank,
            'rankPlan' => $rankPlan,
            'rankPaused' => $rankPlan !== null && $this->rankBudget->isPausedBySpend($business),
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
            $keyword = $this->keywords->create($actorId, $business, (string) $input['phrase'], $location);
        } catch (SeoKeywordException $e) {
            return $this->refused($workspaceUid, $businessUid, $e);
        }

        if (! empty($input['track_rank'])) {
            return $this->done($workspaceUid, $businessUid, $this->trackNewKeyword($actorId, $business, $keyword->uid, $input['search_location_code'] ?? null));
        }

        // Allowance full: the form disables the checkbox, so say plainly that the
        // keyword was saved WITHOUT rank tracking and why.
        $plan = $this->rankEntitlement->planFor($business);

        if ($plan !== null && $this->rankTargets->slotsUsed($business) >= $plan->trackedTargets) {
            return $this->done($workspaceUid, $businessUid, "Keyword saved. Rank tracking off. {$plan->trackedTargets} of {$plan->trackedTargets} rank-tracked keywords are in use.");
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
            'track_rank' => ['nullable', 'boolean'],
            'search_location_code' => ['nullable', 'integer', 'min:1'],
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

    /**
     * The keyword is ALREADY saved as an SEO keyword (it needs no paid slot). If
     * the rank-tracking allowance is full, or the location is not usable, it
     * simply stays untracked and the owner is told why.
     */
    private function trackNewKeyword(int $actorId, Business $business, string $keywordUid, mixed $locationCode): string
    {
        if (! is_int($locationCode) && ! (is_string($locationCode) && ctype_digit($locationCode))) {
            return 'Keyword saved. Rank tracking off. Choose a search location from the list to track its rank.';
        }

        try {
            $target = $this->rankTargets->track($actorId, $business, $keywordUid, (int) $locationCode);
        } catch (SeoRankException $e) {
            return 'Keyword saved. Rank tracking off. ' . $e->customerMessage();
        }

        ScheduleSeoRankChecks::dispatch($target->id);

        return 'Keyword added and rank tracking started. The first check is on its way.';
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
