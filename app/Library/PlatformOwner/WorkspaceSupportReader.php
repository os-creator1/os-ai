<?php

namespace App\Library\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Entitlement\CustomerAccountAccessDecision;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\WorkspaceEntitlementSummary;
use App\Models\AgencyClientSubscription;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessPayerAssignment;
use App\Models\BusinessUsageWallet;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementTransition;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Support\Collection;

/**
 * Platform Owner / Admin V1 — the read model behind the Workspace support
 * cockpit and the Business detail page: "why can / can't this customer use
 * the product, and what is the state of everything that could explain it".
 *
 * READ-ONLY, and it decides NOTHING itself:
 *
 *  - The access answer is CustomerAccountAccessResolver::resolve() — the very
 *    method the customer-facing gate (CustomerAccountAccessGate) calls. This
 *    class wraps the returned decision; it never re-derives it from
 *    assignment status, timestamps, subscription status or an Agency's state.
 *  - The plan/tier/status/lifecycle facts are EntitlementManager's
 *    getWorkspaceEntitlementSummary(), the same seam the resolver itself
 *    reads.
 *  - "Does this subscription grant access" is
 *    PlatformSubscriptionStatus::grantsAccess(), the enum's own answer. A
 *    provider status is never interpreted by hand here, and entitlement is
 *    never inferred from it: the subscription is displayed BESIDE the
 *    decision, and a disagreement between the two is reported as a mismatch
 *    for a human to look at, not resolved.
 *  - No Stripe (or any provider) call is made. Everything shown was persisted
 *    by the domain earlier.
 *
 * BOUNDS. Every collection is capped (members, Businesses, Locations,
 * audit rows) and every lookup that spans Businesses is one batched query
 * keyed by the page's ids, so the query count stays flat as a Workspace
 * gains Businesses and Locations. Contract 13 makes Workspace<->Business
 * 1:1 today; the batched shape is still the safe one if that ever relaxes.
 */
final class WorkspaceSupportReader
{
    public const MAX_MEMBERS = 25;

    public const MAX_BUSINESSES = 25;

    public const MAX_LOCATIONS_PER_BUSINESS = 25;

    public const AUDIT_ROWS = 15;

    private const SUBSCRIPTION_COLUMNS = [
        'id', 'uid', 'workspace_id', 'workspace_plan_catalog_id', 'status', 'provider_customer_id',
        'provider_subscription_id', 'cancel_at_period_end', 'current_period_start', 'current_period_end',
        'trial_ends_at', 'canceled_at', 'ended_at', 'pending_plan_catalog_id', 'pending_effective_at',
        'last_event_at', 'last_reason', 'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly CustomerAccountAccessResolver $accessResolver,
        private readonly EntitlementManager $entitlementManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
        private readonly WorkspaceEntitlementTransitionRepository $transitionRepository,
        private readonly ProviderConnectionStatusReader $providers,
        private readonly AgencyWhiteLabelManager $whiteLabel,
    ) {
    }

