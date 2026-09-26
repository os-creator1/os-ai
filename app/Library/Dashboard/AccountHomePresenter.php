<?php

namespace App\Library\Dashboard;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\BusinessCandidate;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\WorkspaceCandidate;
use App\Library\Usage\UsageWalletManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Customer Experience Slice 4 §7 / §12 — every dashboard branch with no
 * Business selected.
 *
 *  agency   Agency Account Home: an Agency owner or active admin at the
 *           account frame. Flags and counts only — never a client's messages,
 *           contacts or KPIs (no N × B5 fan-out); content lives inside the
 *           client's own Business Home.
 *  chooser  several Businesses and none chosen: the Slice 1B choice, as
 *           server-authorized switch forms. Restricted staff always land here
 *           (or on their one Business), never on the Agency account bands.
 *  zero     no Business the actor can enter: one clear primary action.
 *
 * The Account frame carries no Business, so the request's entitlement
 * snapshot is empty and costs nothing here (Slice 2A §6.5).
 *
 * Unified Home §3.1 (A-1) reshapes the Agency branch: the portfolio now
 * carries cross-client performance and the outreach truth table for a
 * selected period, while capacity and account billing appear only when
 * they need an action. It makes no AI call — a cross-client summary would
 * put several clients' facts in one prompt, which §15 forbids.
 */
final class AccountHomePresenter
{
    /**
     * The periods the portfolio band offers. The canonical vocabulary and
     * every boundary come from AnalyticsDateRange, so Home and Results can
     * never disagree about what "Last 30 days" means; a custom range stays
     * with the Business surfaces that own the range control, and anything
     * else in the query string simply leaves the default selected.
     */
    private const PORTFOLIO_PRESETS = [
        AnalyticsDateRange::PRESET_THIS_MONTH,
        AnalyticsDateRange::PRESET_LAST_MONTH,
        AnalyticsDateRange::PRESET_LAST_7_DAYS,
        AnalyticsDateRange::PRESET_LAST_30_DAYS,
        AnalyticsDateRange::PRESET_LAST_90_DAYS,
    ];

    public function __construct(
        private readonly DashboardStatusReader $statusReader,
        private readonly EntitlementManager $entitlementManager,
        private readonly UsageWalletManager $walletManager,
        private readonly ParentAccountSwitch $parentSwitch,
        private readonly BusinessAnalyticsQueries $analyticsQueries,
        private readonly BusinessConversationReadModel $conversations,
        private readonly AgencyClientPortfolio $clientPortfolio,
        private readonly \App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository $agencyClientRelationshipRepository,
    ) {
    }

    public function present(CustomerContext $context, User $user): DashboardSnapshot
    {
        $parent = $this->parentSwitch->for($user, $context);

        if ($context->selectableBusinesses() === []) {
            return $this->zero($context, $user, $parent);
        }

        $workspace = $context->frameWorkspace();

        if ($workspace !== null && $workspace->isAgency() && $workspace->canManage()) {
            return $this->agency($context, $user, $workspace, $parent);
        }

        return $this->chooser($context, $user, $parent);
    }

