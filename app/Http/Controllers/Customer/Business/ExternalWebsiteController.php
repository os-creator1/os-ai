<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Website\WebsiteMode;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Acquisition\PurposeWebsiteIntents;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\Branding\ClientWorkspaceBrandResolver;
use App\Library\ExternalSite\Audit\ExternalAuditCatalog;
use App\Library\ExternalSite\ConnectionResult;
use App\Library\ExternalSite\ExternalAuditPresenter;
use App\Library\ExternalSite\ExternalImprovementPlan;
use App\Library\ExternalSite\ExternalSiteConfig;
use App\Library\ExternalSite\ExternalSiteCrawler;
use App\Library\ExternalSite\ExternalSiteCrawlManager;
use App\Library\ExternalSite\ExternalSiteException;
use App\Library\ExternalSite\ExternalWebsiteReader;
use App\Library\Website\WebsiteModeManager;
use App\Models\Business;
use App\Models\ExternalSitePlanItem;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * External Website Audit — the Website module for a Business whose primary website
 * lives elsewhere: Overview, Audit, Improvement Plan, Pages, Settings.
 *
 * MotionGrove does not own that site's CMS, so nothing here publishes, edits, rebuilds,
 * re-templates or connects a domain, and nothing is labelled "Fix": the screens show
 * what is wrong, where, how to fix it in the owner's own editor, and a link to open the
 * page. Confirmed technical problems, SEO improvements and growth opportunities are
 * shown apart: a missing marketing page is an opportunity, never a fabricated error.
 *
 * Tenancy mirrors the rest of Website: Workspace -> Business -> active Business -> the
 * `website` capability -> the Website entitlement. Choosing or changing the address
 * needs the same; the address itself is written only by the account owner through the
 * canonical Business update seam. The controller stays thin: every number and sentence
 * comes from ExternalWebsiteReader, ExternalImprovementPlan and ExternalAuditPresenter.
 */
