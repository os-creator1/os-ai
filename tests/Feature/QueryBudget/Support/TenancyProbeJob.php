<?php

namespace Tests\Feature\QueryBudget\Support;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Support\RequestScopedCache;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A real queued job that makes the tenancy and entitlement reads RequestScopedCache
 * memoizes, and records what it saw.
 *
 * It is pushed onto the real `database` queue and run by Laravel's real worker,
 * in the same process and container as the test — the exact condition of a
 * `queue:work` daemon, where one console Request outlives every job. It carries
 * ids only and re-reads, like every production job.
 *
 * `$observed` is static because the worker instantiates the job from its
 * serialized payload; the test reads what that instance recorded.
 */
final class TenancyProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** @var array<string, array{memoized_at_start: bool, allowed: bool, reason: string, can_access: bool, memoized_after_read: bool, membership_memoized_after_read: bool}> */
    public static array $observed = [];

    public function __construct(
        public readonly string $label,
        public readonly int $workspaceId,
        public readonly int $businessId,
        public readonly int $userId,
        public readonly int $actorId,
        public readonly bool $failAfterReading = false,
    ) {
    }

    public function handle(EntitlementManager $entitlements, WorkspaceManager $workspaces, RequestScopedCache $cache): void
    {
        $planKey = "workspace_plan_assignment:find:{$this->workspaceId}";
        $memoizedAtStart = $cache->has($planKey) || $cache->has("business:find:{$this->businessId}");

        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $business = Business::query()->findOrFail($this->businessId);

        $canAccess = $workspaces->userCanAccessBusiness($this->userId, $business);

        // Only populated on the scoped-member path (userCanAccessBusiness
        // short-circuits before this read for an owner or direct customer);
        // absent there is expected, not a bug.
        $membershipMemoizedAfterRead = $cache->has("membership:find:{$this->workspaceId}:{$this->userId}");

        try {
            $decision = $entitlements->decide($workspace, $business, PlatformFeature::Crm->value, $this->actorId);
            [$allowed, $reason] = [$decision->allowed, (string) $decision->reason];
        } catch (\App\Exceptions\Workspace\BusinessWorkspaceMismatchException|\App\Exceptions\Workspace\WorkspaceBusinessNotFoundException) {
            // The Business is no longer in this Workspace — a refusal, and one
            // that can only be reached by reading the CURRENT Business row.
            [$allowed, $reason] = [false, 'business_not_in_workspace'];
        }

        self::$observed[$this->label] = [
            'memoized_at_start' => $memoizedAtStart,
            'allowed' => $allowed,
            'reason' => $reason,
            'can_access' => $canAccess,
            'memoized_after_read' => $cache->has($planKey),
            'membership_memoized_after_read' => $membershipMemoizedAfterRead,
        ];

        if ($this->failAfterReading) {
            throw new \RuntimeException('Tenancy probe failed after its reads.');
        }
    }
}