    /**
     * @param  array{label: string, url: string, message: string}|null  $parent
     */
    private function agency(CustomerContext $context, User $user, WorkspaceCandidate $workspace, ?array $parent): DashboardSnapshot
    {
        $bands = [];
        $failed = [];

        // V1 topology: an Agency Workspace holds exactly ONE Business — its
        // own — and manages each client through an ACTIVE
        // AgencyClientWorkspaceRelationship to that client's SEPARATE
        // Workspace. The portfolio therefore comes from the relationship set
        // (the same authority the Agency Clients surface uses), never from
        // this Workspace's own Businesses: that older read could only ever
        // return the Agency itself, which is not a client of itself.
        $clients = $this->clientPortfolio->activeClientsFor((int) $workspace->id);
        $statuses = null;
        $range = $this->period();

        try {
            $statuses = $this->statusReader->forBusinesses(array_map(fn (BusinessCandidate $b) => $b->id, $clients));
            $bands[DashboardSnapshot::BAND_CLIENTS] = $this->clients($user, $workspace, $clients, $statuses);
        } catch (Throwable $e) {
            report($e);
            $failed[] = DashboardSnapshot::BAND_CLIENTS;
        }

        try {
            $bands[DashboardSnapshot::BAND_CROSS_CLIENT] = $this->crossClientPerformance($clients, $range);
        } catch (Throwable $e) {
            report($e);
            $failed[] = DashboardSnapshot::BAND_CROSS_CLIENT;
        }

        if (Route::has('customer.prospecting.index') && Gate::forUser($user)->allows('access_backend')) {
            try {
                $bands[DashboardSnapshot::BAND_PROSPECTING] = $this->prospecting($workspace, $range);
            } catch (Throwable $e) {
                report($e);
                $failed[] = DashboardSnapshot::BAND_PROSPECTING;
            }
        }

        $model = null;

        try {
            $model = Workspace::query()->find($workspace->id);
            $capacity = $model === null ? null : $this->capacity($user, $workspace, $model);

            if ($capacity !== null) {
                $bands[DashboardSnapshot::BAND_CAPACITY] = $capacity;
            }
        } catch (Throwable $e) {
            report($e);
            $failed[] = DashboardSnapshot::BAND_CAPACITY;
        }

        try {
            $account = $this->accountHealth($workspace, $model);

            if ($account !== null) {
                $bands[DashboardSnapshot::BAND_ACCOUNT] = $account;
            }
        } catch (Throwable $e) {
            report($e);
            $failed[] = DashboardSnapshot::BAND_ACCOUNT;
        }

        if ($parent !== null) {
            $bands[DashboardSnapshot::BAND_TEAM_ACCOUNT] = $parent;
        }

        return new DashboardSnapshot(
            kind: DashboardSnapshot::KIND_AGENCY,
            frameLabel: 'Agency account home',
            heading: $workspace->name,
            bands: $bands,
            failedBands: $failed,
        );
    }

    /**
     * Client accounts with their flags — the ones needing the owner first.
     *
     * OPENING A CLIENT IS THE CANONICAL AGENCY VIEW AS, the one the Agency
     * Clients surface already uses (customer.workspaces.clients.view-as →
     * AgencyClientsController::viewAs → ViewAsManager::startAgencyView). A
     * managed client lives in its own Workspace and is NOT an ordinary
     * context-switcher entry, so the Business switch form this band used to
     * render — valid only for the retired same-Workspace sibling Businesses —
     * is gone. No authorization is duplicated here or in the view: the row
     * carries a URL only for a client this Agency actively manages whose
     * Workspace and Business are both active, and that endpoint re-proves the
     * relationship, this actor's Agency authority, the Agency's management
     * eligibility and the client's own state before any view begins.
     *
     * @param  array<int, BusinessCandidate>  $clients
     * @param  array<int, BusinessStatusRow>  $statuses
     * @return array{rows: array<int, array<string, mixed>>, manageUrl: ?string}
     */
    private function clients(User $user, WorkspaceCandidate $workspace, array $clients, array $statuses): array
    {
        $canOpen = Route::has('customer.workspaces.clients.view-as');

        $rows = [];

        foreach ($clients as $client) {
            $flags = [];

            foreach (($statuses[$client->id] ?? BusinessStatusRow::empty($client->id))->attentionTypes() as $type) {
                $flags[] = [
                    'type' => $type,
                    'severity' => $type->severity(),
                    'text' => $type->sentence(),
                ];
            }

            usort($flags, fn (array $a, array $b) => $a['severity']->rank() <=> $b['severity']->rank());

            $rows[] = [
                'name' => $client->name,
                'active' => $client->isSelectable(),
                'statusWord' => $client->isSelectable() ? 'Active' : 'Not active',
                'openUrl' => $canOpen && $client->isSelectable()
                    ? route('customer.workspaces.clients.view-as', [$workspace->uid, $client->workspaceUid])
                    : null,
                'flags' => $flags,
                'rank' => $flags !== [] ? $flags[0]['severity']->rank() : 3,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['rank'], $a['name']] <=> [$b['rank'], $b['name']]);

        return [
            'rows' => $rows,
            'manageUrl' => $this->manageUrl($user, $workspace),
        ];
    }

