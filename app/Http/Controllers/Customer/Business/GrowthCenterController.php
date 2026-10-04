<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Growth\GrowthScoreCategory;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Growth\GrowthAdvisor;
use App\Library\Growth\GrowthAffectedRecords;
use App\Library\Growth\GrowthBriefBuilder;
use App\Library\Growth\GrowthEvaluationTrigger;
use App\Library\Growth\GrowthNavigation;
use App\Library\Growth\GrowthOpportunityPresenter;
use App\Library\Growth\GrowthOpportunityReader;
use App\Library\Growth\GrowthRuleRegistry;
use App\Library\Growth\GrowthScoreReader;
use App\Library\Growth\GrowthThresholds;
use App\Library\Growth\GrowthViewer;
use App\Library\Opportunity\Exceptions\InvalidOpportunityStateException;
use App\Library\Opportunity\Exceptions\InvalidSnoozeUntilException;
use App\Library\Opportunity\OpportunityManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The Growth Center (Growth Center lane) — the Business-scoped surface that
 * answers "how is my business doing, what is holding it back, why, and what do
 * I do about it".
 *
 * It READS the canonical Opportunity Engine (through GrowthOpportunityReader,
 * which applies Location ACL in SQL before any count) and the stored score
 * history, and it presents them. It creates no raw data, calls no provider and
 * — outside the Advisor page — no AI. Every write is delegated:
 * snooze / dismiss / reopen go through OpportunityManager, "Refresh" queues a
 * Growth evaluation, and every "fix" is a link into the owning module's own
 * screen, which does its own work with its own permission and confirmation.
 *
 * GATE CHAIN, in this order (mirroring every other Business-scoped surface):
 *   Workspace -> Business -> accessible to the actor -> Active Business ->
 *   the AI COO entitlement the Opportunity Engine sits behind (404 on any
 *   failure, never 403) -> the `business_advisor` capability.
 * The Opportunity Engine's master switch is NOT a gate: with it off the page
 * still loads and says plainly that nothing is being evaluated yet.
 */
