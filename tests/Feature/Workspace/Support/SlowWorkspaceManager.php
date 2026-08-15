<?php

namespace Tests\Feature\Workspace\Support;

use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Repositories\Contracts\CustomerRepository;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use App\Repositories\Contracts\WorkspaceTransitionRepository;

/**
 * Test-only subclass that holds the users-row lock open for a controlled
 * duration after acquiring it (lockOwnerRow() is protected exactly for
 * this), used by the real cross-process concurrency test to prove a
 * second concurrent resolver attempt genuinely blocks rather than
 * completing sequentially by coincidence. Contains no alternate
 * resolution logic of its own.
 */
class SlowWorkspaceManager extends WorkspaceManager
{
    public function __construct(
        WorkspaceRepository $workspaceRepository,
        BusinessRepository $businessRepository,
        CustomerOnboardingRepository $onboardingRepository,
        CustomerRepository $customerRepository,
        WorkspaceMembershipRepository $membershipRepository,
        WorkspaceMembershipBusinessRepository $membershipBusinessRepository,
        WorkspaceTransitionRepository $transitionRepository,
        EntitlementManager $entitlementManager,
        private readonly float $holdSeconds,
    ) {
        parent::__construct(
            $workspaceRepository,
            $businessRepository,
            $onboardingRepository,
            $customerRepository,
            $membershipRepository,
            $membershipBusinessRepository,
            $transitionRepository,
            $entitlementManager,
        );
    }

    protected function lockOwnerRow(int $ownerUserId): ?object
    {
        $row = parent::lockOwnerRow($ownerUserId);

        if ($row !== null) {
            usleep((int) ($this->holdSeconds * 1_000_000));
        }

        return $row;
    }
}