    /**
     * Unified Home §3.1 (A-1) — capacity earns Home space only when it needs
     * an action, and the canonical decision already says when that is:
     * `allowed === false` is exactly the actionable set (no plan assigned, a
     * suspended or inactive plan, every slot in use, an allocation needed).
     * A healthy allowance is a settings fact, not a headline, and lives on
     * Settings → Account where it always has.
     *
     * @return array<string, mixed>|null  null when nothing needs doing
     */
    private function capacity(User $user, WorkspaceCandidate $workspace, Workspace $model): ?array
    {
        $decision = $this->entitlementManager->decideBusinessSlotCapacity($model);

        if ($decision->allowed) {
            return null;
        }

        $sentence = match (true) {
            $decision->unlimited => 'This plan includes unlimited client accounts.',
            $decision->allowed => 'You can add ' . self::plural($decision->effectiveCapacity - $decision->currentBusinessCount, 'more client account') . '.',
            default => match ($decision->denialReason) {
                'workspace_plan_unassigned' => 'No plan is assigned to this account yet, so no client account can be added.',
                'plan_suspended' => 'The plan is suspended, so no client account can be added.',
                'plan_inactive' => 'The plan is not active, so no client account can be added.',
                'business_slot_allocation_required' => 'Every included client account slot is in use. Add a slot to create another client account.',
                default => 'Every client account slot this plan allows is in use.',
            },
        };

        return [
            'used' => $decision->currentBusinessCount,
            'capacity' => $decision->unlimited ? null : $decision->effectiveCapacity,
            'unlimited' => $decision->unlimited,
            'allowed' => $decision->allowed,
            'sentence' => $sentence,
            'manageUrl' => $this->manageUrl($user, $workspace),
        ];
    }

    /**
     * Unified Home §3.1 (A-1) — the portfolio's period.
     *
     * The Account frame holds no Business, and clients may sit in different
     * timezones, so there is no single Business calendar to borrow: the
     * window is built in the application's own timezone, which is also the
     * one the counted columns are stored in. Every boundary, and its DST
     * behaviour, comes from AnalyticsDateRange rather than from arithmetic
     * here.
     */
    private function period(): AnalyticsDateRange
    {
        $requested = request()->query('range');

        $preset = is_string($requested) && in_array($requested, self::PORTFOLIO_PRESETS, true)
            ? $requested
            : AnalyticsDateRange::PRESET_THIS_MONTH;

        return AnalyticsDateRange::preset($preset, (string) config('app.timezone', 'UTC'));
    }