    /**
     * The whole Workspace support picture.
     *
     * @return array<string, mixed>
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $decision = $this->accessResolver->resolve($workspace);
        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($workspace);

        $memberships = WorkspaceMembership::query()
            ->with(['user', 'assignedBusinesses'])
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->limit(self::MAX_MEMBERS + 1)
            ->get();

        $businesses = Business::query()
            ->with(['primaryLocation', 'customer.user'])
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->limit(self::MAX_BUSINESSES + 1)
            ->get();

        $businessesTruncated = $businesses->count() > self::MAX_BUSINESSES;
        $businesses = $businesses->take(self::MAX_BUSINESSES);
        $businessIds = $businesses->pluck('id')->map(fn ($id) => (int) $id)->all();

        $subscription = $this->subscriptionFor($workspace);
        $locationStats = $this->locationStats($businessIds);
        $payers = $this->payersFor($businessIds);
        $wallets = $this->walletsFor($businessIds);
        $stripe = $this->providers->stripeFor($businessIds);
        $email = $this->providers->emailFor($businessIds);
        $google = $this->providers->googleFor($businessIds);

        $memberUserIds = $memberships->take(self::MAX_MEMBERS)->pluck('user_id')
            ->push((int) $workspace->owner_user_id)->unique()->values()->all();
        $calendar = $this->providers->calendarFor($memberUserIds);

        $agency = $this->agencyFor($workspace);

        $businessRows = $businesses->map(function (Business $business) use ($decision, $locationStats, $payers, $wallets, $stripe, $email, $google, $agency) {
            $id = (int) $business->id;

            return [
                'business' => $business,
                'locations' => $locationStats[$id] ?? ['active' => 0, 'archived' => 0],
                'payer' => $payers->get($id),
                'wallet' => $wallets->get($id),
                'stripe' => $stripe->get($id),
                'email' => $email->get($id),
                'google' => $google->get($id),
                'accessGranted' => ! $decision->isLocked(),
                'agencyManaged' => $agency !== null,
            ];
        })->all();

        return [
            'workspace' => $workspace,
            'decision' => $decision,
            'summary' => $summary,
            'subscription' => $subscription,
            'mismatch' => $this->mismatchNote($decision, $summary, $subscription),
            'agency' => $agency,
            'memberships' => $memberships->take(self::MAX_MEMBERS),
            'membersTruncated' => $memberships->count() > self::MAX_MEMBERS,
            'businesses' => $businessRows,
            'businessesTruncated' => $businessesTruncated,
            'calendar' => $calendar,
            'canRestoreAccess' => $this->canRestoreAccess($summary),
            'recentActions' => $this->transitionRepository->recentForWorkspace((int) $workspace->id, self::AUDIT_ROWS),
        ];
    }

    /**
     * The Business detail page: the Workspace picture for the Business's own
     * Workspace plus this Business's bounded Location list.
     *
     * @return array<string, mixed>
     */
    public function forBusiness(Business $business): array
    {
        $workspace = $this->workspaceRepository->findById((int) $business->workspace_id);

        $detail = [
            'business' => $business,
            'workspace' => $workspace,
            'locations' => BusinessLocation::query()
                ->select(['id', 'uid', 'business_id', 'name', 'city', 'region', 'country_code', 'is_primary', 'lifecycle_state', 'archived_at'])
                ->where('business_id', $business->id)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->limit(self::MAX_LOCATIONS_PER_BUSINESS)
                ->get(),
            'support' => null,
        ];

        if ($workspace !== null) {
            $detail['support'] = $this->forWorkspace($workspace);
        }

        return $detail;
    }

    /**
     * Support-page eligibility for the one lifecycle control this surface
     * offers. It mirrors only the precondition EntitlementManager's own
     * recoverAccess() already enforces (an Active assignment) plus the
     * RECORDED Grace/Locked timestamps — never a running trial, which
     * recoverAccess() would convert. The manager re-checks everything under
     * its own lock when the action is submitted, so this only decides
     * whether to OFFER the button.
     */
    public function canRestoreAccess(WorkspaceEntitlementSummary $summary): bool
    {
        return $summary->isAssigned
            && $summary->status === WorkspacePlanAssignmentStatus::Active
            && ($summary->lockedAt !== null || $summary->graceStartedAt !== null);
    }

    private function subscriptionFor(Workspace $workspace): ?PlatformSubscription
    {
        return PlatformSubscription::query()
            ->select(self::SUBSCRIPTION_COLUMNS)
            ->with('catalog:id,tier,display_name')
            ->where('workspace_id', $workspace->id)
            ->first();
    }

    /**
     * Where the canonical decision and the lane-A subscription disagree, say
     * so. Both inputs are the domain's own outputs; this only compares them.
     */
    private function mismatchNote(
        CustomerAccountAccessDecision $decision,
        WorkspaceEntitlementSummary $summary,
        ?PlatformSubscription $subscription,
    ): ?string {
        if ($subscription === null || $subscription->status === null) {
            return null;
        }

        $grants = $subscription->status->grantsAccess();

        if ($grants && $decision->isLocked()) {
            return 'The platform subscription is granting access, but the account is blocked (' . $decision->reason . '). '
                . 'Check the plan assignment status and lifecycle below before changing anything.';
        }

        if (! $grants && ! $decision->isLocked() && $summary->isAssigned && ! $summary->isComplimentary) {
            return 'The platform subscription is not granting access (' . $subscription->status->value . '), '
                . 'but the account is still usable. The plan assignment, not the subscription, decides access.';
        }

        return null;
    }

    /**
     * @param array<int, int> $businessIds
     * @return array<int, array{active: int, archived: int}>
     */
    private function locationStats(array $businessIds): array
    {
        if ($businessIds === []) {
            return [];
        }

        $stats = [];

        BusinessLocation::query()
            ->toBase()
            ->selectRaw('business_id, lifecycle_state, COUNT(*) AS aggregate')
            ->whereIn('business_id', $businessIds)
            ->groupBy('business_id', 'lifecycle_state')
            ->get()
            ->each(function ($row) use (&$stats) {
                $bucket = $row->lifecycle_state === 'archived' ? 'archived' : 'active';
                $stats[(int) $row->business_id][$bucket] = (int) ($stats[(int) $row->business_id][$bucket] ?? 0) + (int) $row->aggregate;
            });

        foreach ($businessIds as $id) {
            $stats[$id] = ['active' => $stats[$id]['active'] ?? 0, 'archived' => $stats[$id]['archived'] ?? 0];
        }

        return $stats;
    }

