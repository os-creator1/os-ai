<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Website\WebsiteMode;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Acquisition\PurposeWebsiteIntents;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\ExternalSite\ExternalFixInstructions;
use App\Library\ExternalSite\ExternalSiteCrawlManager;
use App\Library\ExternalSite\ExternalSiteException;
use App\Library\ExternalSite\ExternalWebsiteReader;
use App\Library\Website\WebsiteModeManager;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * External Website Audit Mode V1 — the Website module for a Business whose
 * primary website lives elsewhere: Overview, Audit, Pages, Settings.
 *
 * MotionGrove does not own that site's CMS, so nothing here publishes, edits,
 * rebuilds, re-templates or connects a domain, and nothing is labelled "Fix":
 * the screens show what is wrong, where, how to fix it in the owner's own editor,
 * and a link to open the page. Technical findings (from the one SEO audit
 * engine) and acquisition recommendations (from the Business's goals) are shown
 * in separate cards: a missing marketing page is an opportunity, never a
 * fabricated technical error.
 *
 * Tenancy mirrors the rest of Website: Workspace -> Business -> active Business
 * -> the `website` capability -> the Website entitlement. Choosing or changing the
 * address needs the same; the address itself is written only by the account owner
 * through the canonical Business update seam.
 */
class ExternalWebsiteController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WebsiteModeManager $modes,
        private readonly ExternalSiteCrawlManager $crawls,
        private readonly ExternalWebsiteReader $reader,
        private readonly PurposeWebsiteIntents $intents,
    ) {
    }

    /** POST website/mode — the first-screen choice (and later switches). */
    public function chooseMode(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);

        $mode = WebsiteMode::tryFrom((string) $request->input('mode'));

        if ($mode === null) {
            return back()->with(['status' => 'error', 'message' => 'Choose one of the options.']);
        }

        if ($mode === WebsiteMode::External) {
            return $this->chooseExternal($request, $workspaceUid, $businessUid, $business);
        }

        $this->modes->set($business, $mode);

        if ($mode === WebsiteMode::Hosted) {
            return redirect()->route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]);
        }

        return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'No problem. You can set up your website whenever you are ready.']);
    }

    public function overview(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->externalTenancy($workspaceUid, $businessUid);

        if (! $business instanceof Business) {
            return $business;
        }

        $latest = $this->reader->latest($business);
        $completed = $latest?->isCompleted() ? $latest : $this->reader->latestCompleted($business);

        return view('customer.business.website.external.overview', $this->base($workspace, $business, 'overview') + [
            'latest' => $latest,
            'crawl' => $completed,
            'topIssue' => $completed === null ? null : $this->reader->topIssue($completed),
            'goalsWithoutDestination' => $this->intents->withoutDestination($business),
            'goalIntents' => $this->intents->forBusiness($business),
            'adsGoalsUrl' => $this->adsGoalsUrl($workspace, $business),
        ]);
    }

    public function audit(Request $request, string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->externalTenancy($workspaceUid, $businessUid);

        if (! $business instanceof Business) {
            return $business;
        }

        $crawl = $this->reader->latestCompleted($business);
        $groups = $crawl === null ? [] : $this->reader->groups($crawl);
        $only = is_string($request->query('rule')) ? $request->query('rule') : null;

        return view('customer.business.website.external.audit', $this->base($workspace, $business, 'audit') + [
            'latest' => $this->reader->latest($business),
            'crawl' => $crawl,
            'groups' => $only === null ? $groups : array_values(array_filter($groups, fn (array $g): bool => $g['rule_key'] === $only)),
            'onlyRule' => $only,
            'steps' => fn (string $rule): array => ExternalFixInstructions::steps($rule),
        ]);
    }

    public function pages(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->externalTenancy($workspaceUid, $businessUid);

        if (! $business instanceof Business) {
            return $business;
        }

        $crawl = $this->reader->latestCompleted($business);

        return view('customer.business.website.external.pages', $this->base($workspace, $business, 'pages') + [
            'latest' => $this->reader->latest($business),
            'crawl' => $crawl,
            'pages' => $crawl === null ? collect() : $this->reader->pages($crawl),
        ]);
    }

    public function page(string $workspaceUid, string $businessUid, int $pageId): View|RedirectResponse
    {
        [$workspace, $business] = $this->externalTenancy($workspaceUid, $businessUid);

        if (! $business instanceof Business) {
            return $business;
        }

        $crawl = $this->reader->latestCompleted($business);
        $detail = $crawl === null ? null : $this->reader->page($crawl, $pageId);

        abort_if($detail === null, 404);

        return view('customer.business.website.external.page', $this->base($workspace, $business, 'pages') + [
            'latest' => $this->reader->latest($business),
            'crawl' => $crawl,
            'page' => $detail['page'],
            'findings' => $detail['findings'],
            'steps' => fn (string $rule): array => ExternalFixInstructions::steps($rule),
            'suggestsTitle' => fn (string $rule): bool => ExternalFixInstructions::suggestsTitle($rule),
            'suggestedTitle' => ExternalFixInstructions::suggestedTitle($detail['page'], $business),
        ]);
    }

    public function settings(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->externalTenancy($workspaceUid, $businessUid);

        if (! $business instanceof Business) {
            return $business;
        }

        return view('customer.business.website.external.settings', $this->base($workspace, $business, 'settings') + [
            'latest' => $this->reader->latest($business),
            'history' => $this->reader->history($business),
            'canEditAddress' => $this->isAccountOwner($business),
        ]);
    }

    public function saveSettings(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);

        return $this->applyAddress($request, $workspaceUid, $businessUid, $business, redirectTo: 'customer.workspaces.businesses.website.external.settings');
    }

    /** POST website/external/crawl — "Check again now" (throttled; never takes a URL). */
    public function crawl(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);

        $back = redirect()->route('customer.workspaces.businesses.website.external.overview', [$workspaceUid, $businessUid]);

        try {
            $result = $this->crawls->request($business, 'manual');
        } catch (ExternalSiteException) {
            return $back->with(['status' => 'error', 'message' => 'Add your website address first.']);
        }

        return match ($result['state']) {
            ExternalSiteCrawlManager::STATE_QUEUED => $back->with(['status' => 'success', 'message' => 'We are checking your website now. This usually takes a minute or two.']),
            ExternalSiteCrawlManager::STATE_ACTIVE => $back->with(['status' => 'info', 'message' => 'A check is already running.']),
            default => $back->with(['status' => 'info', 'message' => 'Your website was checked a moment ago. You can check again in '.$result['retry_after_minutes'].' minute(s).']),
        };
    }

    // ------------------------------------------------------------------ internals

    private function chooseExternal(Request $request, string $workspaceUid, string $businessUid, Business $business): RedirectResponse
    {
        $response = $this->applyAddress($request, $workspaceUid, $businessUid, $business, redirectTo: 'customer.workspaces.businesses.website.external.overview', choosing: true);

        return $response;
    }

    /**
     * Validates and stores the address, switches the mode to `external` when
     * choosing, and asks for a crawl.
     */
    private function applyAddress(Request $request, string $workspaceUid, string $businessUid, Business $business, string $redirectTo, bool $choosing = false): RedirectResponse
    {
        $input = trim((string) $request->input('website_url', ''));
        $current = trim((string) $business->website_url);

        try {
            $address = $this->modes->normalizeAddress($input !== '' ? $input : $current);
        } catch (ExternalSiteException) {
            return back()->withInput()->with(['status' => 'error', 'message' => 'Enter your website address, for example example.com. It must be a public website.']);
        }

        if ($address !== $current && $input !== '') {
            if (! $this->isAccountOwner($business)) {
                return back()->with(['status' => 'error', 'message' => 'Only the account owner can change the website address.']);
            }

            try {
                $business = $this->modes->saveAddress(Auth::user()->customer, $business, $address);
            } catch (\Throwable) {
                return back()->with(['status' => 'error', 'message' => 'We could not save the address. Please try again.']);
            }
        }

        if ($choosing) {
            $this->modes->set($business, WebsiteMode::External);
        }

        $started = false;

        try {
            $started = $this->crawls->request($business, 'url_change')['state'] === ExternalSiteCrawlManager::STATE_QUEUED;
        } catch (ExternalSiteException) {
            // Nothing to crawl yet; the screens explain it.
        }

        return redirect()->route($redirectTo, [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => $choosing
                ? 'Your website is connected. We are checking it now and will show what to improve.'
                : ($started ? 'Saved. We are checking the new address now.' : 'Saved.'),
        ]);
    }

    private function isAccountOwner(Business $business): bool
    {
        $customer = Auth::user()?->customer;

        return $customer !== null && (int) $customer->user_id === (int) $business->customer_id;
    }

    /** @return array{0: Workspace, 1: Business} */
    private function tenancy(string $workspaceUid, string $businessUid): array
    {
        $this->authorize('website');

        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }

    /**
     * Tenancy plus the mode check: the external screens exist only for an
     * `external` Business; any other mode goes back to the Website entry, which
     * shows the right thing.
     *
     * @return array{0: Workspace, 1: Business|RedirectResponse}
     */
    private function externalTenancy(string $workspaceUid, string $businessUid): array
    {
        [$workspace, $business] = $this->tenancy($workspaceUid, $businessUid);

        if ($this->modes->resolve($business) !== WebsiteMode::External) {
            return [$workspace, redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid])];
        }

        return [$workspace, $business];
    }

    /** @return array<string, mixed> */
    private function base(Workspace $workspace, Business $business, string $active): array
    {
        $params = [(string) $workspace->uid, (string) $business->uid];
        $prefix = 'customer.workspaces.businesses.website.external.';

        return [
            'workspaceUid' => (string) $workspace->uid,
            'businessUid' => (string) $business->uid,
            'business' => $business,
            'active' => $active,
            'websiteUrl' => trim((string) $business->website_url),
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview', 'url' => route($prefix.'overview', $params)],
                ['key' => 'audit', 'label' => 'Audit', 'url' => route($prefix.'audit', $params)],
                ['key' => 'pages', 'label' => 'Pages', 'url' => route($prefix.'pages', $params)],
                ['key' => 'settings', 'label' => 'Settings', 'url' => route($prefix.'settings', $params)],
            ],
            'crawlUrl' => route($prefix.'crawl', $params),
        ];
    }

    private function adsGoalsUrl(Workspace $workspace, Business $business): ?string
    {
        if (! app(AdsFeatureAccess::class)->hasAnyAds($workspace, $business, (int) Auth::id())) {
            return null;
        }

        return route('customer.workspaces.businesses.ads.goals', [(string) $workspace->uid, (string) $business->uid]);
    }
}