    /**
     * Unified Home §3.1 (A-1) — new contacts and new conversations per
     * client for the selected period.
     *
     * Two grouped statements over the whole authorized id list, whatever the
     * number of clients: one B5 statement for contacts, one 2B statement for
     * conversations, each owned by the seam that owns the table. Never a
     * query per client — that fan-out is the thing this band exists to
     * avoid — and never a figure this page computed for itself.
     *
     * A client with nothing in the period shows 0, which is a fact about a
     * client the actor can already see, not an invented one.
     *
     * @param  array<int, BusinessCandidate>  $clients
     * @return array<string, mixed>
     */
    private function crossClientPerformance(array $clients, AnalyticsDateRange $range): array
    {
        $ids = array_map(fn (BusinessCandidate $b) => (int) $b->id, $clients);

        $contacts = $this->analyticsQueries->newContactsForBusinesses($ids, $range->startUtc, $range->endUtc);
        $conversations = $this->conversations->startedCountsForBusinesses($ids, $range->startUtc, $range->endUtc);

        $rows = [];
        $totalContacts = 0;
        $totalConversations = 0;

        foreach ($clients as $client) {
            $newContacts = (int) ($contacts[$client->id] ?? 0);
            $newConversations = (int) ($conversations[$client->id] ?? 0);
            $totalContacts += $newContacts;
            $totalConversations += $newConversations;

            $rows[] = [
                'name' => $client->name,
                'active' => $client->isSelectable(),
                'newContacts' => $newContacts,
                'newConversations' => $newConversations,
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['name'] <=> $b['name']);

        return [
            'rows' => $rows,
            'totals' => ['newContacts' => $totalContacts, 'newConversations' => $totalConversations],
            'periods' => $this->periodChoices($range),
            'rangeLabel' => $range->label(),
            'spanLabel' => $range->spanLabel(),
        ];
    }

    /**
     * The period control's options — canonical presets only, each as a link
     * back to this page.
     *
     * @return array<int, array{value: string, label: string, url: string, selected: bool}>
     */
    private function periodChoices(AnalyticsDateRange $range): array
    {
        $choices = [];

        foreach (self::PORTFOLIO_PRESETS as $preset) {
            $choices[] = [
                'value' => $preset,
                'label' => AnalyticsDateRange::preset($preset, $range->timezone)->label(),
                'url' => route('user.home', ['range' => $preset]),
                'selected' => $preset === $range->preset,
            ];
        }

        return $choices;
    }

    /**
     * Unified Home §3.1 (A-1) — the outreach truth table, one statement.
     *
     * Every figure is a persisted `agency_prospect_*` fact for the selected
     * period, and nothing is derived from another:
     *
     *  - contacted: campaign members with an outbound message actually sent
     *    in the period, counted once each however many were sent;
     *  - replies: inbound messages received in the period;
     *  - positive replies (A-2): of those inbound messages, the ones whose
     *    persisted `intent` is exactly `positive` — the value
     *    `AgencyProspectingRespondJob` durably wrote from the existing
     *    `AgencyProspectAiDecision`. Never derived from booking, scheduling
     *    or any other intent, and never "all replies"; a null intent (no
     *    decision was ever validated for that message) does not count;
     *  - booked: prospects whose `booked_at` falls in the period;
     *  - failures: outbound messages that failed. A failed send never gets a
     *    `sent_at` — the jobs write only `status` — so the attempt's own row
     *    timestamp is the only time it has, and it is what places the
     *    failure in the period.
     *
     * The two pre-existing counts (active campaigns, prospects) stay as they
     * were — this band is extended, not replaced. No reply rate, booking
     * rate or conversion figure is computed from any of it (§4.1).
     *
     * @return array<string, mixed>
     */
    private function prospecting(WorkspaceCandidate $workspace, AnalyticsDateRange $range): array
    {
        $start = $range->startUtc;
        $end = $range->endUtc;

        $outboundInPeriod = fn () => AgencyProspectMessage::query()
            ->where('workspace_id', $workspace->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND);

        $row = DB::query()
            ->selectSub(
                AgencyProspectCampaign::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('status', AgencyProspectCampaignStatus::Active->value)
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'active_campaigns',
            )
            ->selectSub(
                AgencyProspect::query()->where('workspace_id', $workspace->id)->selectRaw('COUNT(*)')->toBase(),
                'prospects',
            )
            ->selectSub(
                $outboundInPeriod()
                    ->where('sent_at', '>=', $start)
                    ->where('sent_at', '<', $end)
                    ->selectRaw('COUNT(DISTINCT campaign_member_id)')
                    ->toBase(),
                'contacted',
            )
            ->selectSub(
                AgencyProspectMessage::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
                    ->where('received_at', '>=', $start)
                    ->where('received_at', '<', $end)
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'replies',
            )
            ->selectSub(
                AgencyProspectMessage::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
                    ->where('intent', 'positive')
                    ->where('received_at', '>=', $start)
                    ->where('received_at', '<', $end)
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'positive_replies',
            )
            ->selectSub(
                AgencyProspect::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('booked_at', '>=', $start)
                    ->where('booked_at', '<', $end)
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'booked',
            )
            ->selectSub(
                $outboundInPeriod()
                    ->where('status', AgencyProspectMessage::STATUS_FAILED)
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end)
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'failures',
            )
            ->first();

        return [
            'activeCampaigns' => (int) ($row->active_campaigns ?? 0),
            'prospects' => (int) ($row->prospects ?? 0),
            'contacted' => (int) ($row->contacted ?? 0),
            'replies' => (int) ($row->replies ?? 0),
            'positiveReplies' => (int) ($row->positive_replies ?? 0),
            'booked' => (int) ($row->booked ?? 0),
            'failures' => (int) ($row->failures ?? 0),
            'rangeLabel' => $range->label(),
            'url' => route('customer.prospecting.index'),
        ];
    }

