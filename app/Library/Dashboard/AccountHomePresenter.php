<?php

namespace App\Library\Dashboard;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\BusinessCandidate;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\WorkspaceCandidate;
use App\Library\Usage\UsageWalletManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
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
 */
final class AccountHomePresenter
{
    public function __construct(
        private readonly DashboardStatusReader $statusReader,
        private readonly EntitlementManager $entitlementManager,
        private readonly UsageWalletManager $walletManager,
        private readonly ParentAccountSwitch $parentSwitch,
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
        $clients = $workspace->accessibleBusinesses();
        $statuses = null;

        try {
            $statuses = $this->statusReader->forBusinesses(array_map(fn (BusinessCandidate $b) => $b->id, $clients));
            $bands[DashboardSnapshot::BAND_CLIENTS] = $this->clients($user, $workspace, $clients, $statuses);
        } catch (Throwable $e) {
            report($e);
            $failed[] = DashboardSnapshot::BAND_CLIENTS;
        }

        $model = null;

        try {
            $model = Workspace::query()->find($workspace->id);

            if ($model !== null) {
                $bands[DashboardSnapshot::BAND_CAPACITY] = $this->capacity($user, $workspace, $model);
            }
        } catch (Throwable $e) {
            report($e);
            $failed[] = DashboardSnapshot::BAND_CAPACITY;
        }

        if (Route::has('customer.prospecting.index') && Gate::forUser($user)->allows('access_backend')) {
            try {
                $bands[DashboardSnapshot::BAND_PROSPECTING] = $this->prospecting($workspace);
            } catch (Throwable $e) {
                report($e);
                $failed[] = DashboardSnapshot::BAND_PROSPECTING;
            }
        }

        try {
            $bands[DashboardSnapshot::BAND_ACCOUNT] = $this->accountHealth($workspace, $model, $statuses);
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
     * @param  array<int, BusinessCandidate>  $clients
     * @param  array<int, BusinessStatusRow>  $statuses
     * @return array{rows: array<int, array<string, mixed>>, switchUrl: ?string, manageUrl: ?string}
     */
    private function clients(User $user, WorkspaceCandidate $workspace, array $clients, array $statuses): array
    {
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
                'switch' => $client->isSelectable() ? ['workspace' => $client->workspaceUid, 'business' => $client->uid] : null,
                'flags' => $flags,
                'rank' => $flags !== [] ? $flags[0]['severity']->rank() : 3,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['rank'], $a['name']] <=> [$b['rank'], $b['name']]);

        return [
            'rows' => $rows,
            'switchUrl' => Route::has('customer.context.business.switch') ? route('customer.context.business.switch') : null,
            'manageUrl' => $this->manageUrl($user, $workspace),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capacity(User $user, WorkspaceCandidate $workspace, Workspace $model): array
    {
        $decision = $this->entitlementManager->decideBusinessSlotCapacity($model);

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
     * Operational counts over the Agency's own prospecting rows, one
     * statement. No reply rate, booking or conversion figure (§4.1).
     *
     * @return array{activeCampaigns: int, prospects: int, url: string}
     */
    private function prospecting(WorkspaceCandidate $workspace): array
    {
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
            ->first();

        return [
            'activeCampaigns' => (int) ($row->active_campaigns ?? 0),
            'prospects' => (int) ($row->prospects ?? 0),
            'url' => route('customer.prospecting.index'),
        ];
    }

    /**
     * Plan and the Agency-wide spending controls — the one aggregate §7
     * permits, because the account owner set it. Shown to the owner and to
     * an Agency-wide admin only: a staff-scoped admin would otherwise read a
     * total that includes clients outside their scope.
     *
     * @param  array<int, BusinessStatusRow>|null  $statuses
     * @return array<string, mixed>
     */
    private function accountHealth(WorkspaceCandidate $workspace, ?Workspace $model, ?array $statuses): array
    {
        $health = [
            'plan' => $workspace->tierDisplayName,
            'controls' => null,
        ];

        $agencyWide = $workspace->isOwner || ($workspace->membershipActive
            && $workspace->membershipRole === WorkspaceMembershipRole::Admin
            && $workspace->membershipScope === WorkspaceBusinessAccessScope::All);

        if ($model === null || ! $agencyWide) {
            return $health;
        }

        $controls = $this->walletManager->workspaceControls($model);

        // One currency or none: a sum across currencies is not an amount.
        $currencies = $statuses === null ? [] : array_values(array_unique(array_filter(
            array_map(fn (BusinessStatusRow $row) => $row->currencyCode, $statuses),
            fn (?string $code) => $code !== null && $code !== '',
        )));
        $mixed = count($currencies) > 1;
        $currency = $currencies[0] ?? null;

        $health['controls'] = [
            'paused' => (bool) $controls['paid_activity_paused'],
            'mixedCurrencies' => $mixed,
            'spentThisPeriod' => $mixed ? null : DashboardMoney::format($controls['workspace_paid_spend_this_period_micro'], $currency),
            'spendLimit' => $controls['monthly_aggregate_spend_cap_micro'] !== null && ! $mixed
                ? DashboardMoney::format($controls['monthly_aggregate_spend_cap_micro'], $currency)
                : null,
            'hasSpendLimit' => $controls['monthly_aggregate_spend_cap_micro'] !== null,
        ];

        return $health;
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

        $emptyState = [
            'title' => $inactive === [] ? 'Create your first ' . $noun : 'No active ' . $noun . ' yet',
            'explanation' => $inactive === []
                ? 'Your home page fills in once you have a ' . $noun . ': what needs attention, what happened in the last 30 days and what to do next.'
                : '“' . $inactive[0]->name . '” is not active yet. Your home page fills in once a ' . $noun . ' is active.',
            'state' => 'empty',
            'primary' => $primaryUrl !== null
                ? ['label' => $inactive === [] ? 'Create your first ' . $noun : 'Review your ' . $noun . ($noun === 'business' ? 'es' : 's'), 'url' => $primaryUrl]
                : null,
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
