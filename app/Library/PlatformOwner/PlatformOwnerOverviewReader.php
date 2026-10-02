<?php

namespace App\Library\PlatformOwner;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\Usage\ProviderEventState;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessMessagingProvisioningIncident;
use App\Models\PaymentProviderEvent;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Support\Collection;

/**
 * Platform Owner / Admin V1 — the bounded numbers behind the Platform Owner
 * overview, plus the batched plan/subscription lookup the Workspace list
 * needs.
 *
 * Every figure is a COUNT of canonical persisted state, taken with one
 * grouped aggregate query over an indexed column (businesses.status,
 * business_locations.lifecycle_state, platform_subscriptions.status, the
 * assignment repository's own aggregates). Nothing here loads a ledger, a
 * history table or a row-per-Workspace, there is no chart or history
 * warehouse, and none of it is cached — "now" is the point of this page, and
 * each query is cheap enough not to need it.
 *
 * "Recorded lifecycle" is exactly that: the timestamps the assignment rows
 * carry. It is NOT a re-implementation of the customer access decision (an
 * elapsed-but-unswept Grace window still counts as grace here). The decision
 * itself is only ever CustomerAccountAccessResolver's, on the detail pages.
 */
final class PlatformOwnerOverviewReader
{
    public const RECENT_BLOCKED_LIMIT = 10;

    public function __construct(
        private readonly WorkspacePlanAssignmentRepository $assignments,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $workspaceTotals = Workspace::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total, SUM(is_active = 1) AS active')
            ->first();

        $businessesByStatus = Business::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $locationsByState = BusinessLocation::query()
            ->toBase()
            ->selectRaw('lifecycle_state, COUNT(*) AS aggregate')
            ->groupBy('lifecycle_state')
            ->pluck('aggregate', 'lifecycle_state')
            ->map(fn ($count) => (int) $count)
            ->all();

        $subscriptionsByStatus = PlatformSubscription::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $grantingStatuses = collect(PlatformSubscriptionStatus::cases())
            ->filter(fn ($status) => $status->grantsAccess())
            ->map(fn ($status) => $status->value);

        $grantingSubscriptions = (int) $grantingStatuses->sum(fn ($status) => $subscriptionsByStatus[$status] ?? 0);

        return [
            'workspaces' => [
                'total' => (int) ($workspaceTotals->total ?? 0),
                'active' => (int) ($workspaceTotals->active ?? 0),
            ],
            'businessesByStatus' => $businessesByStatus,
            'businessesTotal' => array_sum($businessesByStatus),
            'locationsByState' => $locationsByState,
            'locationsTotal' => array_sum($locationsByState),
            'assignmentsByStatus' => $this->assignments->countByStatus(),
            'assignmentsByTier' => $this->assignments->countByTier(),
            'recordedLifecycle' => $this->assignments->countRecordedLifecycle(),
            'subscriptionsByStatus' => $subscriptionsByStatus,
            'grantingSubscriptions' => $grantingSubscriptions,
            'attention' => [
                'failedProviderEvents' => PaymentProviderEvent::query()->where('state', ProviderEventState::Failed->value)->count(),
                'unresolvedMessagingIncidents' => BusinessMessagingProvisioningIncident::query()->unresolved()->count(),
            ],
            'recentBlocked' => $this->recentBlocked(),
        ];
    }

    /**
     * Workspaces blocked by PERSISTED state (assignment not active, or a lock
     * timestamp), newest first, capped. One query for the assignments, one
     * for their Workspaces.
     *
     * @return array<int, array{workspace: Workspace, assignment: WorkspacePlanAssignment}>
     */
    private function recentBlocked(): array
    {
        $blocked = $this->assignments->recentBlocked(self::RECENT_BLOCKED_LIMIT);

        if ($blocked->isEmpty()) {
            return [];
        }

        $workspaces = Workspace::query()
            ->with('owner')
            ->whereIn('id', $blocked->pluck('workspace_id')->all())
            ->get()
            ->keyBy('id');

        return $blocked
            ->map(fn ($assignment) => $workspaces->has($assignment->workspace_id)
                ? ['workspace' => $workspaces->get($assignment->workspace_id), 'assignment' => $assignment]
                : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Plan + subscription for ONE page of Workspaces, in two queries total.
     *
     * @param Collection<int, Workspace>|iterable<int, Workspace> $workspaces
     * @return array{assignments: Collection, subscriptions: Collection}
     */
    public function forWorkspacePage(iterable $workspaces): array
    {
        $ids = collect($workspaces)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return ['assignments' => collect(), 'subscriptions' => collect()];
        }

        return [
            'assignments' => $this->assignments->findByWorkspaceIds($ids),
            'subscriptions' => PlatformSubscription::query()
                ->select(['id', 'workspace_id', 'status', 'provider_customer_id', 'provider_subscription_id', 'current_period_end', 'cancel_at_period_end'])
                ->whereIn('workspace_id', $ids)
                ->get()
                ->keyBy('workspace_id'),
        ];
    }
}
