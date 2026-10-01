<?php

namespace App\Library\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Library\AgencyBilling\AgencyClientSubscriptionManager;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agency V1 completion — the Agency Clients list, read as ONE bounded page.
 *
 * THE AUTHORITY IS THE RELATIONSHIP, exactly as in AgencyClientsController and
 * AgencyClientPortfolio: every row comes from an ACTIVE
 * agency_client_workspace_relationships row keyed by THIS Agency Workspace's
 * id. The Agency id is a bound parameter of the very first statement, so
 * another Agency's clients cannot be enumerated by any search term, filter or
 * page number, and a terminated relationship disappears the moment it ends.
 *
 * BOUNDED. A page is at most MAX_PER_PAGE rows whatever the client count, and
 * the whole page costs a FIXED number of statements (the page, its count, and
 * one batched read each for Businesses, plan assignments and Agency
 * subscriptions) — never one query per client. Search and the Business-state
 * filter run in SQL, not over an in-memory collection of every client.
 *
 * NO SECOND LIFECYCLE AUTHORITY. The "Account" column is not re-derived here:
 * the already-loaded assignment facts are handed to
 * CustomerAccountAccessResolver::decideFromAssignmentFacts(), the one
 * Contract 03 truth table the rest of the product already uses. That is the
 * client's OWN lifecycle; composing it with the managing Agency is the
 * controller's gate (a locked/inactive/suspended Agency never reaches this
 * list at all), so nothing is hidden by reading it uncomposed.
 *
 * Read-only: nothing here writes, and nothing here is a payment or entitlement
 * decision — the plan and subscription columns are presentation of rows other
 * authorities own.
 */
final class AgencyClientListReader
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 50;

    /** Longest search term honoured; anything longer is truncated, not rejected. */
    public const MAX_SEARCH_LENGTH = 80;

    /** The Business-state filters the list offers ('' means all). */
    public const STATE_FILTERS = ['setup', 'active', 'inactive'];

    public function __construct(
        private readonly CustomerAccountAccessResolver $accessResolver,
        private readonly WorkspacePlanAssignmentRepository $assignments,
        private readonly AgencyClientSubscriptionManager $subscriptions,
    ) {
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(
        int $agencyWorkspaceId,
        string $search = '',
        string $state = '',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): LengthAwarePaginator {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);
        $search = mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH);
        $state = in_array($state, self::STATE_FILTERS, true) ? $state : '';

        $base = DB::table('agency_client_workspace_relationships as r')
            ->join('workspaces as w', 'w.id', '=', 'r.client_workspace_id')
            ->where('r.agency_workspace_id', $agencyWorkspaceId)
            ->where('r.status', AgencyClientRelationshipStatus::Active->value)
            // A self-link is impossible through Contract 01's manager; rejected
            // here as well so a legacy row can never list the Agency as its own
            // client.
            ->where('r.client_workspace_id', '<>', $agencyWorkspaceId);

        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';

            $base->where(function ($query) use ($like): void {
                $query->where('w.name', 'like', $like)
                    ->orWhereExists(function ($exists) use ($like): void {
                        $exists->select(DB::raw('1'))
                            ->from('businesses as sb')
                            ->whereColumn('sb.workspace_id', 'w.id')
                            ->where('sb.name', 'like', $like);
                    });
            });
        }

        if ($state !== '') {
            $base->whereExists(function ($exists) use ($state): void {
                $exists->select(DB::raw('1'))
                    ->from('businesses as fb')
                    ->whereColumn('fb.workspace_id', 'w.id');

                match ($state) {
                    'setup' => $exists->where('fb.status', BusinessStatus::Draft->value),
                    'active' => $exists->where('fb.status', BusinessStatus::Active->value),
                    default => $exists->whereNotIn('fb.status', [BusinessStatus::Draft->value, BusinessStatus::Active->value]),
                };
            });
        }

        $total = (clone $base)->count();

        $rows = $base
            ->orderBy('r.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get([
                'r.client_workspace_id',
                'r.established_at',
                'w.uid as workspace_uid',
                'w.name as workspace_name',
                'w.is_active as workspace_active',
            ]);

        $items = $this->present($agencyWorkspaceId, $rows->all());

        return new Paginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function present(int $agencyWorkspaceId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(fn (object $row): int => (int) $row->client_workspace_id, $rows);

        $businesses = [];

        foreach (DB::table('businesses')->whereIn('workspace_id', $ids)->orderBy('id')->get(['workspace_id', 'name', 'status']) as $business) {
            $businesses[(int) $business->workspace_id][] = $business;
        }

        // Each batched read goes through the seam that owns the table: the
        // entitlement repository for plan assignments, the lane-C manager for
        // the Agency's own subscriptions (scoped to THIS Agency's rows).
        $assignments = $this->assignments->lifecycleFactsForWorkspaces($ids);
        $subscriptions = $this->subscriptions->summariesForClients($agencyWorkspaceId, $ids);

        return array_map(function (object $row) use ($businesses, $assignments, $subscriptions): array {
            $workspaceId = (int) $row->client_workspace_id;
            $workspaceBusinesses = $businesses[$workspaceId] ?? [];
            $soleBusiness = count($workspaceBusinesses) === 1 ? $workspaceBusinesses[0] : null;
            $assignment = $assignments[$workspaceId] ?? null;
            $subscription = $subscriptions[$workspaceId] ?? null;

            return [
                'workspace_uid' => (string) $row->workspace_uid,
                'workspace_name' => (string) $row->workspace_name,
                'business_name' => $soleBusiness !== null ? (string) $soleBusiness->name : null,
                'business_status' => $soleBusiness !== null ? (string) $soleBusiness->status : null,
                'business_count' => count($workspaceBusinesses),
                'established_at' => $row->established_at !== null ? Carbon::parse($row->established_at) : null,
                'plan_name' => $assignment?->plan_display_name,
                'account' => $this->account($assignment),
                'agency_subscription_status' => $subscription['status'] ?? null,
                'agency_subscription_plan' => $subscription['plan'] ?? null,
            ];
        }, $rows);
    }

    /**
     * The client's own account state as plain words, from the ONE resolver
     * table. A client with no plan assignment yet reads "No plan" — the same
     * "not unusable, just not set up" the resolver itself answers.
     *
     * @return array{label: string, variant: string, reason: ?string}
     */
    private function account(?WorkspacePlanAssignment $assignment): array
    {
        if ($assignment === null) {
            return ['label' => 'No plan yet', 'variant' => 'neutral', 'reason' => null];
        }

        $decision = $this->accessResolver->decideFromAssignmentFacts(
            $assignment->status,
            $assignment->trial_ends_at,
            $assignment->grace_started_at,
            $assignment->locked_at,
        );

        return match (true) {
            $decision->state === CustomerAccountAccessState::LockedSuspended => ['label' => 'Suspended', 'variant' => 'danger', 'reason' => $decision->reason],
            $decision->state === CustomerAccountAccessState::LockedInactive => ['label' => 'Inactive', 'variant' => 'neutral', 'reason' => $decision->reason],
            $decision->isLocked() => ['label' => 'Locked', 'variant' => 'danger', 'reason' => $decision->reason],
            $decision->reason === 'plan_grace' => ['label' => 'Payment due', 'variant' => 'warning', 'reason' => $decision->reason],
            $decision->reason === 'plan_trial' => ['label' => 'Trial', 'variant' => 'accent', 'reason' => $decision->reason],
            default => ['label' => 'Active', 'variant' => 'success', 'reason' => $decision->reason],
        };
    }
}
