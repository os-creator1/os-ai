<?php

namespace App\Library\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceContextFailureReason;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Exceptions\Workspace\WorkspaceContextRequiredException;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Repositories\Contracts\CustomerRepository;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Narrow compatibility resolver (RFC-003 §13.1) for the one still-active
 * legacy Business-creation write path (§6 finding 8). Exists only to
 * supply an explicit Workspace to that path — it is never wired into any
 * generic repository method, never a general-purpose owner-to-Workspace
 * inference API, and never chooses a candidate arbitrarily when more than
 * one exists.
 */
class WorkspaceManager
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly CustomerOnboardingRepository $onboardingRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinessRepository,
    ) {
    }

    /**
     * RFC-003 §14.1's effective Business-access algorithm. Platform-
     * administrator access is deliberately not evaluated here — §14 states
     * it is "checked upstream of, and independently from, everything
     * below" by existing backend authorization, and RFC-003 "does not add
     * to, wrap, or duplicate this path."
     *
     * Re-reads Business and Workspace via their repositories rather than
     * trusting the caller's in-memory $business (and its relations) for
     * the load-bearing fields (workspace_id, customer_id, is_active,
     * owner_user_id) — a caller-held model can be stale relative to the
     * current persisted row. Performs no write, event dispatch, or
     * transition creation.
     */
    public function userCanAccessBusiness(int $userId, Business $business): bool
    {
        $currentBusiness = $this->businessRepository->findById($business->id);

        if ($currentBusiness === null || $currentBusiness->workspace_id === null) {
            // No Workspace to evaluate against — §14.1: "workspace is
            // null" only occurs pre-M1B; handled defensively, not assumed
            // impossible for every historical row.
            return false;
        }

        $workspace = $this->workspaceRepository->findById($currentBusiness->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            // An inactive (or missing) Workspace blocks ALL customer-side
            // access to this Business, including direct ownership —
            // evaluated before any ownership/membership check.
            return false;
        }

        if ((int) $currentBusiness->customer_id === $userId) {
            return true;
        }

        if ((int) $workspace->owner_user_id === $userId) {
            // The Workspace owner always has full access, never
            // scope-limited (§7.3) — independent of Business.customer_id.
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        if ($membership === null || ! $membership->is_active) {
            return false;
        }

        if ($membership->business_access_scope === WorkspaceBusinessAccessScope::All) {
            return true;
        }

        return $this->membershipBusinessRepository->isAssigned($membership, $currentBusiness->id);
    }

    /**
     * Delegates entirely to userCanAccessBusiness() — no second,
     * independent access algorithm. Performs no write.
     */
    public function assertUserCanAccessBusiness(int $userId, Business $business): void
    {
        if (! $this->userCanAccessBusiness($userId, $business)) {
            throw new WorkspaceAccessDeniedException($userId, $business->id);
        }
    }

    public function resolveLegacyOnboardingWorkspace(int $ownerUserId): Workspace
    {
        return DB::transaction(function () use ($ownerUserId) {
            $userRow = $this->lockOwnerRow($ownerUserId);

            if ($userRow === null) {
                throw (new ModelNotFoundException())->setModel(User::class, [$ownerUserId]);
            }

            $preferredIds = $this->collectPreferredCandidateIds($ownerUserId);

            if ($preferredIds->isNotEmpty()) {
                $workspaces = $this->verifyWorkspaceIds($ownerUserId, $preferredIds);

                if ($preferredIds->count() === 1) {
                    return $workspaces->first();
                }

                throw new WorkspaceContextRequiredException(
                    $ownerUserId,
                    $preferredIds->all(),
                    WorkspaceContextFailureReason::MultiplePreferredCandidates
                );
            }

            $fallbackIds = $this->collectFallbackCandidateIds($ownerUserId);

            if ($fallbackIds->isEmpty()) {
                return $this->createWorkspace($ownerUserId, $userRow);
            }

            $workspaces = $this->verifyWorkspaceIds($ownerUserId, $fallbackIds);

            if ($fallbackIds->count() === 1) {
                return $workspaces->first();
            }

            throw new WorkspaceContextRequiredException(
                $ownerUserId,
                $fallbackIds->all(),
                WorkspaceContextFailureReason::MultipleFallbackCandidates
            );
        });
    }

    /**
     * The stable users row is the serialization point (RFC-003 §13.1 step
     * 2, §18) — there is no Workspace row to lock when none exists yet.
     * Protected so a test can hold this lock open for a controlled
     * duration after acquiring it, to prove a second concurrent attempt
     * genuinely blocks rather than completing sequentially by coincidence.
     */
    protected function lockOwnerRow(int $ownerUserId): ?object
    {
        return DB::table('users')->where('id', $ownerUserId)->lockForUpdate()->first();
    }

    /**
     * Onboarding-linked and primary Businesses are equally preferred
     * sources (§13.1) — neither is silently prioritized over the other.
     * Both contribute to one flat, deduplicated ID set.
     */
    private function collectPreferredCandidateIds(int $ownerUserId): Collection
    {
        $ids = collect();

        $onboarding = $this->onboardingRepository->findByCustomerId($ownerUserId);

        if ($onboarding !== null && $onboarding->business_id !== null) {
            $onboardingBusiness = $this->businessRepository->findById($onboarding->business_id);

            if ($onboardingBusiness === null) {
                throw new WorkspaceContextRequiredException(
                    $ownerUserId,
                    [],
                    WorkspaceContextFailureReason::OnboardingBusinessReferenceInvalid
                );
            }

            if ((int) $onboardingBusiness->customer_id !== $ownerUserId) {
                throw new WorkspaceContextRequiredException(
                    $ownerUserId,
                    [],
                    WorkspaceContextFailureReason::OnboardingBusinessCustomerMismatch
                );
            }

            if ($onboardingBusiness->workspace_id !== null) {
                $ids->push((int) $onboardingBusiness->workspace_id);
            }
        }

        foreach ($this->businessRepository->primaryBusinessesForCustomer($ownerUserId) as $primaryBusiness) {
            if ($primaryBusiness->workspace_id !== null) {
                $ids->push((int) $primaryBusiness->workspace_id);
            }
        }

        return $ids->unique()->values();
    }

    /**
     * Business-linked Workspaces (source A) are valid candidates
     * regardless of Workspace ownership; directly-owned Workspaces
     * (source B) are candidates by ownership alone. No membership,
     * users.parent_id, plan, or unrelated-customer data is consulted.
     */
    private function collectFallbackCandidateIds(int $ownerUserId): Collection
    {
        $businessLinked = $this->businessRepository->workspaceIdsForCustomer($ownerUserId);

        $directlyOwned = $this->workspaceRepository->findOwnedBy($ownerUserId)
            ->map(fn (Workspace $workspace) => (int) $workspace->id);

        return $businessLinked->merge($directlyOwned)->unique()->values();
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function verifyWorkspaceIds(int $ownerUserId, Collection $ids): Collection
    {
        $missingIds = [];
        $workspaces = collect();

        foreach ($ids as $id) {
            $workspace = $this->workspaceRepository->findById($id);

            if ($workspace === null) {
                $missingIds[] = $id;

                continue;
            }

            $workspaces->push($workspace);
        }

        if ($missingIds !== []) {
            throw new WorkspaceContextRequiredException(
                $ownerUserId,
                $missingIds,
                WorkspaceContextFailureReason::DanglingWorkspaceReference
            );
        }

        return $workspaces;
    }

    private function createWorkspace(int $ownerUserId, object $userRow): Workspace
    {
        return $this->workspaceRepository->create([
            'owner_user_id' => $ownerUserId,
            'name' => $this->resolveWorkspaceName($ownerUserId, $userRow),
            'is_active' => true,
        ]);
    }

    /**
     * Deterministic name priority (RFC-003 §10.5-equivalent), first
     * non-blank value wins: customers.company, then the single primary
     * Business's name (only when exactly one exists — two or more skip
     * this tier rather than picking one arbitrarily), then the first
     * Business by id, then "{first_name} {last_name}'s Workspace" from
     * the already-locked users row, then a customer_id-based fallback
     * that can never itself be blank.
     */
    private function resolveWorkspaceName(int $ownerUserId, object $userRow): string
    {
        $company = $this->customerRepository->findByUserId($ownerUserId)?->company;

        if ($this->isNonBlank($company)) {
            return trim($company);
        }

        $primaryBusinesses = $this->businessRepository->primaryBusinessesForCustomer($ownerUserId);

        if ($primaryBusinesses->count() === 1) {
            $primaryName = $primaryBusinesses->first()->name;

            if ($this->isNonBlank($primaryName)) {
                return trim($primaryName);
            }
        }

        $firstBusiness = $this->businessRepository->findFirstByCustomer($ownerUserId);

        if ($firstBusiness !== null && $this->isNonBlank($firstBusiness->name)) {
            return trim($firstBusiness->name);
        }

        $fullName = trim(($userRow->first_name ?? '') . ' ' . ($userRow->last_name ?? ''));

        if ($this->isNonBlank($fullName)) {
            return "{$fullName}'s Workspace";
        }

        return "Customer #{$ownerUserId}'s Workspace";
    }

    private function isNonBlank(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