    /**
     * Unified Home §3.1 / §5 (A-1) — an Agency account billing problem that
     * needs an action, and nothing else.
     *
     * Routine spend is never a Home figure: the plan, the amount spent, the
     * Agency-wide limit and the invoices stay on Settings → Account and
     * Settings → Billing, where they always were. What remains here is the
     * one account-level state that stops work across every client —
     * Agency-wide paid activity paused — read from the same wallet controls
     * the account owner set. A client's own billing exception is already a
     * flag on that client's row and is not repeated here.
     *
     * Still Agency-wide actors only: a staff-scoped admin is never shown an
     * account-wide state covering clients outside their scope.
     *
     * @return array<string, mixed>|null  null when nothing needs doing
     */
    private function accountHealth(WorkspaceCandidate $workspace, ?Workspace $model): ?array
    {
        $agencyWide = $workspace->isOwner || ($workspace->membershipActive
            && $workspace->membershipRole === WorkspaceMembershipRole::Admin
            && $workspace->membershipScope === WorkspaceBusinessAccessScope::All);

        if ($model === null || ! $agencyWide) {
            return null;
        }

        $controls = $this->walletManager->workspaceControls($model);

        if (! $controls['paid_activity_paused']) {
            return null;
        }

        return [
            'plan' => $workspace->tierDisplayName,
            'paused' => true,
            'sentence' => 'Paid activity is paused across this agency account, so messaging is stopped for every client account.',
            'manageUrl' => Route::has('customer.workspaces.show') ? route('customer.workspaces.show', $workspace->uid) : null,
        ];
    }

    /**
     * @param  array{label: string, url: string, message: string}|null  $parent
     */
    private function chooser(CustomerContext $context, User $user, ?array $parent): DashboardSnapshot
    {
        $bands = [
            DashboardSnapshot::BAND_CHOOSER => [
                'noun' => strtolower($context->businessNoun()),
                'showWorkspace' => $context->hasMultipleWorkspaces(),
                'switchUrl' => Route::has('customer.context.business.switch') ? route('customer.context.business.switch') : null,
                'businesses' => array_map(fn (BusinessCandidate $b) => [
                    'name' => $b->name,
                    'workspaceName' => $b->workspaceName,
                    'workspace' => $b->workspaceUid,
                    'business' => $b->uid,
                ], $context->selectableBusinesses()),
            ],
        ];

        if ($parent !== null) {
            $bands[DashboardSnapshot::BAND_TEAM_ACCOUNT] = $parent;
        }

        return new DashboardSnapshot(
            kind: DashboardSnapshot::KIND_CHOOSER,
            frameLabel: 'Account home',
            heading: $context->frameWorkspace()?->name ?? $user->displayName(),
            bands: $bands,
        );
    }

    /**
     * §12 — no zeroed tiles (a zero is a claim, and it would be false): one
     * state, one clear primary action, and for a team member the preserved
     * route back to the main account.
     *
     * @param  array{label: string, url: string, message: string}|null  $parent
     */
    private function zero(CustomerContext $context, User $user, ?array $parent): DashboardSnapshot
    {
        $noun = strtolower($context->businessNoun());
        $inactive = $context->accessibleButNotActiveBusinesses();
        $primaryUrl = $this->createUrl($context, $user);

        // Contract 07 correction — a newly-invited client landing here has
        // exactly one Draft Business (AgencyClientProvisioningManager::
        // accept()'s own placeholder), which the generic createUrl() above
        // sends to the account overview page rather than the one place
        // that actually moves it forward. A findable, direct link beats an
        // extra hop through a page that itself links onward.
        $draftActivationUrl = $this->draftClientActivationUrl($context, $user, $inactive);

        $emptyState = [
            'title' => $inactive === [] ? 'Create your first ' . $noun : 'No active ' . $noun . ' yet',
            'explanation' => $inactive === []
                ? 'Your home page fills in once you have a ' . $noun . ': what needs attention, what happened in the last 30 days and what to do next.'
                : ($draftActivationUrl !== null
                    ? '"' . $inactive[0]->name . '" is waiting for you to review and confirm its details before it can go live.'
                    : '"' . $inactive[0]->name . '" is not active yet. Your home page fills in once a ' . $noun . ' is active.'),
            'state' => 'empty',
            'primary' => $draftActivationUrl !== null
                ? ['label' => 'Finish setting up your Business', 'url' => $draftActivationUrl]
                : ($primaryUrl !== null
                    ? ['label' => $inactive === [] ? 'Create your first ' . $noun : 'Review your ' . $noun . ($noun === 'business' ? 'es' : 's'), 'url' => $primaryUrl]
                    : null),
            'secondary' => null,
            'ownerHint' => $primaryUrl === null && $parent === null ? 'Ask your account owner to give you access to a ' . $noun . '.' : null,
            'icon' => 'briefcase',
            'headingId' => 'dashboard-empty-heading',
        ];

        if ($parent !== null) {
            $emptyState['explanation'] = $parent['message'];
            $emptyState['secondary'] = ['label' => $parent['label'], 'url' => $parent['url']];
        }

        return new DashboardSnapshot(
            kind: DashboardSnapshot::KIND_ZERO,
            frameLabel: 'Account home',
            heading: $context->frameWorkspace()?->name ?? $user->displayName(),
            emptyState: $emptyState,
        );
    }