class GrowthCenterController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const SNOOZE_PRESETS = ['tomorrow', '3_days', '1_week', 'custom'];

    public function __construct(
        private readonly GrowthOpportunityReader $reader,
        private readonly GrowthOpportunityPresenter $presenter,
        private readonly GrowthScoreReader $scores,
        private readonly GrowthBriefBuilder $brief,
        private readonly GrowthAffectedRecords $affected,
        private readonly GrowthEvaluationTrigger $trigger,
        private readonly OpportunityManager $manager,
        private readonly LocationAccessGuard $locationGuard,
        private readonly GrowthAdvisor $advisor,
    ) {
    }

    /**
     * Home IS the Growth Center now: the old overview is a permanent alias of
     * Home, kept so existing links and bookmarks keep working. The gate chain
     * still runs first, so an unentitled actor gets the same 404 as before.
     */
    public function overview(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->context($workspaceUid, $businessUid);

        return redirect()->route('user.home');
    }

    public function opportunities(Request $request, string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business, $viewer] = $this->context($workspaceUid, $businessUid);

        $filters = $request->only(['state', 'category', 'module', 'location', 'q']);
        $state = $this->reader->normalizeState($filters['state'] ?? null);
        $page = $this->reader->paginate($business, $viewer, $filters, 15);

        $names = GrowthOpportunityPresenter::locationNames($business->id);
        $items = $page->getCollection()->map(fn (Opportunity $o) => $this->presenter->present($o, $names, $workspaceUid, $businessUid))->all();

        return view('customer.business.growth.opportunities', $this->shared($workspace, $business, $viewer, 'opportunities') + [
            'items' => $items,
            'paginator' => $page,
            'state' => $state,
            'filters' => $filters,
            'stateCounts' => $this->reader->stateCounts($business, $viewer),
            'categories' => collect(GrowthRuleRegistry::all())->map(fn ($r) => $r->definition()->category)->unique()->values(),
            'modules' => collect(GrowthRuleRegistry::all())->map(fn ($r) => $r->definition()->sourceModule)->unique()->values()->all(),
            'locationOptions' => collect($this->reader->locationIds($business, $viewer))->mapWithKeys(fn ($id) => [$id => $names[$id] ?? ('Location ' . $id)])->all(),
        ]);
    }

    public function show(string $workspaceUid, string $businessUid, string $opportunityUid): View
    {
        [$workspace, $business, $viewer] = $this->context($workspaceUid, $businessUid);

        $opportunity = $this->reader->find($business, $viewer, $opportunityUid) ?? abort(404);
        $names = GrowthOpportunityPresenter::locationNames($business->id);
        $card = $this->presenter->present($opportunity, $names, $workspaceUid, $businessUid);
        $definition = GrowthRuleRegistry::find($opportunity->type)?->definition();

        Log::info('growth.opportunity_viewed', ['business_id' => $business->id, 'rule' => $opportunity->type]);

        return view('customer.business.growth.opportunity', $this->shared($workspace, $business, $viewer, 'opportunities') + [
            'card' => $card,
            'records' => $this->affected->resolve($definition?->domain ?? '', $card['evidence']['uids'] ?? [], $business->id, $viewer, $workspaceUid, $businessUid),
            'history' => $opportunity->transitions()->orderByDesc('id')->limit(15)->get(['category', 'from_status', 'to_status', 'actor_type', 'reason_code', 'safe_note', 'created_at']),
            'dismissReasons' => OpportunityManager::DISMISS_REASONS,
            'secondary' => $this->secondaryAction($opportunity, $workspaceUid, $businessUid),
        ]);
    }

    public function score(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business, $viewer] = $this->context($workspaceUid, $businessUid);

        return view('customer.business.growth.score', $this->shared($workspace, $business, $viewer, 'score') + [
            'categories' => $this->categoryCards($business, $viewer),
            'thresholds' => (new GrowthThresholds())->all(),
        ]);
    }

    public function insights(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business, $viewer] = $this->context($workspaceUid, $businessUid);

        return view('customer.business.growth.insights', $this->shared($workspace, $business, $viewer, 'insights') + [
            'brief' => $this->brief->build($business, $viewer, $workspaceUid, $businessUid),
        ]);
    }

    public function briefPage(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business, $viewer] = $this->context($workspaceUid, $businessUid);

        return view('customer.business.growth.brief', $this->shared($workspace, $business, $viewer, 'overview') + [
            'brief' => $this->brief->build($business, $viewer, $workspaceUid, $businessUid),
        ]);
    }

    public function advisor(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business, $viewer] = $this->context($workspaceUid, $businessUid);

        return view('customer.business.growth.advisor', $this->shared($workspace, $business, $viewer, 'advisor') + [
            'questions' => $this->advisor->questions(),
            'answer' => session('growth_advisor'),
        ]);
    }

    /**
     * Ask one of the CLOSED advisor questions. A POST (so a link or a prefetch
     * can never spend AI budget) that answers by redirecting to the advisor page
     * with the structured answer flashed — a refresh re-reads it, it does not
     * re-ask. The deterministic answer is always produced; AI only explains it.
     */
    public function ask(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business, $viewer] = $this->context($workspaceUid, $businessUid);

        $request->validate(['question' => ['required', 'in:' . implode(',', array_keys(GrowthAdvisor::QUESTIONS))]]);

        $names = GrowthOpportunityPresenter::locationNames($business->id);
        $cards = $this->reader->top($business, $viewer, 15)
            ->map(fn (Opportunity $o) => $this->presenter->present($o, $names, $workspaceUid, $businessUid))
            ->all();

        $latest = $viewer->maySeeScore() ? $this->scores->latest($business) : null;
        $baseline = $latest !== null ? $this->scores->baseline($business, $latest) : null;
        $movement = $latest !== null ? $this->scores->movement($latest, $baseline) : null;

        $answer = $this->advisor->answer(
            (string) $request->input('question'),
            $cards,
            $movement,
            $latest !== null ? $this->scores->changes($latest, $baseline) : [],
            $latest !== null ? $this->scores->positives($latest, 3) : [],
            $this->unavailableModules($latest),
            $latest === null ? null : [
                'overall' => $latest->overall_score,
                'based_on' => $latest->scored_category_count . ' of ' . $latest->total_category_count . ' categories',
                'categories' => collect(GrowthScoreCategory::cases())->mapWithKeys(fn ($c) => [$c->label() => $latest->category_scores[$c->value] ?? null])->all(),
            ],
            $workspace,
            $business,
            (int) Auth::id(),
        );

        return redirect()->route('customer.workspaces.businesses.growth.advisor', [$workspaceUid, $businessUid])->with('growth_advisor', $answer);
    }

    /**
     * Owner-facing names of the modules the last evaluation could NOT read, so an
     * answer says "not available" instead of implying it looked and found nothing.
     *
     * @return array<int, string>
     */
    private function unavailableModules($latest): array
    {
        $labels = [
            'ads' => 'Google Ads', 'rank' => 'Rank tracking', 'search_console' => 'Search Console',
            'seo' => 'SEO', 'reviews' => 'Reviews', 'citations' => 'Citations', 'documents' => 'Payments & proposals',
            'booking' => 'Calendar', 'crm' => 'CRM', 'conversations' => 'Conversations', 'website' => 'Website', 'automations' => 'Automations',
        ];

        $status = $latest?->metrics['domain_status'] ?? [];

        return collect($status)->filter(fn ($v) => $v !== 'available')->keys()->map(fn ($d) => $labels[$d] ?? null)->filter()->unique()->values()->all();
    }

    /** Opening a fix: recorded as a structured log line (acted-upon telemetry), then handed to the module. */
    public function go(string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        [, $business, $viewer] = $this->context($workspaceUid, $businessUid);

        $opportunity = $this->reader->find($business, $viewer, $opportunityUid) ?? abort(404);
        $meta = \App\Library\Opportunity\OpportunityTypeRegistry::get($opportunity->worker_key->value, $opportunity->type)['growth'] ?? [];
        $url = isset($meta['target']) ? GrowthNavigation::url($meta['target'], $workspaceUid, $businessUid) : null;

        Log::info('growth.action_opened', ['business_id' => $business->id, 'rule' => $opportunity->type, 'user_id' => Auth::id()]);

        return $url !== null
            ? redirect()->to($url)
            : redirect()->route('customer.workspaces.businesses.growth.opportunities.show', [$workspaceUid, $businessUid, $opportunityUid]);
    }

    public function snooze(Request $request, string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        [, $business, $viewer] = $this->context($workspaceUid, $businessUid);
        $opportunity = $this->reader->find($business, $viewer, $opportunityUid) ?? abort(404);

        $request->validate([
            'duration' => ['required', 'in:' . implode(',', self::SNOOZE_PRESETS)],
            'until' => ['required_if:duration,custom', 'nullable', 'date', 'after:today', 'before:' . now()->addYear()->toDateString()],
        ]);

        // A fixed, server-computed instant — mapped from the validated key (or a
        // validated date), never a client-supplied timestamp.
        $until = match ($request->input('duration')) {
            'tomorrow' => now()->addDay()->startOfDay()->addHours(8),
            '3_days' => now()->addDays(3),
            '1_week' => now()->addWeek(),
            default => CarbonImmutable::parse((string) $request->input('until'))->startOfDay()->addHours(8),
        };

        try {
            $this->manager->snooze($opportunity, Auth::user()->customer, $until);
        } catch (InvalidOpportunityStateException|InvalidSnoozeUntilException) {
            return $this->back($workspaceUid, $businessUid, $opportunityUid)->withErrors(['opportunity' => "That can't be snoozed right now."]);
        }

        Log::info('growth.opportunity_snoozed', ['business_id' => $business->id, 'rule' => $opportunity->type]);

        return redirect()->route('customer.workspaces.businesses.growth.opportunities.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Snoozed. It will come back if it is still a problem.']);
    }

    public function dismiss(Request $request, string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        [, $business, $viewer] = $this->context($workspaceUid, $businessUid);
        $opportunity = $this->reader->find($business, $viewer, $opportunityUid) ?? abort(404);

        $request->validate(['reason' => ['nullable', 'in:' . implode(',', array_keys(OpportunityManager::DISMISS_REASONS))]]);

        try {
            $this->manager->dismiss($opportunity, Auth::user()->customer, $request->input('reason') ?: null);
        } catch (InvalidOpportunityStateException) {
            return $this->back($workspaceUid, $businessUid, $opportunityUid)->withErrors(['opportunity' => "That can't be dismissed right now."]);
        }

        Log::info('growth.opportunity_dismissed', ['business_id' => $business->id, 'rule' => $opportunity->type]);

        return redirect()->route('customer.workspaces.businesses.growth.opportunities.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Dismissed. It may return after a while if the problem is still there.']);
    }

    public function reopen(string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        [, $business, $viewer] = $this->context($workspaceUid, $businessUid);
        $opportunity = $this->reader->find($business, $viewer, $opportunityUid) ?? abort(404);

        try {
            $this->manager->reopen($opportunity, Auth::user()->customer, 'customer_reopened');
        } catch (InvalidOpportunityStateException) {
            return $this->back($workspaceUid, $businessUid, $opportunityUid)->withErrors(['opportunity' => "That can't be reopened right now."]);
        }

        return $this->back($workspaceUid, $businessUid, $opportunityUid)->with(['status' => 'success', 'message' => 'Reopened.']);
    }

    /** Re-check now (queued; the page shows the new result on the next load). */
    public function refresh(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->context($workspaceUid, $businessUid);

        $this->trigger->triggerManual($business->id);

        return redirect()->route('user.home')
            ->with(['status' => 'success', 'message' => config('opportunity.enabled') ? 'Checking your business now.' : 'Growth checks are not switched on yet.']);
    }

    /**
     * @return array{0: Workspace, 1: Business, 2: GrowthViewer}
     */
    private function context(string $workspaceUid, string $businessUid): array
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::AiCooBasic->value);

        $this->authorize('business_advisor');

        return [$workspace, $business, GrowthViewer::resolve($this->locationGuard, (int) Auth::id(), $business)];
    }

    /** @return array<string, mixed> */
    private function shared(Workspace $workspace, Business $business, GrowthViewer $viewer, string $tab): array
    {
        $latest = $viewer->maySeeScore() ? $this->scores->latest($business) : null;
        $baseline = $latest !== null ? $this->scores->baseline($business, $latest) : null;

        return [
            'workspaceUid' => $workspace->uid,
            'businessUid' => $business->uid,
            'business' => $business,
            'tab' => $tab,
            'viewer' => $viewer,
            'engineEnabled' => (bool) config('opportunity.enabled', false),
            'score' => $latest,
            'movement' => $latest !== null ? $this->scores->movement($latest, $baseline) : null,
            'scoreHistory' => $latest !== null ? $this->scores->history($business) : [],
            'canSeeScore' => $viewer->maySeeScore(),
            'openCount' => $this->reader->applyState($this->reader->base($business, $viewer), GrowthOpportunityReader::STATE_OPEN)->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function presentAll($opportunities, Business $business, string $workspaceUid, string $businessUid): array
    {
        $names = GrowthOpportunityPresenter::locationNames($business->id);

        return $opportunities->map(fn (Opportunity $o) => $this->presenter->present($o, $names, $workspaceUid, $businessUid))->all();
    }

    /**
     * The nine score categories as owner-facing cards, from the stored
     * snapshot. An unscored category says "Not enough data" — never a number.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoryCards(Business $business, GrowthViewer $viewer): array
    {
        $latest = $viewer->maySeeScore() ? $this->scores->latest($business) : null;
        $baseline = $latest !== null ? $this->scores->baseline($business, $latest) : null;
        $cards = [];

        foreach (GrowthScoreCategory::cases() as $category) {
            $row = $latest?->breakdown[$category->value] ?? ['score' => null, 'status' => 'not_enough_data', 'rules' => []];
            $rules = [];

            foreach ($row['rules'] ?? [] as $rule) {
                $definition = GrowthRuleRegistry::find($rule['key'])?->definition();
                $rules[] = [
                    'title' => $definition?->title ?? $rule['key'],
                    'status' => $rule['status'],
                    'weight' => $rule['weight'],
                    'health' => $rule['health'],
                ];
            }

            $cards[] = [
                'key' => $category->value,
                'label' => $category->label(),
                'score' => $row['score'],
                'status' => $row['status'],
                'rules' => $rules,
                'delta' => $this->categoryDelta($latest, $baseline, $category),
            ];
        }

        return $cards;
    }

    private function categoryDelta($latest, $baseline, GrowthScoreCategory $category): ?int
    {
        if ($latest === null) {
            return null;
        }

        $now = $latest->category_scores[$category->value] ?? null;
        $then = $baseline?->category_scores[$category->value] ?? null;

        return ($now === null || $then === null) ? null : (int) $now - (int) $then;
    }

    /** The optional second handoff (e.g. "Build a follow-up automation") for lead-response findings. */
    private function secondaryAction(Opportunity $o, string $workspaceUid, string $businessUid): ?array
    {
        if (! in_array($o->type, ['crm.unanswered_new_leads:v1', 'conversations.inbound_awaiting_reply:v1'], true)) {
            return null;
        }

        $url = GrowthNavigation::url('automations', $workspaceUid, $businessUid);

        return $url === null ? null : ['label' => 'Build a follow-up automation', 'url' => $url];
    }

    private function back(string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.growth.opportunities.show', [$workspaceUid, $businessUid, $opportunityUid]);
    }
}
