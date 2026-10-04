<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationException;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationService;
use App\Library\GoogleAds\Mutations\MutationOutcome;
use App\Library\GoogleAds\Mutations\MutationOutcomeStatus;
use App\Library\GoogleAds\Mutations\NegativeKeywordPreview;
use App\Library\GoogleAds\Reporting\GoogleAdsListInput;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermReview;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\GoogleAdsSearchTerm;
use App\Models\User;
use App\Repositories\Contracts\WorkspaceRepository;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Google Ads Module V1 contract §6 — the HTTP side of the safe mutations.
 *
 * THIN: every Google-changing action is one GoogleAdsMutationService call;
 * this class only resolves tenancy, checks `manage_google_ads`, maps the
 * outcome to a calm flash and redirects. It never builds a provider request.
 *
 * Order (as everywhere in the module): tenancy + google_ads_module
 * entitlement (404) THEN `manage_google_ads` (the application's 401), then the
 * service, which re-resolves the target inside the Business: a request carries
 * only LOCAL identifiers (campaign / keyword uid, a local search-term row id);
 * Google's ids are never accepted, and a foreign identifier is a 404 with no
 * provider call. The negative-keyword text is always read from OUR cached
 * search-term row, never from the request. View As is blocked for every route
 * here by ViewAsProhibitedActions (all non-GET ads.* routes).
 *
 * "Ignore" is the owner's classification of a cached search term; it is not a
 * provider mutation (GoogleAdsSearchTermReview).
 */
class AdsMutationController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;

    private const UUID = '/\A[0-9a-fA-F\-]{36}\z/';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly GoogleAdsMutationService $mutations,
        private readonly GoogleAdsSearchTermReview $review,
    ) {
    }

    // -----------------------------------------------------------------
    // Campaign / keyword status
    // -----------------------------------------------------------------

    public function pauseCampaign(Request $request, string $workspaceUid, string $businessUid, string $campaignUid): RedirectResponse
    {
        return $this->mutate($request, $workspaceUid, $businessUid, 'campaigns.index', fn (Business $b, User $u) => $this->mutations->pauseCampaign($b, $u, $campaignUid));
    }

    public function resumeCampaign(Request $request, string $workspaceUid, string $businessUid, string $campaignUid): RedirectResponse
    {
        return $this->mutate($request, $workspaceUid, $businessUid, 'campaigns.index', fn (Business $b, User $u) => $this->mutations->resumeCampaign($b, $u, $campaignUid));
    }

    public function pauseKeyword(Request $request, string $workspaceUid, string $businessUid, string $keywordUid): RedirectResponse
    {
        return $this->mutate($request, $workspaceUid, $businessUid, 'keywords.index', fn (Business $b, User $u) => $this->mutations->pauseKeyword($b, $u, $keywordUid));
    }

    public function resumeKeyword(Request $request, string $workspaceUid, string $businessUid, string $keywordUid): RedirectResponse
    {
        return $this->mutate($request, $workspaceUid, $businessUid, 'keywords.index', fn (Business $b, User $u) => $this->mutations->resumeKeyword($b, $u, $keywordUid));
    }

    // -----------------------------------------------------------------
    // Negative keyword (server-driven confirmation, then the mutation)
    // -----------------------------------------------------------------

    /**
     * Step one: the exact facts the owner is about to confirm. A POST (a page
     * render with no provider call and no write), so a refresh cannot re-send
     * anything. Changing the scope or match type re-submits to this action.
     */
    public function previewNegative(Request $request, string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->resolveAdsModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        [$term, $campaignUid] = $this->resolveTerm($request, $business);
        $scope = $this->scope($request, $term);
        $match = $this->match($request);

        try {
            $preview = $this->mutations->previewNegativeKeyword($business, $this->actor(), $campaignUid, (string) $term->search_term, $scope, $match, (int) $term->id);
        } catch (GoogleAdsMutationException $exception) {
            return $this->refused($exception, $request, $workspaceUid, $businessUid, 'search-terms.index');
        }

        return view('customer.business.ads.negative-confirm', $this->adsViewData($workspace, $business, 'search-terms') + [
            'preview' => $preview,
            'term' => $term,
            'campaignUid' => $campaignUid,
            'scopeOptions' => $this->scopeOptions($business, $campaignUid, $term, $match, $scope, $preview),
            'match' => $match,
            'scope' => $scope,
            'hidden' => $this->returnFields($request),
        ]);
    }

    /** Step two: the owner confirmed what step one displayed. */
    public function storeNegative(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveAdsModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        [$term, $campaignUid] = $this->resolveTerm($request, $business);
        $scope = $this->scope($request, $term);
        $match = $this->match($request);

        try {
            $outcome = $this->mutations->addNegativeKeyword($business, $this->actor(), $campaignUid, (string) $term->search_term, $scope, $match, (int) $term->id);
        } catch (GoogleAdsMutationException $exception) {
            return $this->refused($exception, $request, $workspaceUid, $businessUid, 'search-terms.index');
        }

        return $this->returnTo($request, $workspaceUid, $businessUid, 'search-terms.index')->with($this->flash($outcome));
    }

    // -----------------------------------------------------------------
    // Ignore / un-ignore (a local classification; no provider call)
    // -----------------------------------------------------------------

    public function ignoreTerm(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        return $this->classify($request, $workspaceUid, $businessUid, true);
    }

    public function unignoreTerm(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        return $this->classify($request, $workspaceUid, $businessUid, false);
    }

    private function classify(Request $request, string $workspaceUid, string $businessUid, bool $ignore): RedirectResponse
    {
        [, $business] = $this->resolveAdsModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        $account = $this->resolveAdsAccount($business);
        $term = $account === null ? null : $this->review->resolve($account, $this->termId($request), $this->optionalCampaignUid($request));

        abort_if($account === null || $term === null, 404);

        $ignore ? $this->review->ignore($account, $term) : $this->review->unignore($account, $term);

        return $this->returnTo($request, $workspaceUid, $businessUid, 'search-terms.index')->with($this->adsFlash(
            'success',
            $ignore
                ? 'Ignored. This search term will no longer be flagged as potential waste. Nothing was changed in Google Ads.'
                : 'Un-ignored. This search term can be flagged again. Nothing was changed in Google Ads.',
        ));
    }

    // -----------------------------------------------------------------
    // Shared plumbing
    // -----------------------------------------------------------------

    /**
     * @param  Closure(Business, User): MutationOutcome  $call
     */
    private function mutate(Request $request, string $workspaceUid, string $businessUid, string $fallbackRoute, Closure $call): RedirectResponse
    {
        [, $business] = $this->resolveAdsModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        try {
            $outcome = $call($business, $this->actor());
        } catch (GoogleAdsMutationException $exception) {
            return $this->refused($exception, $request, $workspaceUid, $businessUid, $fallbackRoute);
        }

        return $this->returnTo($request, $workspaceUid, $businessUid, $fallbackRoute)->with($this->flash($outcome));
    }

    /**
     * A request the service refused before sending anything. Not found / not
     * entitled are bare 404s; the rest are calm messages in our own words.
     */
    private function refused(GoogleAdsMutationException $exception, Request $request, string $workspaceUid, string $businessUid, string $fallbackRoute): RedirectResponse
    {
        abort_if($exception->httpStatus() === 404, 404);

        return $this->returnTo($request, $workspaceUid, $businessUid, $fallbackRoute)->with($this->adsFlash('error', $exception->getMessage()));
    }

    /**
     * Fixed, calm copy per outcome. Provider text never reaches here:
     * MutationOutcome messages are the platform's own vocabulary.
     *
     * @return array{status: string, message: string, message_title?: string}
     */
    private function flash(MutationOutcome $outcome): array
    {
        return match ($outcome->status) {
            MutationOutcomeStatus::Succeeded => $this->adsFlash('success', $outcome->message),
            MutationOutcomeStatus::AwaitingConfirmation => $this->adsFlash(
                'warning',
                "Google didn't confirm this change. We'll verify it on the next update. Nothing was changed twice.",
            ) + ['message_title' => 'Pending confirmation'],
            MutationOutcomeStatus::Deferred => $this->adsFlash('warning', $outcome->message),
            MutationOutcomeStatus::Failed => $this->adsFlash('error', $outcome->message),
            MutationOutcomeStatus::DuplicateNoop => $this->adsFlash('info', $outcome->message),
        };
    }

    /**
     * The cached search-term row the action addresses, resolved inside the
     * Business's account AND the named campaign; anything else is a 404.
     *
     * @return array{0: GoogleAdsSearchTerm, 1: string}
     */
    private function resolveTerm(Request $request, Business $business): array
    {
        $campaignUid = $request->input('campaign');
        $campaignUid = is_string($campaignUid) ? trim($campaignUid) : '';
        $account = $this->resolveAdsAccount($business);

        $term = $account === null ? null : $this->review->resolve($account, $this->termId($request), $campaignUid);

        abort_if($term === null, 404);

        return [$term, $campaignUid];
    }

    private function termId(Request $request): int
    {
        $id = $request->input('search_term_id');

        return (is_string($id) || is_int($id)) && ctype_digit((string) $id) && strlen((string) $id) <= 18 ? (int) $id : 0;
    }

    private function optionalCampaignUid(Request $request): ?string
    {
        $uid = $request->input('campaign');

        return is_string($uid) && trim($uid) !== '' ? trim($uid) : null;
    }

    /** Campaign scope is the default and the safest; ad group only when the term has one. */
    private function scope(Request $request, GoogleAdsSearchTerm $term): GoogleAdsKeywordLevel
    {
        return GoogleAdsListInput::choice($request->input('scope'), ['ad_group']) !== null && $term->google_ads_ad_group_id !== null
            ? GoogleAdsKeywordLevel::AdGroup
            : GoogleAdsKeywordLevel::Campaign;
    }

    /** Exact by default; phrase on request; broad is never offered or accepted. */
    private function match(Request $request): GoogleAdsMatchType
    {
        return GoogleAdsListInput::choice($request->input('match'), ['phrase']) !== null ? GoogleAdsMatchType::Phrase : GoogleAdsMatchType::Exact;
    }

    /**
     * What each scope radio names ("Campaign: X", "Ad group: Y"). The selected
     * scope's preview is the one already computed; the other is a best-effort
     * read of the same local data (no provider call).
     *
     * @return array<string, array{label: string, name: ?string, available: bool, already_excluded: bool}>
     */
    private function scopeOptions(Business $business, string $campaignUid, GoogleAdsSearchTerm $term, GoogleAdsMatchType $match, GoogleAdsKeywordLevel $selected, NegativeKeywordPreview $preview): array
    {
        $options = [];

        foreach ([GoogleAdsKeywordLevel::Campaign, GoogleAdsKeywordLevel::AdGroup] as $level) {
            if ($level === GoogleAdsKeywordLevel::AdGroup && $term->google_ads_ad_group_id === null) {
                $options[$level->value] = ['label' => 'Ad group', 'name' => null, 'available' => false, 'already_excluded' => false];

                continue;
            }

            $view = $level === $selected ? $preview : $this->tryPreview($business, $campaignUid, $term, $level, $match);

            $options[$level->value] = [
                'label' => $level === GoogleAdsKeywordLevel::Campaign ? 'Campaign' : 'Ad group',
                'name' => $view?->parentName,
                'available' => $view !== null,
                'already_excluded' => $view?->alreadyExcluded ?? false,
            ];
        }

        return $options;
    }

    private function tryPreview(Business $business, string $campaignUid, GoogleAdsSearchTerm $term, GoogleAdsKeywordLevel $level, GoogleAdsMatchType $match): ?NegativeKeywordPreview
    {
        try {
            return $this->mutations->previewNegativeKeyword($business, $this->actor(), $campaignUid, (string) $term->search_term, $level, $match, (int) $term->id);
        } catch (GoogleAdsMutationException) {
            return null;
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * Hidden fields that carry "where to go back to" through the two-step
     * flow. Both are re-validated on use; neither is ever a URL.
     *
     * @return array<string, string>
     */
    private function returnFields(Request $request): array
    {
        $fields = [];
        $from = $request->input('from');

        if (is_string($from) && in_array($from, ['campaigns', 'keywords', 'search-terms', 'campaign'], true)) {
            $fields['from'] = $from;
        }

        $uid = $request->input('return_campaign');

        if (is_string($uid) && preg_match(self::UUID, $uid) === 1) {
            $fields['return_campaign'] = $uid;
        }

        $query = GoogleAdsListInput::returnQuery($this->parsedQuery($request));

        if ($query !== []) {
            $fields['q'] = http_build_query($query);
        }

        return $fields;
    }

    /** Back to the page the action came from, filters preserved, never an arbitrary URL. */
    private function returnTo(Request $request, string $workspaceUid, string $businessUid, string $fallbackRoute): RedirectResponse
    {
        $prefix = 'customer.workspaces.businesses.ads.';
        $from = $request->input('from');
        $query = GoogleAdsListInput::returnQuery($this->parsedQuery($request));
        $base = [$workspaceUid, $businessUid];

        $url = match (true) {
            $from === 'campaign' && is_string($request->input('return_campaign')) && preg_match(self::UUID, (string) $request->input('return_campaign')) === 1
                => route($prefix . 'campaigns.show', [...$base, (string) $request->input('return_campaign')] + $query),
            $from === 'campaigns' => route($prefix . 'campaigns.index', $base + $query),
            $from === 'keywords' => route($prefix . 'keywords.index', $base + $query),
            $from === 'search-terms' => route($prefix . 'search-terms.index', $base + $query),
            default => route($prefix . $fallbackRoute, $base),
        };

        return redirect()->to($url);
    }

    /** @return array<string, mixed> */
    private function parsedQuery(Request $request): array
    {
        $raw = $request->input('q');
        $parsed = [];

        if (is_string($raw) && $raw !== '' && strlen($raw) <= 500) {
            parse_str($raw, $parsed);
        }

        return $parsed;
    }
}