class ExternalWebsiteController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WebsiteModeManager $modes,
        private readonly ExternalSiteCrawlManager $crawls,
        private readonly ExternalWebsiteReader $reader,
        private readonly PurposeWebsiteIntents $intents,
        private readonly ExternalSiteCrawler $crawler,
        private readonly ExternalImprovementPlan $plans,
        private readonly ExternalSiteConfig $config,
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

        // Leaving "use my existing website": a check still running for it is stopped; its stored history stays.
        $this->crawls->cancel($business);
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

        $report = $this->reader->latestReport($business);
        $diff = $report === null ? null : $this->reader->diff($report, $this->reader->previousReport($business, $report));
        $groups = $report === null ? [] : $this->reader->issueGroups($report, [], $diff === null ? null : ['new' => $diff['new_keys'], 'unchanged' => $diff['unchanged_keys']]);
        $plan = $report === null ? null : $this->plans->build($groups, $business, $report, $this->tracked($business), $this->helperEmail($workspace));

        return view('customer.business.website.external.overview', $this->base($workspace, $business, 'overview') + [
            'report' => $report,
            'earlier' => $report === null ? $this->reader->latestEarlierFormat($business) : null,
            'plan' => $plan,
            'diff' => $diff,
            'notMeasured' => ExternalAuditPresenter::notMeasured(),
            'history' => $this->reader->history($business, 5),
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

        $report = $this->reader->latestReport($business);
        $filters = $this->auditFilters($request);
        $diff = $report === null ? null : $this->reader->diff($report, $this->reader->previousReport($business, $report));
        $diffKeys = $diff === null ? null : ['new' => $diff['new_keys'], 'unchanged' => $diff['unchanged_keys']];
        $all = $report === null ? [] : $this->reader->issueGroups($report, [], $diffKeys);
        $groups = $report === null ? [] : $this->reader->issueGroups($report, $filters, $diffKeys);

        return view('customer.business.website.external.audit', $this->base($workspace, $business, 'audit') + [
            'report' => $report,
            'earlier' => $report === null ? $this->reader->latestEarlierFormat($business) : null,
            'groups' => $groups,
            'totalGroups' => count($all),
            'filters' => $filters,
            'diff' => $diff,
            'categories' => ExternalAuditCatalog::CATEGORIES,
            'kinds' => ExternalAuditCatalog::KINDS,
            'priorities' => ExternalAuditCatalog::PRIORITY_LABELS,
            'notMeasured' => ExternalAuditPresenter::notMeasured(),
        ]);
    }

    public function plan(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->externalTenancy($workspaceUid, $businessUid);

        if (! $business instanceof Business) {
            return $business;
        }

        $report = $this->reader->latestReport($business);
        $groups = $report === null ? [] : $this->reader->issueGroups($report);

        return view('customer.business.website.external.plan', $this->base($workspace, $business, 'plan') + [
            'report' => $report,
            'earlier' => $report === null ? $this->reader->latestEarlierFormat($business) : null,
            'plan' => $report === null ? null : $this->plans->build($groups, $business, $report, $this->tracked($business), $this->helperEmail($workspace)),
            'statuses' => ExternalSitePlanItem::STATUSES,
        ]);
    }

    /** POST website/external/plan/{itemKey} — the owner's own tracking of one improvement. */
    public function updatePlanItem(Request $request, string $workspaceUid, string $businessUid, string $itemKey): RedirectResponse|JsonResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);

        $data = $request->validate([
            'rule_key' => ['required', 'string', 'max:64'],
            'status' => ['required', 'in:'.implode(',', array_keys(ExternalSitePlanItem::STATUSES))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // The key must be the hash of a rule that exists: a made-up key can never create a row.
        if (! ExternalAuditCatalog::has($data['rule_key']) || ExternalSitePlanItem::keyFor($data['rule_key']) !== $itemKey) {
            abort(404);
        }

        $item = ExternalSitePlanItem::query()->firstOrNew(['business_id' => $business->id, 'item_key' => $itemKey]);
        $changed = ! $item->exists || $item->status !== $data['status'];

        $item->fill([
            'rule_key' => $data['rule_key'],
            'status' => $data['status'],
            'note' => isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null,
            'updated_by_user_id' => Auth::id(),
            'first_seen_crawl_id' => $item->first_seen_crawl_id ?? $this->reader->latestReport($business)?->id,
        ]);

        if ($changed) {
            $item->status_changed_at = now();
            // Re-opening, or marking done again, clears an earlier verification: the next complete check re-establishes it.
            $item->resolved_in_crawl_id = null;
        }

        $item->save();

        if ($request->wantsJson()) {
            return response()->json(['status' => $item->status, 'label' => ExternalSitePlanItem::STATUSES[$item->status]]);
        }

        return redirect()->to(url()->previous() ?: route('customer.workspaces.businesses.website.external.plan', [$workspaceUid, $businessUid]))
            ->with(['status' => 'success', 'message' => 'Saved. Mark an item done after you change it, then check again: MotionGrove confirms it only when the next check no longer finds it.']);
    }

    public function pages(Request $request, string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->externalTenancy($workspaceUid, $businessUid);

        if (! $business instanceof Business) {
            return $business;
        }

        $report = $this->reader->latestReport($business);
        $filters = array_filter([
            'q' => $this->str($request->query('q')),
            'priority' => $this->oneOf($request->query('priority'), ExternalAuditCatalog::PRIORITIES),
            'importance' => $this->oneOf($request->query('importance'), ['home', 'key', 'standard', 'utility']),
            'indexing' => $this->oneOf($request->query('indexing'), ['indexable', 'noindex']),
        ]);
        $sort = $this->oneOf($request->query('sort'), ['severity', 'issues', 'title', 'url', 'status']) ?? 'severity';

        return view('customer.business.website.external.pages', $this->base($workspace, $business, 'pages') + [
            'report' => $report,
            'earlier' => $report === null ? $this->reader->latestEarlierFormat($business) : null,
            'pages' => $report === null ? collect() : $this->reader->pages($report, $filters, $sort),
            'otherUrls' => $report === null ? collect() : $this->reader->otherUrls($report),
            'filters' => $filters,
            'sort' => $sort,
            'priorities' => ExternalAuditCatalog::PRIORITY_LABELS,
        ]);
    }

    public function page(string $workspaceUid, string $businessUid, int $pageId): View|RedirectResponse
    {
        [$workspace, $business] = $this->externalTenancy($workspaceUid, $businessUid);

        if (! $business instanceof Business) {
            return $business;
        }

        $report = $this->reader->latestReport($business);
        $detail = $report === null ? null : $this->reader->page($report, $pageId);

        abort_if($detail === null, 404);

        return view('customer.business.website.external.page', $this->base($workspace, $business, 'pages') + [
            'report' => $report,
            'detail' => $detail,
            'page' => $detail['page'],
        ]);
    }

    public function settings(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->externalTenancy($workspaceUid, $businessUid);

        if (! $business instanceof Business) {
            return $business;
        }

        $latest = $this->reader->latest($business);
        $report = $this->reader->latestReport($business);
        $withConnection = $latest !== null && $latest->connection_state !== null ? $latest : $this->reader->siteQuery($business)->whereNotNull('connection_state')->latest('id')->first();

        try {
            $normalized = $this->crawls->startUrl($business);
        } catch (ExternalSiteException) {
            $normalized = null;
        }

        $history = $this->reader->history($business, 12);
        $changes = [];

        // What changed at each of the latest checks, compared only with the check before it of the same address.
        foreach ($history->take(6) as $check) {
            if ($check->isReport() && $this->reader->isCurrentSite($business, $check)) {
                $diff = $this->reader->diff($check, $this->reader->previousReport($business, $check));

                if ($diff !== null) {
                    $changes[$check->id] = $diff;
                }
            }
        }

        return view('customer.business.website.external.settings', $this->base($workspace, $business, 'settings') + [
            'report' => $report,
            'history' => $history,
            'changes' => $changes,
            'canEditAddress' => $this->isAccountOwner($business),
            'normalizedUrl' => $normalized,
            'connectionCrawl' => $withConnection,
            'connection' => ExternalAuditPresenter::connection($withConnection?->connection_state),
            'budget' => $this->config->maxPages(),
            'cadenceDays' => $this->config->recrawlIntervalDays(),
            'throttleMinutes' => $this->config->manualThrottleMinutes(),
            'businessUrlPrefill' => trim((string) $business->website_url),
        ]);
    }

    public function saveSettings(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);

        return $this->applyAddress($request, $workspaceUid, $businessUid, $business, redirectTo: 'customer.workspaces.businesses.website.external.settings');
    }

    /** POST website/external/settings/test — fetch the SAVED address once and report how it behaved (never takes a URL). */
    public function testConnection(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $back = redirect()->route('customer.workspaces.businesses.website.external.settings', [$workspaceUid, $businessUid]);

        try {
            $result = $this->crawler->probe($this->crawls->startUrl($business));
        } catch (ExternalSiteException) {
            return $back->with(['status' => 'error', 'message' => 'Add your website address first.']);
        }

        return $back->with($this->connectionFlash($result));
    }

    /** POST website/external/crawl — "Check again now" (throttled; never takes a URL). */
    public function crawl(Request $request, string $workspaceUid, string $businessUid): RedirectResponse|JsonResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);

        $back = redirect()->route('customer.workspaces.businesses.website.external.overview', [$workspaceUid, $businessUid]);

        try {
            $result = $this->crawls->request($business, 'manual');
        } catch (ExternalSiteException) {
            return $request->wantsJson()
                ? response()->json(['state' => 'no_address', 'message' => 'Add your website address first.'], 422)
                : $back->with(['status' => 'error', 'message' => 'Add your website address first.']);
        }

        $message = match ($result['state']) {
            ExternalSiteCrawlManager::STATE_QUEUED => ['status' => 'success', 'message' => 'We are checking your website now. The progress appears below; you do not need to refresh.'],
            ExternalSiteCrawlManager::STATE_ACTIVE => ['status' => 'info', 'message' => 'A check is already in progress. Its progress is shown below.'],
            default => ['status' => 'info', 'message' => 'Your website was checked a moment ago. You can check again in '.$result['retry_after_minutes'].' minute(s).'],
        };

        return $request->wantsJson()
            ? response()->json(['state' => $result['state']] + $message + $this->reader->status($business))
            : $back->with($message);
    }

    /** POST website/external/crawl/cancel — stops the check in progress; the previous report is untouched. */
    public function cancel(Request $request, string $workspaceUid, string $businessUid): RedirectResponse|JsonResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);

        $stopped = $this->crawls->cancel($business);

        return $request->wantsJson()
            ? response()->json(['cancelled' => $stopped] + $this->reader->status($business))
            : redirect()->route('customer.workspaces.businesses.website.external.overview', [$workspaceUid, $businessUid])
                ->with(['status' => 'info', 'message' => $stopped ? 'The check was cancelled. Your previous results are unchanged.' : 'No check was running.']);
    }

    /** POST website/external/stop — stop monitoring this external website. History is kept. */
    public function stop(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);

        $this->crawls->cancel($business);
        $this->modes->set($business, WebsiteMode::None);

        return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'We stopped checking your website. Your audit history is kept, and you can connect a website again at any time.']);
    }

    /** GET website/external/status — the live state of the latest check, polled by the page while a check runs. */
    public function status(string $workspaceUid, string $businessUid): JsonResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);

        $this->crawls->failStale((int) $business->id);

        return response()->json($this->reader->status($business))->header('Cache-Control', 'no-store');
    }

    // ------------------------------------------------------------------ internals

    private function chooseExternal(Request $request, string $workspaceUid, string $businessUid, Business $business): RedirectResponse
    {
        return $this->applyAddress($request, $workspaceUid, $businessUid, $business, redirectTo: 'customer.workspaces.businesses.website.external.overview', choosing: true);
    }

    /**
     * Validates and stores the address, verifies it can be fetched, switches the mode to `external` when choosing, and
     * asks for a crawl. A changed address starts a fresh audit of the NEW address; the old address's history stays.
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

        $addressChanged = false;

        if ($address !== $current && $input !== '') {
            if (! $this->isAccountOwner($business)) {
                return back()->with(['status' => 'error', 'message' => 'Only the account owner can change the website address.']);
            }

            try {
                $business = $this->modes->saveAddress(Auth::user()->customer, $business, $address);
            } catch (Throwable) {
                return back()->with(['status' => 'error', 'message' => 'We could not save the address. Please try again.']);
            }

            $addressChanged = true;
        }

        if ($choosing) {
            $this->modes->set($business, WebsiteMode::External);
        }

        // A changed address on an already-external Business is re-checked by the Business update listener (any edit
        // surface); asking again here would only duplicate it. Choosing the mode (the listener did not fire: the mode
        // was not external when the address was saved) and re-saving an unchanged address ask here.
        $started = $addressChanged && ! $choosing;

        if (! $started) {
            try {
                $started = $this->crawls->request($business, $choosing ? 'manual' : 'url_change')['state'] === ExternalSiteCrawlManager::STATE_QUEUED;
            } catch (ExternalSiteException) {
                // Nothing to crawl yet; the screens explain it.
            }
        }

        $note = '';

        if ($addressChanged || $choosing) {
            // One request to the saved address, so the owner learns NOW whether it can be fetched and where it really goes.
            try {
                $note = ' '.$this->connectionFlash($this->crawler->probe($address))['message'];
            } catch (Throwable) {
                $note = '';
            }
        }

        return redirect()->route($redirectTo, [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => ($choosing
                ? 'Your website is connected. We are checking it now and will show what to improve.'
                : ($started ? 'Saved. We are checking the new address now; results for the old address stay in your history.' : 'Saved.')).$note,
        ]);
    }

    /** @return array{status: string, message: string} the owner-facing outcome of one connection check */
    private function connectionFlash(ConnectionResult $result): array
    {
        $final = $result->finalUrl !== null ? ' ('.preg_replace('#^https?://#', '', rtrim($result->finalUrl, '/')).')' : '';

        return match ($result->state) {
            ConnectionResult::REACHABLE => ['status' => 'success', 'message' => 'Your website could be reached'.$final.'.'],
            ConnectionResult::REDIRECTS_ELSEWHERE => ['status' => 'info', 'message' => 'That address redirects to a different website'.$final.'. If that is your real website, save it as your address.'],
            ConnectionResult::CRAWLER_BLOCKED => ['status' => 'info', 'message' => 'Your website answered, but a firewall or rate limit turned our crawler away (HTTP '.($result->httpStatus ?: '—').'). That does not mean the site is down; it means the audit cannot read it until the crawler is allowed.'],
            ConnectionResult::ROBOTS_BLOCKED => ['status' => 'info', 'message' => 'Your robots.txt asks crawlers like ours to stay out, so the audit cannot read your site.'],
            ConnectionResult::HTTP_ERROR => ['status' => 'info', 'message' => 'Your website answered with an error (HTTP '.($result->httpStatus ?: '—').').'],
            ConnectionResult::TLS_ERROR => ['status' => 'info', 'message' => 'The secure connection (HTTPS certificate) to your website failed.'],
            ConnectionResult::NETWORK_ERROR => ['status' => 'info', 'message' => 'We could not connect to that address from our servers ('.str_replace('_', ' ', (string) $result->reason).'). That does not prove visitors cannot.'],
            default => ['status' => 'error', 'message' => 'That address is not a public website we can check.'],
        };
    }

    private function isAccountOwner(Business $business): bool
    {
        $customer = Auth::user()?->customer;

        return $customer !== null && (int) $customer->user_id === (int) $business->customer_id;
    }

    /** @return \Illuminate\Support\Collection<string, ExternalSitePlanItem> */
    private function tracked(Business $business): \Illuminate\Support\Collection
    {
        return ExternalSitePlanItem::query()->where('business_id', $business->id)->get()->keyBy('rule_key');
    }

    /** The support address of the Agency whose white-label brand wraps this Business, if any (else "request help" is a copyable brief). */
    private function helperEmail(Workspace $workspace): ?string
    {
        try {
            $email = app(ClientWorkspaceBrandResolver::class)->forClientWorkspaceId((int) $workspace->id)?->supportEmail;

            return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{severity?: string, category?: string, kind?: string, q?: string, page?: int, status?: string} */
    private function auditFilters(Request $request): array
    {
        return array_filter([
            'severity' => $this->oneOf($request->query('severity'), ExternalAuditCatalog::PRIORITIES),
            'category' => $this->oneOf($request->query('category'), array_keys(ExternalAuditCatalog::CATEGORIES)),
            'kind' => $this->oneOf($request->query('kind'), array_keys(ExternalAuditCatalog::KINDS)),
            'status' => $this->oneOf($request->query('status'), ['new', 'unchanged']),
            'q' => $this->str($request->query('q')),
            'page' => ctype_digit((string) $request->query('page_id')) ? (int) $request->query('page_id') : null,
        ], fn ($v): bool => $v !== null && $v !== '');
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 120) : null;
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** @return array{0: Workspace, 1: Business} */
    private function tenancy(string $workspaceUid, string $businessUid): array
    {
        $this->authorize('website');

        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }

    /**
     * Tenancy plus the mode check: the external screens exist only for an `external` Business; any other mode goes back
     * to the Website entry, which shows the right thing.
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
        $latest = $this->reader->latest($business);

        return [
            'workspaceUid' => (string) $workspace->uid,
            'businessUid' => (string) $business->uid,
            'business' => $business,
            'active' => $active,
            'websiteUrl' => trim((string) $business->website_url),
            'latest' => $latest,
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview', 'url' => route($prefix.'overview', $params)],
                ['key' => 'audit', 'label' => 'Audit', 'url' => route($prefix.'audit', $params)],
                ['key' => 'plan', 'label' => 'Improvement plan', 'url' => route($prefix.'plan', $params)],
                ['key' => 'pages', 'label' => 'Pages', 'url' => route($prefix.'pages', $params)],
                ['key' => 'settings', 'label' => 'Settings', 'url' => route($prefix.'settings', $params)],
            ],
            'crawlUrl' => route($prefix.'crawl', $params),
            'cancelUrl' => route($prefix.'crawl.cancel', $params),
            'statusUrl' => route($prefix.'status', $params),
            'settingsUrl' => route($prefix.'settings', $params),
            'auditUrl' => route($prefix.'audit', $params),
            'planUrl' => route($prefix.'plan', $params),
            'pagesUrl' => route($prefix.'pages', $params),
            'overviewUrl' => route($prefix.'overview', $params),
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
