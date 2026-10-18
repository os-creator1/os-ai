<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\CalendarScheduleService;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Dashboard\Home\HomeActionRouter;
use App\Library\Dashboard\Home\HomeAskRegistry;
use App\Library\Dashboard\Home\HomeBookingReader;
use App\Library\Growth\GrowthOpportunityPresenter;
use App\Library\Growth\GrowthOpportunityReader;
use App\Library\Growth\GrowthViewer;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Opportunity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Home V2 — the READ side of the in-Home action drawers, and the Ask MotionGrove
 * resolver.
 *
 * NOTHING HERE WRITES. A drawer is an HTML fragment that shows real records and
 * carries a form whose action is the module's own endpoint
 * (conversations.reply, booking-types.store, appointments.store). So a drawer
 * can never be a bypass around its module: the write re-runs that module's
 * tenancy, entitlement, permission and Location gates, validation and
 * idempotency, and Home reports success only from that endpoint's answer.
 *
 * A drawer is served only when HomeActionRouter — the same four-way rule that
 * decides whether Home shows its button — says this actor may open it, and
 * every record is read through the seam the module itself uses (the
 * conversation read model with the inbox's Location rule; the calendar's
 * schedule service and Location resolver; the Growth reader's Location ACL).
 */
class HomeActionsController extends Controller
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly CustomerShellComposer $shell,
        private readonly HomeActionRouter $router,
        private readonly HomeBookingReader $booking,
        private readonly HomeAskRegistry $ask,
        private readonly BusinessConversationReadModel $conversations,
        private readonly CalendarScheduleService $schedule,
        private readonly CalendarLocationResolver $locations,
        private readonly BookingTypeManager $bookingTypes,
        private readonly GrowthOpportunityReader $growthReader,
        private readonly GrowthOpportunityPresenter $growthPresenter,
        private readonly LocationAccessGuard $locationGuard,
    ) {
    }

    public function drawer(Request $request, string $workspaceUid, string $businessUid, string $drawer): View
    {
        abort_unless(in_array($drawer, HomeActionRouter::DRAWERS, true), 404);

        [$workspace, $business, $context, $user, $actions] = $this->resolve($workspaceUid, $businessUid);

        $action = $actions[$drawer] ?? null;
        abort_if($action === null || $action['drawerUrl'] === null, 404);

        $scoped = [$workspaceUid, $businessUid];
        $common = ['action' => $action, 'scoped' => $scoped, 'business' => $business, 'viewingAs' => $context->isViewingAsClient()];

        return match ($drawer) {
            'follow-ups' => $this->followUps($request, $business, $user, $common),
            'booking-type' => $this->bookingTypeForm($workspaceUid, $businessUid, $business, $user, $common),
            'appointment' => $this->appointmentForm($request, $workspace, $business, $user, $common),
            'upcoming' => $this->upcoming($business, $user, $common),
            'ads' => $this->ads($business, $user, $workspaceUid, $businessUid, $common),
        };
    }

    /**
     * Ask MotionGrove. Resolves a request against the closed registry and
     * returns what the customer is offered — it never executes anything and
     * never calls an AI provider.
     */
    public function ask(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'max:200']]);

        [, , $context, $user, $actions] = $this->resolve($workspaceUid, $businessUid);

        $intent = $this->ask->match($validated['q']);
        $entitlements = $this->shell->currentMenuEntitlements($context);

        $advisorAsk = ($actions['advisor'] ?? null) !== null && $entitlements->allows('ai_coo_basic') && (bool) config('services.openai.active')
            ? route('customer.workspaces.businesses.growth.advisor.ask', [$workspaceUid, $businessUid])
            : null;

        return response()->json($this->ask->resolve($intent, $actions, $advisorAsk));
    }

    // ─── drawers ──────────────────────────────────────────────────────────

    private function followUps(Request $request, Business $business, User $user, array $common): View
    {
        $draft = $this->assistantDraft($request, $business, $user);

        $rows = $this->conversations->awaitingReplyConversations($business, (int) $user->id, 5);
        $names = ChatBox::displayNamesFor($business, array_map(fn (array $r): object => (object) ['id' => $r['id'], 'to' => $r['to']], $rows));
        [$workspaceUid, $businessUid] = $common['scoped'];

        $items = array_map(fn (array $r): array => [
            'name' => $names[$r['id']] ?? $r['to'],
            'phone' => $r['to'],
            'snippet' => Str::limit(trim($r['body']), 240),
            'at' => $r['at'],
            'token' => (string) Str::uuid(),
            'replyUrl' => route('customer.workspaces.businesses.conversations.reply', [$workspaceUid, $businessUid, $r['uid']]),
            // Ask MotionGrove prepared this text; it is only pre-filled. Sending is still the customer pressing Send.
            'draft' => $draft !== null && $draft['conversation_uid'] === $r['uid'] ? $draft['message'] : null,
        ], $rows);

        return view('customer.dashboard.drawers.follow-ups', $common + [
            'items' => $items,
            'total' => $this->conversations->awaitingReplyCount($business, null, (int) $user->id),
        ]);
    }

    /**
     * The reply Ask MotionGrove prepared for this customer, if the drawer was opened from its proposal: found only by
     * uid AND this Business AND this actor, and only a `draft_reply` the customer has already approved. Pre-fills a
     * textarea and nothing else.
     *
     * @return array{conversation_uid: string, message: string}|null
     */
    private function assistantDraft(Request $request, Business $business, User $user): ?array
    {
        $uid = (string) $request->query('proposal', '');

        if ($uid === '') {
            return null;
        }

        $proposal = \App\Models\AssistantActionProposal::query()
            ->where('uid', $uid)
            ->where('workspace_id', $business->workspace_id)
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('tool_key', 'draft_reply')
            ->where('status', \App\Models\AssistantActionProposal::HANDED_OFF)
            ->whereNotNull('conversation_id')
            ->whereNull('scrubbed_at')
            ->first();

        // Expired (handed off too long ago) or revoked (its conversation was deleted, which scrubs the proposal and
        // detaches it) hand-offs are refused; so is one whose conversation is not this actor's, in this Business.
        if ($proposal === null
            || $proposal->executed_at === null
            || $proposal->executed_at->lt(now()->subMinutes(max(1, (int) config('assistant.handoff_ttl_minutes'))))
            || ! \App\Models\AssistantConversation::query()
                ->whereKey($proposal->conversation_id)
                ->where('workspace_id', $business->workspace_id)
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->exists()) {
            return null;
        }

        return ['conversation_uid' => (string) ($proposal->params['conversation_uid'] ?? ''), 'message' => (string) ($proposal->params['message'] ?? '')];
    }

    private function bookingTypeForm(string $workspaceUid, string $businessUid, Business $business, User $user, array $common): View
    {
        $locations = $this->schedule->accessibleLocations($business, (int) $user->id);

        $choices = $locations->map(fn ($l): array => [
            'uid' => (string) $l->uid,
            'name' => $l->name ?: 'Main location',
            'storeUrl' => route('customer.workspaces.businesses.calendar.booking-types.store', [$workspaceUid, $businessUid, $l->uid]),
        ])->values()->all();

        return view('customer.dashboard.drawers.booking-type', $common + ['locations' => $choices]);
    }

    private function appointmentForm(Request $request, $workspace, Business $business, User $user, array $common): View
    {
        [$workspaceUid, $businessUid] = $common['scoped'];
        $accessible = $this->schedule->accessibleLocations($business, (int) $user->id);
        abort_if($accessible->isEmpty(), 404);

        $requested = (string) $request->query('location', '');
        $location = $requested === ''
            ? $accessible->first()
            // Re-derived through the module's own Location resolver; a guessed uid is a 404.
            : ($this->locations->resolveForActor($business, $requested, (int) $user->id) ?? abort(404));

        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $search = trim((string) $request->query('q', ''));
        $types = $this->bookingTypes->forLocation($location)->where('is_active', true)->values();
        $staff = $this->locations->eligibleStaff($workspace, $business, $location);

        return view('customer.dashboard.drawers.appointment', $common + [
            'location' => $location,
            'locations' => $accessible,
            'bookingTypes' => $types,
            'staff' => $staff,
            'search' => $search,
            'contacts' => $search === '' ? [] : $this->schedule->searchBookableContacts($business, $location, $search, 8),
            'timezone' => $timezone,
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'storeUrl' => route('customer.workspaces.businesses.calendar.appointments.store', [$workspaceUid, $businessUid, $location->uid]),
            'fragmentUrl' => $common['action']['drawerUrl'],
        ]);
    }

    private function upcoming(Business $business, User $user, array $common): View
    {
        [$workspaceUid, $businessUid] = $common['scoped'];
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $from = CarbonImmutable::now();
        $to = $from->addDays(14);
        $rows = [];

        foreach ($this->schedule->accessibleLocations($business, (int) $user->id)->take(5) as $location) {
            $appointments = $this->schedule->appointmentsForRange($location, $from, $to)
                ->filter(fn ($a): bool => $a->status->value === 'scheduled')
                ->take(10);
            $contacts = $this->schedule->contactSummaries($business, $appointments);

            foreach ($appointments as $a) {
                $contact = $contacts[(int) $a->contact_id] ?? null;
                $staffName = trim(((string) ($a->staff->first_name ?? '')) . ' ' . ((string) ($a->staff->last_name ?? '')));
                $rows[] = [
                    'start' => CarbonImmutable::parse($a->start_at)->utc()->setTimezone($timezone),
                    'type' => (string) ($a->bookingType->name ?? 'Appointment'),
                    'who' => $contact['name'] ?? $contact['phone'] ?? 'Customer',
                    'staff' => $staffName !== '' ? $staffName : null,
                    'location' => $location->name,
                    'url' => route('customer.workspaces.businesses.calendar.appointments.show', [$workspaceUid, $businessUid, $location->uid, $a->uid]),
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return view('customer.dashboard.drawers.upcoming', $common + ['rows' => array_slice($rows, 0, 10)]);
    }

    private function ads(Business $business, User $user, string $workspaceUid, string $businessUid, array $common): View
    {
        $viewer = GrowthViewer::resolve($this->locationGuard, (int) $user->id, $business);

        // Ads Decision Engine output reaches Home ONLY as Growth Opportunities (GrowthAdsFactReader):
        // the same evidence hierarchy, read from the persisted engine result, no provider call.
        $cards = $this->growthReader->top($business, $viewer, 15)
            ->filter(fn (Opportunity $o): bool => str_contains(strtolower((string) ($o->worker_key->value ?? $o->worker_key)), 'ads'))
            ->take(3)
            ->map(fn (Opportunity $o): array => $this->growthPresenter->present($o, [], $workspaceUid, $businessUid))
            ->values()
            ->all();

        return view('customer.dashboard.drawers.ads', $common + ['cards' => $cards]);
    }

    // ─── resolution ───────────────────────────────────────────────────────

    /**
     * @return array{0: mixed, 1: Business, 2: CustomerContext, 3: User, 4: array<string, mixed>}
     */
    private function resolve(string $workspaceUid, string $businessUid): array
    {
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        /** @var User $user */
        $user = Auth::user();
        $context = $this->shell->currentContext($user);

        // The context must be THIS route's Business — never "whatever is selected".
        abort_unless($context->isBusinessFrame()
            && $context->selectedBusiness !== null
            && $context->selectedBusiness->uid === $businessUid
            && $context->frameWorkspace()?->uid === $workspaceUid, 404);

        $entitlements = $this->shell->currentMenuEntitlements($context);
        $summary = $entitlements->allows('calendar') && \Illuminate\Support\Facades\Gate::forUser($user)->allows('access_backend')
            ? $this->booking->summary($business, (int) $user->id)
            : null;

        $actions = $this->router->forActor($context, $user, $entitlements, [$workspaceUid, $businessUid], $summary['firstLocationUid'] ?? null);

        return [$workspace, $business, $context, $user, $actions];
    }
}
