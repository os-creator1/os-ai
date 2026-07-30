<?php

namespace App\Library\Workspace;

use App\Enums\Workspace\WorkspaceContextFailureReason;
use App\Exceptions\Workspace\WorkspaceContextRequiredException;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Repositories\Contracts\CustomerRepository;
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
    ) {
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