    /**
     * @param array<int, int> $businessIds
     * @return Collection<int, BusinessPayerAssignment> keyed by business_id
     */
    private function payersFor(array $businessIds): Collection
    {
        if ($businessIds === []) {
            return collect();
        }

        return BusinessPayerAssignment::query()
            ->select(['id', 'business_id', 'payer_type', 'managing_agency_relationship_id', 'agency_rebill_consented_at'])
            ->whereIn('business_id', $businessIds)
            ->get()
            ->keyBy('business_id');
    }

    /**
     * Status only — balances and ledgers stay on the existing Usage Billing
     * admin page this surface links to.
     *
     * @param array<int, int> $businessIds
     * @return Collection<int, BusinessUsageWallet> keyed by business_id
     */
    private function walletsFor(array $businessIds): Collection
    {
        if ($businessIds === []) {
            return collect();
        }

        return BusinessUsageWallet::query()
            ->select(['id', 'business_id', 'billing_status', 'paid_activity_paused_at'])
            ->whereIn('business_id', $businessIds)
            ->get()
            ->keyBy('business_id');
    }

    /**
     * Read-only Agency facts, only where the data already exists on main.
     *
     * @return array<string, mixed>|null
     */
    private function agencyFor(Workspace $workspace): ?array
    {
        $relationship = $this->relationshipRepository->findActiveForClientWorkspace((int) $workspace->id);

        $managedClients = AgencyClientWorkspaceRelationship::query()
            ->where('agency_workspace_id', $workspace->id)
            ->where('status', AgencyClientRelationshipStatus::Active->value)
            ->count();

        // A TERMINATED relationship is history, never "managed". It is read
        // only to explain a Workspace that used to be Agency-managed, and is
        // reported separately from the active one so it can never be mistaken
        // for it (findActiveForClientWorkspace() already excludes it).
        $previous = null;

        if ($relationship === null) {
            $previous = $this->relationshipRepository->historyForClientWorkspace((int) $workspace->id)
                ->filter(fn ($row) => $row->status === AgencyClientRelationshipStatus::Terminated)
                ->last();
        }

        if ($relationship === null && $managedClients === 0 && $previous === null) {
            return null;
        }

        $detail = [
            'relationship' => $relationship,
            'previous' => $previous,
            'previousAgencyWorkspace' => $previous === null ? null : $this->workspaceRepository->findById((int) $previous->agency_workspace_id),
            'agencyWorkspace' => null,
            'subscription' => null,
            'managedClients' => $managedClients,
            'whiteLabel' => null,
        ];

        // White Label belongs to the AGENCY Workspace: the managing Agency for
        // a client, or this Workspace itself when it manages clients. Read
        // through the manager's own read-only find()/isEntitled(); nothing is
        // written and no branding value other than the enabled flag is shown.
        $brandingAgency = $relationship !== null
            ? $this->workspaceRepository->findById((int) $relationship->agency_workspace_id)
            : ($managedClients > 0 ? $workspace : null);

        if ($brandingAgency !== null) {
            $setting = $this->whiteLabel->find($brandingAgency);

            $detail['whiteLabel'] = [
                'agencyName' => $brandingAgency->name,
                'configured' => $setting !== null,
                'enabled' => (bool) ($setting?->is_enabled),
                'entitled' => $this->whiteLabel->isEntitled($brandingAgency),
            ];
        }

        if ($relationship !== null) {
            $detail['agencyWorkspace'] = $this->workspaceRepository->findById((int) $relationship->agency_workspace_id);
            $detail['subscription'] = AgencyClientSubscription::query()
                ->select(['id', 'uid', 'agency_workspace_id', 'client_workspace_id', 'agency_saas_plan_id', 'status', 'current_period_end', 'cancel_at_period_end', 'updated_at'])
                ->with('plan:id,name,tier')
                ->where('client_workspace_id', $workspace->id)
                ->orderByDesc('id')
                ->first();
        }

        return $detail;
    }

    /**
     * A human-readable actor label for audit rows, in one batched query.
     *
     * @param iterable<int, WorkspaceEntitlementTransition> $rows
     * @return array<int, string> user id => display label
     */
    public function actorLabels(iterable $rows): array
    {
        $ids = collect($rows)->pluck('actor_user_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->mapWithKeys(fn ($user) => [(int) $user->id => trim($user->displayName()) . ' <' . $user->email . '>'])
            ->all();
    }
}