    /**
     * Where a first Business is created, in order: guided onboarding when it
     * is switched on; the manageable account's own page (its "Create
     * Business" form); the account list when the actor has no account yet.
     * Restricted staff get no action — only who can help.
     */
    private function createUrl(CustomerContext $context, User $user): ?string
    {
        if (! Gate::forUser($user)->allows('access_backend')) {
            return null;
        }

        if (config('business.onboarding.enabled', false) && Route::has('customer.onboarding.show')) {
            return route('customer.onboarding.show');
        }

        $workspace = $context->frameWorkspace();

        if ($workspace !== null && $workspace->canManage() && Route::has('customer.workspaces.show')) {
            return route('customer.workspaces.show', $workspace->uid);
        }

        $managesAny = false;

        foreach ($context->workspaces as $candidate) {
            $managesAny = $managesAny || $candidate->canManage();
        }

        if (($context->workspaces === [] || $managesAny) && Route::has('customer.workspaces.index')) {
            return route('customer.workspaces.index');
        }

        return null;
    }

    /**
     * Contract 07 correction — a direct link to the client owner's own
     * draft-activation step (ClientBusinessActivationController), offered
     * only when EVERY one of these holds, so it can never substitute for
     * the account page's more general "review your Businesses" link in any
     * other shape of "not active yet":
     *
     *  - exactly one inactive Business is reachable at all (several, or an
     *    Inactive rather than Draft one, keep the existing generic link —
     *    this is deliberately not a general Draft-Business finder);
     *  - that Business is genuinely Draft, not merely Inactive;
     *  - the actor is that Business's own Workspace OWNER — never an
     *    Admin, Staff, or the inviting Agency, matching
     *    ClientBusinessActivationController::resolveOwnedWorkspace()'s own
     *    owner-only gate exactly, so this link is never shown to someone
     *    the route itself would 404;
     *  - the Workspace is a genuine Agency-managed Client Workspace (an
     *    ACTIVE AgencyClientWorkspaceRelationship names it) — the
     *    businesses table itself defaults every row to Draft
     *    (WorkspaceController's own identical scoping correction applies
     *    here too), so an ordinary, not-yet-provisioned Workspace with no
     *    Agency involved at all would otherwise also match.
     *
     * @param  array<int, BusinessCandidate>  $inactive
     */
    private function draftClientActivationUrl(CustomerContext $context, User $user, array $inactive): ?string
    {
        if (count($inactive) !== 1 || $inactive[0]->status !== BusinessStatus::Draft->value) {
            return null;
        }

        if (! Gate::forUser($user)->allows('access_backend') || ! Route::has('customer.workspaces.businesses.activate.show')) {
            return null;
        }

        $business = $inactive[0];
        $workspace = null;

        foreach ($context->workspaces as $candidate) {
            if ($candidate->uid === $business->workspaceUid) {
                $workspace = $candidate;

                break;
            }
        }

        if ($workspace === null || ! $workspace->isOwner) {
            return null;
        }

        if ($this->agencyClientRelationshipRepository->findActiveForClientWorkspace($workspace->id) === null) {
            return null;
        }

        return route('customer.workspaces.businesses.activate.show', [$business->workspaceUid, $business->uid]);
    }

    private function manageUrl(User $user, WorkspaceCandidate $workspace): ?string
    {
        return Route::has('customer.workspaces.show') && Gate::forUser($user)->allows('access_backend')
            ? route('customer.workspaces.show', $workspace->uid)
            : null;
    }

    private static function plural(?int $count, string $noun): string
    {
        $count = max(0, (int) $count);

        return $count . ' ' . $noun . ($count === 1 ? '' : 's');
    }
}
