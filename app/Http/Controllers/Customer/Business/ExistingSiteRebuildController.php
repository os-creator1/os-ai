<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Website\WebsiteMode;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\ExternalSite\ExternalWebsiteReader;
use App\Library\ExternalSite\Rebuild\ExternalRebuildApplier;
use App\Library\ExternalSite\Rebuild\ExternalRebuildPlanner;
use App\Library\ExternalSite\Rebuild\ExternalRebuildRedirectPlan;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Library\Website\WebsiteModeManager;
use App\Models\Business;
use App\Models\ExternalRebuildPlan;
use App\Models\ExternalSitePage;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Existing website -> MotionGrove-hosted website, as an owner-reviewed plan.
 *
 * Safety contract (pinned by tests): nothing here crawls, calls AI, publishes, connects or switches a domain, changes the
 * Business's website address, or deletes the external site's audit history. The external site stays live and
 * untouched; the plan is data, the draft redirects only go live inside a revision the owner publishes later.
 *
 * Every read and write is scoped to the one Business that tenancy resolved: the plan is looked up by business_id, never
 * by an id from the request.
 */
class ExistingSiteRebuildController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly ExternalWebsiteReader $reader,
        private readonly ExternalRebuildPlanner $planner,
        private readonly ExternalRebuildRedirectPlan $redirects,
        private readonly ExternalRebuildApplier $applier,
        private readonly WebsiteModeManager $modes,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->tenancy($workspaceUid, $businessUid);
        $plan = $this->plan($business);
        $website = Website::where('business_id', $business->id)->first();
        $drafts = $website === null ? collect() : WebsitePage::where('website_id', $website->id)->orderBy('sort_order')->get();

        return view('customer.business.website.existing-rebuild', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'plan' => $plan,
            'report' => $this->reader->latestReport($business),
            'rows' => $plan === null ? [] : $this->redirects->build($plan, $drafts),
            'draftSlugs' => $drafts->map(fn ($p) => (bool) $p->is_home ? '' : (string) $p->slug)->filter(fn ($s) => $s !== '')->values()->all(),
            'hasDraft' => $drafts->isNotEmpty(),
            'areas' => ExternalRebuildPlanner::GTA_AREAS,
        ]);
    }

    /** Build (or rebuild, while still a draft) the plan from the newest finished audit. No network, no AI. */
    public function prepare(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $existing = $this->plan($business);

        if ($existing !== null && $existing->isConfirmed()) {
            return $this->back($workspaceUid, $businessUid, 'error', 'Your plan is already confirmed. Your decisions are kept.');
        }

        $crawl = $this->reader->latestReport($business);

        if ($crawl === null) {
            return $this->back($workspaceUid, $businessUid, 'error', 'Run a check of your existing website first, so there is a real page list to plan from.');
        }

        $pages = ExternalSitePage::where('crawl_id', $crawl->id)->where('business_id', $business->id)->get();
        $built = $this->planner->build($pages);

        if ($built['pages'] === []) {
            return $this->back($workspaceUid, $businessUid, 'error', 'The latest check found no live, indexable pages to plan from.');
        }

        ExternalRebuildPlan::updateOrCreate(['business_id' => $business->id], [
            'crawl_id' => $crawl->id,
            'source_host' => (string) $crawl->host,
            'status' => ExternalRebuildPlan::DRAFT,
            'pages' => $built['pages'],
            'facts' => $built['facts'],
            'decisions' => null,
        ]);

        return $this->back($workspaceUid, $businessUid, 'success', 'Plan prepared from your existing website. Review it, then confirm.');
    }

    /** Record the owner's decisions and confirm the plan. Only the keys the plan itself contains are accepted. */
    public function confirm(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $plan = $this->plan($business);

        if ($plan === null) {
            return $this->back($workspaceUid, $businessUid, 'error', 'Prepare a plan first.');
        }

        $input = $request->validate([
            'facts' => ['nullable', 'array'],
            'facts.*' => ['nullable', 'in:1'],
            'pages' => ['nullable', 'array'],
            'pages.*.include' => ['nullable', 'in:1'],
            'pages.*.keep_seo' => ['nullable', 'in:1'],
            'pages.*.target' => ['nullable', 'string', 'max:120', 'regex:/^(auto|none|home|[a-z0-9]+(-[a-z0-9]+)*)$/'],
        ]);

        $facts = array_map(fn (array $f) => ['confirmed' => isset($input['facts'][$f['key']])] + $f, (array) $plan->facts);

        $decisions = [];
        foreach ((array) $plan->pages as $page) {
            $sent = (array) ($input['pages'][$page['key']] ?? []);
            $decisions[$page['key']] = [
                'include' => isset($sent['include']),
                'keep_seo' => isset($sent['keep_seo']) && $page['claim_flags'] === [],
                'target' => (string) ($sent['target'] ?? ExternalRebuildRedirectPlan::AUTO),
            ];
        }

        $plan->forceFill([
            'facts' => $facts,
            'decisions' => ['pages' => $decisions],
            'status' => $plan->status === ExternalRebuildPlan::STARTED ? ExternalRebuildPlan::STARTED : ExternalRebuildPlan::CONFIRMED,
            'confirmed_by_user_id' => Auth::id(),
            'confirmed_at' => now(),
        ])->save();

        return $this->back($workspaceUid, $businessUid, 'success', 'Plan confirmed. Nothing on your live website has changed.');
    }

    /**
     * Begin the hosted build. Only switches the Business's primary-site mode (the same switch the Website screen already
     * offers) and opens the setup wizard, whose generation runs on the queue. The address stays the external site.
     */
    public function start(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $plan = $this->plan($business);

        if ($plan === null || ! $plan->isConfirmed()) {
            return $this->back($workspaceUid, $businessUid, 'error', 'Confirm the plan before starting the build.');
        }

        if ($this->modes->resolve($business) !== WebsiteMode::Hosted) {
            $this->modes->set($business, WebsiteMode::Hosted);
        }

        if ($plan->started_at === null) {
            $plan->forceFill(['status' => ExternalRebuildPlan::STARTED, 'started_at' => now()])->save();
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]);
    }

    /** Write the confirmed redirects (and the titles/descriptions the owner chose to keep) into the DRAFT. */
    public function applyToDraft(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $plan = $this->plan($business);
        $website = Website::where('business_id', $business->id)->first();

        if ($plan === null || $website === null) {
            return $this->back($workspaceUid, $businessUid, 'error', 'Generate your draft website first; then the redirects can be matched to its real pages.');
        }

        try {
            $result = $this->applier->apply($plan, $website);
        } catch (GenerationInProgressException $e) {
            return $this->back($workspaceUid, $businessUid, 'error', $e->getMessage());
        } catch (ValidationException $e) {
            return $this->back($workspaceUid, $businessUid, 'error', (string) collect($e->errors())->flatten()->first());
        }

        return $this->back($workspaceUid, $businessUid, 'success', "Draft updated: {$result['redirects']} redirect(s) and {$result['seo']} page title/description(s). They only go live if you publish.");
    }

    private function plan(Business $business): ?ExternalRebuildPlan
    {
        return ExternalRebuildPlan::where('business_id', $business->id)->first();
    }

    /** @return array{0: \App\Models\Workspace, 1: Business} */
    private function tenancy(string $workspaceUid, string $businessUid): array
    {
        $this->authorize('website');

        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }

    private function back(string $workspaceUid, string $businessUid, string $status, string $message): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.website.existing.show', [$workspaceUid, $businessUid])->with(['status' => $status, 'message' => $message]);
    }
}
