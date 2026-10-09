<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Seo\SeoRankCheckType;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Jobs\Seo\ScheduleSeoRankChecks;
use App\Jobs\Seo\SubmitSeoRankCheck;
use App\Library\Seo\Rank\SeoRankBudgetDecision;
use App\Library\Navigation\CustomerContext;
use App\Library\Seo\Rank\SeoRankChart;
use App\Library\Seo\Rank\SeoRankCheckPlanner;
use App\Library\Seo\Rank\SeoRankDashboardReader;
use App\Library\Seo\Rank\SeoRankEntitlement;
use App\Library\Seo\Rank\SeoRankException;
use App\Library\Seo\Rank\SeoRankFirstCheckNotice;
use App\Library\Seo\Rank\SeoRankHistoryReader;
use App\Library\Seo\Rank\SeoRankIdentity;
use App\Library\Seo\Rank\SeoRankLocationCatalog;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Library\Seo\Rank\SeoRankTrackingBudget;
use App\Library\Seo\Rank\SeoSearchConsoleReader;
use App\Library\Seo\SeoConfig;
use App\Library\Seo\SeoKeywordCoverageReader;
use App\Library\Seo\SeoPublishedContentReader;
use App\Models\Business;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SEO Keyword Rank Tracking V1 — start/stop/restart tracking, "Check now", the
 * search-location lookup and the keyword rank-history page.
 *
 * Every action runs the mandatory chain: Workspace -> Business ->
 * userCanAccessBusiness() -> active Business -> the SeoRankTracking entitlement
 * (404 for every failure) -> the capability (view_seo to read, manage_seo to
 * write). Targets are resolved ONLY by uid inside the Business and behind the
 * keyword's Location ACL (SeoRankTargetManager::findAccessible); a forged or
 * foreign uid is a 404, never a different answer.
 *
 * No provider call is made here. "Check now" asks the budget authority (via the
 * planner) and merely queues a job for runs it just reserved.
 */
class SeoRankTargetsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly SeoRankTargetManager $targets,
        private readonly SeoRankCheckPlanner $planner,
        private readonly SeoRankLocationCatalog $locations,
        private readonly SeoRankHistoryReader $history,
        private readonly SeoRankTrackingBudget $budget,
        private readonly SeoRankEntitlement $entitlement,
        private readonly SeoSearchConsoleReader $searchConsole,
        private readonly SeoKeywordCoverageReader $coverage,
        private readonly SeoPublishedContentReader $publishedContent,
        private readonly SeoRankFirstCheckNotice $firstCheckNotice,
        private readonly SeoConfig $config,
    ) {
    }

    public function locationSearch(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $this->resolveRankTenancy($workspaceUid, $businessUid);
        $this->authorize('view_seo');

        $query = (string) $request->query('q', '');

        return response()->json([
            'locations' => $this->locations->search(mb_substr($query, 0, 80), 10)->map(fn ($l) => [
                'code' => (int) $l->location_code,
                'label' => SeoRankLocationCatalog::label($l->location_name),
            ])->values(),
        ]);
    }

    public function track(Request $request, string $workspaceUid, string $businessUid, string $keywordUid): RedirectResponse
    {
        [, $business] = $this->resolveRankTenancy($workspaceUid, $businessUid);
        $this->authorize('manage_seo');

        $input = $request->validate(['search_location_code' => ['nullable', 'integer', 'min:1']]);

        // No code sent: use the Business's existing rank location (this keyword's Location first).
        $code = $input['search_location_code'] ?? $this->targets->defaultLocation(
            $business,
            \App\Models\SeoKeyword::query()->where('business_id', $business->id)->where('uid', $keywordUid)->value('business_location_id'),
        )?->location_code;

        if ($code === null) {
            return redirect()->route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid])->with('status', 'error')->with('message', 'Choose a search location to track this keyword.');
        }

        try {
            $target = $this->targets->track((int) Auth::id(), $business, $keywordUid, (int) $code);
        } catch (SeoRankException $e) {
            return $this->refused($workspaceUid, $businessUid, $e);
        }

        ScheduleSeoRankChecks::dispatch($target->id);

        // Said from the real state (provider, budget, what we can match), never assumed.
        return $this->done($workspaceUid, $businessUid, 'Rank tracking started. ' . $this->firstCheckNotice->forBusiness($business));
    }

    public function stop(string $workspaceUid, string $businessUid, string $targetUid): RedirectResponse
    {
        [, $business] = $this->resolveRankTenancy($workspaceUid, $businessUid);
        $this->authorize('manage_seo');

        try {
            $this->targets->stop((int) Auth::id(), $business, $targetUid);
        } catch (SeoRankException $e) {
            return $this->refused($workspaceUid, $businessUid, $e);
        }

        return $this->done($workspaceUid, $businessUid, 'Rank tracking stopped. Your history is kept and the slot is free.');
    }

    public function restart(string $workspaceUid, string $businessUid, string $targetUid): RedirectResponse
    {
        [, $business] = $this->resolveRankTenancy($workspaceUid, $businessUid);
        $this->authorize('manage_seo');

        try {
            $target = $this->targets->restart((int) Auth::id(), $business, $targetUid);
        } catch (SeoRankException $e) {
            return $this->refused($workspaceUid, $businessUid, $e);
        }

        ScheduleSeoRankChecks::dispatch($target->id);

        return $this->done($workspaceUid, $businessUid, 'Rank tracking resumed. ' . $this->firstCheckNotice->forBusiness($business));
    }

    public function check(string $workspaceUid, string $businessUid, string $targetUid): RedirectResponse
    {
        [, $business] = $this->resolveRankTenancy($workspaceUid, $businessUid);
        $this->authorize('manage_seo');

        $target = $this->targets->findAccessible((int) Auth::id(), $business, $targetUid);
        abort_if($target === null, 404);

        if (! $target->isTracking()) {
            return $this->back($workspaceUid, $businessUid, $targetUid, 'error', 'Start tracking this keyword before checking it.');
        }

        $decisions = $this->planner->planManual($target, (int) Auth::id());

        foreach ($decisions as $decision) {
            if ($decision->allowed && $decision->run !== null) {
                SubmitSeoRankCheck::dispatch($decision->run->id);
            }
        }

        $started = array_filter($decisions, fn (SeoRankBudgetDecision $d) => $d->allowed);

        if ($started !== []) {
            return $this->back($workspaceUid, $businessUid, $targetUid, 'success', 'Checking… results will appear here when the check completes.');
        }

        return $this->back($workspaceUid, $businessUid, $targetUid, 'error', $this->refusalCopy($decisions));
    }

    public function show(Request $request, string $workspaceUid, string $businessUid, string $targetUid): View
    {
        [, $business] = $this->resolveRankTenancy($workspaceUid, $businessUid);
        $this->authorize('view_seo');

        $target = $this->targets->findAccessible((int) Auth::id(), $business, $targetUid);
        abort_if($target === null, 404);

        $identity = SeoRankIdentity::forBusiness($business);
        $context = $request->attributes->get('customerContext');

        $series = $this->history->series($target->id);
        $summary = $this->history->summaries([$target->id])[$target->id];
        $byType = fn (SeoRankCheckType $t) => $series->filter(fn ($o) => $o->check_type === $t)->values();

        $coverage = $target->keyword->isActive()
            ? $this->coverage->forKeywords(collect([$target->keyword]), $this->publishedContent->forBusiness($business))[$target->keyword->id] ?? null
            : null;

        return view('customer.business.seo.rank-target', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'target' => $target,
            'keyword' => $target->keyword,
            'locationLabel' => SeoRankLocationCatalog::label((string) $target->searchLocation?->location_name),
            'organic' => $summary[SeoRankCheckType::Organic->value],
            'local' => $summary[SeoRankCheckType::Local->value],
            'organicChange' => SeoRankHistoryReader::change($summary['organic']['current'], $summary['organic']['previous']),
            'localChange' => SeoRankHistoryReader::change($summary['local']['current'], $summary['local']['previous']),
            'organicChart' => SeoRankChart::build($byType(SeoRankCheckType::Organic)),
            'localChart' => SeoRankChart::build($byType(SeoRankCheckType::Local)),
            'recent' => $series->reverse()->take(20)->values(),
            'coverage' => $coverage,
            'searchConsole' => $this->searchConsole->forKeyword($business, $target->keyword),
            'unavailable' => $this->budget->providerState() !== SeoRankTrackingBudget::PROVIDER_ENABLED,
            'pausedBySpend' => $this->budget->isPausedBySpend($business),
            'canTrack' => $this->entitlement->planFor($business) !== null,
            // Why a check can never run (no Active primary domain / no phone), and
            // whether the last result is older than the freshness window.
            'organicBlocked' => ! $identity->canMatchOrganic(),
            'localBlocked' => ! $identity->canMatchLocal(),
            'staleDays' => SeoRankDashboardReader::staleDays($target->last_checked_at, $this->config->rankStaleAfterDays()),
            // Paid checks are never started on a client's behalf while viewing as them.
            'viewingAsClient' => $context instanceof CustomerContext && $context->isViewingAsClient(),
        ]);
    }

    /**
     * @return array{0: \App\Models\Workspace, 1: Business}
     */
    private function resolveRankTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::SeoRankTracking->value);
    }

    /** @param list<SeoRankBudgetDecision> $decisions */
    private function refusalCopy(array $decisions): string
    {
        if ($decisions === []) {
            return 'Add a website domain or a business phone number so we can find your listing in the results.';
        }

        foreach ($decisions as $d) {
            if ($d->isUnavailable()) {
                return 'Rank checks are not available right now. Your existing results stay visible.';
            }
        }

        foreach ($decisions as $d) {
            if ($d->isBudgetPause()) {
                return 'Rank checks paused until your usage period resets.';
            }
        }

        $reasons = array_map(fn (SeoRankBudgetDecision $d) => $d->reason, $decisions);

        return match (true) {
            in_array(SeoRankBudgetDecision::PENDING, $reasons, true) => 'Checking… a check is already in progress.',
            in_array(SeoRankBudgetDecision::RECENT_RESULT, $reasons, true),
            in_array(SeoRankBudgetDecision::COOLDOWN, $reasons, true) => 'This keyword was checked in the last 24 hours, so the latest result is shown. You can refresh it again later.',
            in_array(SeoRankBudgetDecision::ALREADY_SCHEDULED, $reasons, true) => 'A check for this period is already scheduled.',
            default => 'This keyword cannot be checked right now.',
        };
    }

    private function done(string $workspaceUid, string $businessUid, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid])
            ->with('status', 'success')
            ->with('message', $message);
    }

    private function back(string $workspaceUid, string $businessUid, string $targetUid, string $status, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.seo.rank-targets.show', [$workspaceUid, $businessUid, $targetUid])
            ->with('status', $status)
            ->with('message', $message);
    }

    private function refused(string $workspaceUid, string $businessUid, SeoRankException $e): RedirectResponse
    {
        // An access refusal is the same 404 as an unknown id.
        abort_if($e->reason === SeoRankException::ACCESS_DENIED, 404);

        return redirect()
            ->route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid])
            ->with('status', 'error')
            ->with('message', $e->customerMessage());
    }
}
