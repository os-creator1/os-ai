<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Entitlement\BusinessFeatureToggleChanged;
use App\Events\Entitlement\WorkspaceAdditionalBusinessSlotsChanged;
use App\Events\Entitlement\WorkspaceComplimentaryStatusChanged;
use App\Events\Entitlement\WorkspaceEntitlementOverrideChanged;
use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Events\Entitlement\WorkspacePlanChanged;
use App\Events\Entitlement\WorkspacePlanStatusChanged;
use App\Exceptions\Entitlement\BusinessSlotAllocationRequiredException;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\ComplimentaryWorkspaceCannotAllocatePaidSlotsException;
use App\Exceptions\Entitlement\InactiveWorkspacePlanException;
use App\Exceptions\Entitlement\InvalidAdditionalBusinessSlotsException;
use App\Exceptions\Entitlement\InvalidPaymentAllocationEvidenceException;
use App\Exceptions\Entitlement\PaymentAllocationIdempotencyConflictException;
use App\Exceptions\Entitlement\PaymentAllocationWorkspaceMismatchException;
use App\Exceptions\Entitlement\PlanCatalogPricingInUseException;
use App\Exceptions\Entitlement\SuspendedWorkspacePlanException;
use App\Exceptions\Entitlement\UnavailablePlatformFeatureOverrideException;
use App\Exceptions\Entitlement\UndefinedPlanPricingException;
use App\Exceptions\Entitlement\WorkspacePlanAlreadyAssignedException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Entitlement\Contracts\UsageAuthorizationGateway;
use App\Models\Business;
use App\Models\BusinessFeatureToggle;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementOverride;
use App\Models\WorkspacePlanAssignment;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\BusinessFeatureToggleRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\UserRepository;
use App\Repositories\Contracts\WorkspaceEntitlementOverrideRepository;
use App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use App\Repositories\Contracts\WorkspacePlanFeatureRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sole authority for RFC-004's structural entitlement decisions and
 * mutations (RFC-004 §20). No repository method outside this class's own
 * ten dependencies is ever queried directly for entitlement data by any
 * other class — this class and its repositories are the only authorized
 * readers/writers of the six RFC-004 tables (§15).
 *
 * One-directional dependency: WorkspaceManager/BusinessManager depend on
 * this class; this class never depends on either of them (§5).
 */
final class EntitlementManager
{
    private const LEGACY_COMPATIBILITY_REASON = 'Legacy onboarding Workspace — auto-provisioned complimentary Core assignment continuing Milestone 1\'s backfill posture for a brand-new Workspace created by the legacy resolver.';

    /**
     * RFC-004 Amendment 1 §7's fixed reason prefix, distinguishing
     * allocateAdditionalBusinessSlotsFromVerifiedPayment()'s own null-actor
     * transition rows from createLegacyOnboardingCompatibilityAssignment()'s
     * own fixed reason string.
     */
    private const PAYMENT_VERIFIED_ALLOCATION_REASON_PREFIX = 'payment_verified_allocation';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly UserRepository $userRepository,
        private readonly WorkspacePlanCatalogRepository $catalogRepository,
        private readonly WorkspacePlanFeatureRepository $planFeatureRepository,
        private readonly WorkspacePlanAssignmentRepository $assignmentRepository,
        private readonly WorkspaceEntitlementOverrideRepository $overrideRepository,
        private readonly BusinessFeatureToggleRepository $toggleRepository,
        private readonly WorkspaceEntitlementTransitionRepository $transitionRepository,
        private readonly UsageAuthorizationGateway $usageAuthorizationGateway,
    ) {
    }

    // =====================================================================
    // Read / decision (no lock — pure read)
    // =====================================================================

    /**
     * RFC-004 §14's exact 8-step precedence, with the raw string feature
     * key converted to a validated PlatformFeature first, and a defensive
     * Workspace/Business consistency check (steps 3-4) before any
     * entitlement-table read.
     */
    public function decide(Workspace $workspace, Business $business, string $featureKey, int $actorUserId): EntitlementDecision
    {
        $feature = PlatformFeature::tryFrom($featureKey);

        if ($feature === null) {
            return new EntitlementDecision(false, 'platform_feature_unknown');
        }

        if (! PlatformFeatureRegistry::isAvailable($feature->value)) {
            return new EntitlementDecision(false, 'platform_feature_unavailable');
        }

        $currentBusiness = $this->businessRepository->findById($business->id);

        if ($currentBusiness === null) {
            throw new WorkspaceBusinessNotFoundException($business->id);
        }

        if ((int) $currentBusiness->workspace_id !== (int) $workspace->id) {
            throw new BusinessWorkspaceMismatchException(
                $currentBusiness->id,
                (int) $workspace->id,
                (int) $currentBusiness->workspace_id,
            );
        }

        $assignment = $this->assignmentRepository->findByWorkspaceId((int) $workspace->id);

        if ($assignment === null) {
            return new EntitlementDecision(false, 'workspace_plan_unassigned');
        }

        $catalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
        $override = $this->overrideRepository->findByWorkspaceAndFeature((int) $workspace->id, $feature->value);

        if ($override !== null) {
            $workspaceEntitled = $override->state === WorkspaceEntitlementOverrideState::Allow;
            $denialReasonIfNot = 'denied_by_workspace_override';
        } else {
            $workspaceEntitled = $catalog !== null && $this->planFeatureRepository->includesFeature($catalog, $feature->value);
            $denialReasonIfNot = 'not_entitled_by_plan';
        }

        if (! $workspaceEntitled) {
            return new EntitlementDecision(false, $denialReasonIfNot);
        }

        if ($this->toggleRepository->findByBusinessAndFeature($currentBusiness->id, $feature->value) !== null) {
            return new EntitlementDecision(false, 'disabled_for_business');
        }

        if ($assignment->status === WorkspacePlanAssignmentStatus::Suspended) {
            return new EntitlementDecision(false, 'plan_suspended');
        }

        if ($assignment->status === WorkspacePlanAssignmentStatus::Inactive) {
            return new EntitlementDecision(false, 'plan_inactive');
        }

        $usageResult = $this->usageAuthorizationGateway->check($currentBusiness, $feature);

        if (! $usageResult->authorized) {
            return new EntitlementDecision(false, $usageResult->reason ?? 'usage_unauthorized');
        }

        return new EntitlementDecision(true, null);
    }

    /**
     * RFC-004 §17's exact algorithm, reproduced not redesigned.
     */
    public function decideBusinessSlotCapacity(Workspace $workspace): BusinessSlotCapacityDecision
    {
        $assignment = $this->assignmentRepository->findByWorkspaceId((int) $workspace->id);

        if ($assignment === null) {
            return new BusinessSlotCapacityDecision(0, 0, 0, null, false, false, 'workspace_plan_unassigned');
        }

        if ($assignment->status === WorkspacePlanAssignmentStatus::Suspended) {
            return new BusinessSlotCapacityDecision(0, 0, $assignment->additional_business_slots, null, false, false, 'plan_suspended');
        }

        if ($assignment->status === WorkspacePlanAssignmentStatus::Inactive) {
            return new BusinessSlotCapacityDecision(0, 0, $assignment->additional_business_slots, null, false, false, 'plan_inactive');
        }

        $catalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
        $currentCount = $this->businessRepository->countForWorkspace($workspace);
        $included = $catalog?->business_slot_included ?? 0;
        $additional = $assignment->additional_business_slots;

        if ($catalog !== null && $catalog->unlimited_business_slots) {
            return new BusinessSlotCapacityDecision($currentCount, $included, $additional, null, true, true, null);
        }

        $max = $catalog?->business_slot_max ?? ($included + $additional);
        $effectiveCapacity = min($included + $additional, $max);

        if ($currentCount < $effectiveCapacity) {
            return new BusinessSlotCapacityDecision($currentCount, $included, $additional, $effectiveCapacity, false, true, null);
        }

        if ($currentCount >= $max) {
            return new BusinessSlotCapacityDecision($currentCount, $included, $additional, $effectiveCapacity, false, false, 'business_slot_limit_exceeded');
        }

        return new BusinessSlotCapacityDecision($currentCount, $included, $additional, $effectiveCapacity, false, false, 'business_slot_allocation_required');
    }

    /**
     * Locks nothing itself — the caller already holds the Workspace lock
     * (§6) before calling this.
     */
    public function assertCanCreateAnotherBusiness(Workspace $workspace): void
    {
        $decision = $this->decideBusinessSlotCapacity($workspace);

        if ($decision->allowed) {
            return;
        }

        match ($decision->denialReason) {
            'workspace_plan_unassigned' => throw new WorkspacePlanUnassignedException((int) $workspace->id),
            'plan_inactive' => throw new InactiveWorkspacePlanException((int) $workspace->id),
            'plan_suspended' => throw new SuspendedWorkspacePlanException((int) $workspace->id),
            'business_slot_allocation_required' => throw new BusinessSlotAllocationRequiredException((int) $workspace->id),
            'business_slot_limit_exceeded' => throw new BusinessSlotLimitExceededException((int) $workspace->id),
            default => throw new RuntimeException("Unexpected capacity denial reason [{$decision->denialReason}] for Workspace [{$workspace->id}]."),
        };
    }

    // =====================================================================
    // Read / presentation (M3 §8 — no repository outside this class's own
    // dependencies is ever queried directly by a controller or view)
    // =====================================================================

    /**
     * @return array<int, WorkspacePlanCatalogSummary> exactly 3 entries, in Core/Growth/Agency order.
     */
    public function listPlanCatalogSummaries(): array
    {
        $summaries = [];

        foreach (WorkspacePlanTier::cases() as $tier) {
            $catalog = $this->catalogRepository->findByTier($tier);

            if ($catalog === null) {
                throw new RuntimeException("Workspace plan catalog tier [{$tier->value}] does not exist.");
            }

            $planFeatureKeys = $this->planFeatureRepository->featureKeysForCatalog($catalog)->all();
            $featureAvailability = [];

            foreach ($planFeatureKeys as $featureKey) {
                $featureAvailability[$featureKey] = PlatformFeatureRegistry::isAvailable($featureKey);
            }

            $summaries[] = new WorkspacePlanCatalogSummary(
                $catalog->id,
                $tier,
                $catalog->display_name,
                $catalog->price,
                $catalog->currency_id,
                $catalog->billing_cycle,
                $catalog->business_slot_included,
                $catalog->business_slot_max,
                $catalog->unlimited_business_slots,
                $catalog->additional_business_slot_price_ratio,
                $catalog->is_active,
                $planFeatureKeys,
                $featureAvailability,
            );
        }

        return $summaries;
    }

    public function getWorkspaceEntitlementSummary(Workspace $workspace): WorkspaceEntitlementSummary
    {
        $assignment = $this->assignmentRepository->findByWorkspaceId((int) $workspace->id);
        $capacity = $this->decideBusinessSlotCapacity($workspace);

        $overrides = [];

        foreach (PlatformFeature::cases() as $feature) {
            $override = $this->overrideRepository->findByWorkspaceAndFeature((int) $workspace->id, $feature->value);

            if ($override !== null) {
                $overrides[$feature->value] = $override->state;
            }
        }

        if ($assignment === null) {
            return new WorkspaceEntitlementSummary(false, null, null, null, null, [], $overrides, $capacity);
        }

        $catalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
        $planFeatureKeys = $catalog !== null ? $this->planFeatureRepository->featureKeysForCatalog($catalog)->all() : [];

        return new WorkspaceEntitlementSummary(
            true,
            $catalog?->tier,
            $catalog?->display_name,
            $assignment->status,
            $assignment->is_complimentary,
            $planFeatureKeys,
            $overrides,
            $capacity,
        );
    }

    /**
     * @return array<string, array{decision: EntitlementDecision, disablePreferenceRecorded: bool}>
     *         keyed by PlatformFeature value; empty array if the Business is no
     *         longer authoritatively part of $workspace as of this read.
     */
    public function decideAvailableFeaturesForBusiness(Workspace $workspace, Business $business, int $actorUserId): array
    {
        $decisions = [];

        foreach (PlatformFeature::cases() as $feature) {
            if (! PlatformFeatureRegistry::isAvailable($feature->value)) {
                continue;
            }

            try {
                $decision = $this->decide($workspace, $business, $feature->value, $actorUserId);
            } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
                return [];
            }

            $toggle = $this->toggleRepository->findByBusinessAndFeature($business->id, $feature->value);

            $decisions[$feature->value] = [
                'decision' => $decision,
                'disablePreferenceRecorded' => $toggle !== null,
            ];
        }

        return $decisions;
    }

    // =====================================================================
    // Plan assignment
    // =====================================================================

    public function assignFirstPlan(
        Workspace $workspace,
        WorkspacePlanTier $tier,
        int $actorUserId,
        string $reason,
        bool $isComplimentary = false,
        int $additionalBusinessSlots = 0,
    ): WorkspacePlanAssignment {
        return DB::transaction(function () use ($workspace, $tier, $actorUserId, $reason, $isComplimentary, $additionalBusinessSlots) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertPlatformAdministrator($actorUserId);

            if ($this->assignmentRepository->findByWorkspaceId($lockedWorkspace->id) !== null) {
                throw new WorkspacePlanAlreadyAssignedException($lockedWorkspace->id);
            }

            $catalog = $this->catalogRepository->findByTier($tier);

            if ($catalog === null) {
                throw new RuntimeException("Workspace plan catalog tier [{$tier->value}] does not exist.");
            }

            $this->assertCatalogActive($catalog, $tier);

            if (! $isComplimentary) {
                $catalog = $this->catalogRepository->findForUpdate($catalog->id);
                $this->assertCatalogActive($catalog, $tier);
                $this->assertBasePricingDefined($catalog);

                if ($additionalBusinessSlots > 0) {
                    $this->assertSlotRatioDefined($catalog);
                }
            }

            $this->assertValidAdditionalBusinessSlots($tier, $additionalBusinessSlots);

            if (trim($reason) === '') {
                throw new InvalidArgumentException('A non-empty reason is required to assign a first plan.');
            }

            $now = now();

            $assignment = $this->assignmentRepository->create([
                'workspace_id' => $lockedWorkspace->id,
                'workspace_plan_catalog_id' => $catalog->id,
                'status' => WorkspacePlanAssignmentStatus::Active,
                'is_complimentary' => $isComplimentary,
                'complimentary_reason' => $isComplimentary ? $reason : null,
                'complimentary_granted_by_user_id' => $isComplimentary ? $actorUserId : null,
                'complimentary_granted_at' => $isComplimentary ? $now : null,
                'additional_business_slots' => $additionalBusinessSlots,
            ]);

            $this->transitionRepository->create([
                'workspace_id' => $lockedWorkspace->id,
                'transition_type' => WorkspaceEntitlementTransitionType::PlanAssigned,
                'actor_user_id' => $actorUserId,
                'to_plan_catalog_id' => $catalog->id,
                'reason' => $reason,
            ]);

            WorkspacePlanAssigned::dispatch($lockedWorkspace->id, $catalog->id, $actorUserId);

            return $assignment;
        });
    }

    /**
     * Narrow, system-provenance-only path — reachable only from
     * WorkspaceManager::provisionWorkspaceRecord()'s own legacy-provisioning
     * integration (§13.D). Never a general admin-bypass precedent.
     */
    public function createLegacyOnboardingCompatibilityAssignment(Workspace $workspace): WorkspacePlanAssignment
    {
        return DB::transaction(function () use ($workspace) {
            $catalog = $this->catalogRepository->findByTier(WorkspacePlanTier::Core);

            if ($catalog === null) {
                throw new RuntimeException('Workspace plan catalog tier [core] does not exist.');
            }

            $this->assertCatalogActive($catalog, WorkspacePlanTier::Core);

            $now = now();
            $reason = self::LEGACY_COMPATIBILITY_REASON;

            $assignment = $this->assignmentRepository->create([
                'workspace_id' => $workspace->id,
                'workspace_plan_catalog_id' => $catalog->id,
                'status' => WorkspacePlanAssignmentStatus::Active,
                'is_complimentary' => true,
                'complimentary_reason' => $reason,
                'complimentary_granted_by_user_id' => null,
                'complimentary_granted_at' => $now,
                'additional_business_slots' => 0,
            ]);

            $this->transitionRepository->create([
                'workspace_id' => $workspace->id,
                'transition_type' => WorkspaceEntitlementTransitionType::PlanAssigned,
                'actor_user_id' => null,
                'to_plan_catalog_id' => $catalog->id,
                'reason' => $reason,
            ]);

            WorkspacePlanAssigned::dispatch($workspace->id, $catalog->id, null);

            return $assignment;
        });
    }

    public function changePlan(
        Workspace $workspace,
        WorkspacePlanTier $newTier,
        int $actorUserId,
        ?string $reason = null,
        ?int $additionalBusinessSlots = null,
    ): WorkspacePlanAssignment {
        return DB::transaction(function () use ($workspace, $newTier, $actorUserId, $reason, $additionalBusinessSlots) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertPlatformAdministrator($actorUserId);

            $assignment = $this->assignmentRepository->findByWorkspaceId($lockedWorkspace->id);

            if ($assignment === null) {
                throw new WorkspacePlanUnassignedException($lockedWorkspace->id);
            }

            $currentCatalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
            $currentTier = $currentCatalog?->tier;

            $destinationCatalog = $this->catalogRepository->findByTier($newTier);

            if ($destinationCatalog === null) {
                throw new RuntimeException("Workspace plan catalog tier [{$newTier->value}] does not exist.");
            }

            $this->assertCatalogActive($destinationCatalog, $newTier);

            if (! $assignment->is_complimentary) {
                $destinationCatalog = $this->catalogRepository->findForUpdate($destinationCatalog->id);
                $this->assertCatalogActive($destinationCatalog, $newTier);
                $this->assertBasePricingDefined($destinationCatalog);
            }

            $fromSlots = $assignment->additional_business_slots;
            $toSlots = $this->normalizeSlotsForDirection($currentTier, $newTier, $fromSlots, $additionalBusinessSlots);

            $this->assertValidAdditionalBusinessSlots($newTier, $toSlots);

            if (! $assignment->is_complimentary && $toSlots > $fromSlots) {
                $this->assertSlotRatioDefined($destinationCatalog);
            }

            $planChanged = $currentCatalog === null || (int) $currentCatalog->id !== (int) $destinationCatalog->id;
            $slotsChanged = $toSlots !== $fromSlots;

            $updated = $this->assignmentRepository->update($assignment, [
                'workspace_plan_catalog_id' => $destinationCatalog->id,
                'additional_business_slots' => $toSlots,
            ]);

            if ($planChanged) {
                $this->transitionRepository->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'transition_type' => WorkspaceEntitlementTransitionType::PlanChanged,
                    'actor_user_id' => $actorUserId,
                    'from_plan_catalog_id' => $currentCatalog?->id,
                    'to_plan_catalog_id' => $destinationCatalog->id,
                    'reason' => $reason,
                ]);
            }

            if ($slotsChanged) {
                $this->transitionRepository->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'transition_type' => WorkspaceEntitlementTransitionType::AdditionalBusinessSlotsChanged,
                    'actor_user_id' => $actorUserId,
                    'from_additional_business_slots' => $fromSlots,
                    'to_additional_business_slots' => $toSlots,
                    'reason' => $reason,
                ]);
            }

            if ($planChanged) {
                WorkspacePlanChanged::dispatch($lockedWorkspace->id, (int) ($currentCatalog?->id ?? 0), $destinationCatalog->id, $actorUserId);
            }

            if ($slotsChanged) {
                WorkspaceAdditionalBusinessSlotsChanged::dispatch($lockedWorkspace->id, $fromSlots, $toSlots, $actorUserId);
            }

            return $updated;
        });
    }

    public function changePlanStatus(Workspace $workspace, WorkspacePlanAssignmentStatus $status, int $actorUserId, string $reason): WorkspacePlanAssignment
    {
        return DB::transaction(function () use ($workspace, $status, $actorUserId, $reason) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertPlatformAdministrator($actorUserId);

            if (trim($reason) === '') {
                throw new InvalidArgumentException('A non-empty reason is required to change plan status.');
            }

            $assignment = $this->assignmentRepository->findByWorkspaceId($lockedWorkspace->id);

            if ($assignment === null) {
                throw new WorkspacePlanUnassignedException($lockedWorkspace->id);
            }

            if ($assignment->status === $status) {
                return $assignment;
            }

            $fromStatus = $assignment->status;
            $updated = $this->assignmentRepository->update($assignment, ['status' => $status]);

            $this->transitionRepository->create([
                'workspace_id' => $lockedWorkspace->id,
                'transition_type' => WorkspaceEntitlementTransitionType::PlanStatusChanged,
                'actor_user_id' => $actorUserId,
                'from_status' => $fromStatus,
                'to_status' => $status,
                'reason' => $reason,
            ]);

            WorkspacePlanStatusChanged::dispatch($lockedWorkspace->id, $fromStatus->value, $status->value, $actorUserId);

            return $updated;
        });
    }

    // =====================================================================
    // Complimentary status
    // =====================================================================

    public function grantComplimentaryStatus(Workspace $workspace, int $actorUserId, string $reason): WorkspacePlanAssignment
    {
        return DB::transaction(function () use ($workspace, $actorUserId, $reason) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertPlatformAdministrator($actorUserId);

            if (trim($reason) === '') {
                throw new InvalidArgumentException('A non-empty reason is required to grant complimentary status.');
            }

            $assignment = $this->assignmentRepository->findByWorkspaceId($lockedWorkspace->id);

            if ($assignment === null) {
                throw new WorkspacePlanUnassignedException($lockedWorkspace->id);
            }

            if ($assignment->is_complimentary) {
                return $assignment;
            }

            $updated = $this->assignmentRepository->update($assignment, [
                'is_complimentary' => true,
                'complimentary_reason' => $reason,
                'complimentary_granted_by_user_id' => $actorUserId,
                'complimentary_granted_at' => now(),
            ]);

            $this->transitionRepository->create([
                'workspace_id' => $lockedWorkspace->id,
                'transition_type' => WorkspaceEntitlementTransitionType::ComplimentaryGranted,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            WorkspaceComplimentaryStatusChanged::dispatch($lockedWorkspace->id, true, $actorUserId);

            return $updated;
        });
    }

    public function revokeComplimentaryStatus(Workspace $workspace, int $actorUserId, ?string $reason = null): WorkspacePlanAssignment
    {
        return DB::transaction(function () use ($workspace, $actorUserId, $reason) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertPlatformAdministrator($actorUserId);

            $assignment = $this->assignmentRepository->findByWorkspaceId($lockedWorkspace->id);

            if ($assignment === null) {
                throw new WorkspacePlanUnassignedException($lockedWorkspace->id);
            }

            if (! $assignment->is_complimentary) {
                return $assignment;
            }

            $catalog = $this->catalogRepository->findForUpdate($assignment->workspace_plan_catalog_id);

            if ($catalog === null) {
                throw new RuntimeException("Workspace plan catalog [{$assignment->workspace_plan_catalog_id}] does not exist.");
            }

            $this->assertBasePricingDefined($catalog);

            if ($assignment->additional_business_slots > 0) {
                $this->assertSlotRatioDefined($catalog);
            }

            $updated = $this->assignmentRepository->update($assignment, ['is_complimentary' => false]);

            $this->transitionRepository->create([
                'workspace_id' => $lockedWorkspace->id,
                'transition_type' => WorkspaceEntitlementTransitionType::ComplimentaryRevoked,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            WorkspaceComplimentaryStatusChanged::dispatch($lockedWorkspace->id, false, $actorUserId);

            return $updated;
        });
    }

    // =====================================================================
    // Additional Business slots
    // =====================================================================

    public function setAdditionalBusinessSlots(Workspace $workspace, int $count, int $actorUserId, ?string $reason = null): WorkspacePlanAssignment
    {
        return DB::transaction(function () use ($workspace, $count, $actorUserId, $reason) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertPlatformAdministrator($actorUserId);

            $assignment = $this->assignmentRepository->findByWorkspaceId($lockedWorkspace->id);

            if ($assignment === null) {
                throw new WorkspacePlanUnassignedException($lockedWorkspace->id);
            }

            $catalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
            $tier = $catalog?->tier ?? WorkspacePlanTier::Core;

            $this->assertValidAdditionalBusinessSlots($tier, $count);

            $fromCount = $assignment->additional_business_slots;

            if ($count > $fromCount && ! $assignment->is_complimentary) {
                $lockedCatalog = $this->catalogRepository->findForUpdate($assignment->workspace_plan_catalog_id);
                $this->assertBasePricingDefined($lockedCatalog);
                $this->assertSlotRatioDefined($lockedCatalog);
            }

            if ($count === $fromCount) {
                return $assignment;
            }

            $updated = $this->assignmentRepository->update($assignment, ['additional_business_slots' => $count]);

            $this->transitionRepository->create([
                'workspace_id' => $lockedWorkspace->id,
                'transition_type' => WorkspaceEntitlementTransitionType::AdditionalBusinessSlotsChanged,
                'actor_user_id' => $actorUserId,
                'from_additional_business_slots' => $fromCount,
                'to_additional_business_slots' => $count,
                'reason' => $reason,
            ]);

            WorkspaceAdditionalBusinessSlotsChanged::dispatch($lockedWorkspace->id, $fromCount, $count, $actorUserId);

            return $updated;
        });
    }

    /**
     * RFC-004 Amendment 1 §5 — narrow, payment-proof-provenance-only path —
     * reachable only from a future RFC-005 Milestone 4 checkout/webhook-
     * completion manager, after that caller has already independently
     * verified a durable, successful, idempotent payment record for exactly
     * this allocation (§7). This method itself performs no provider call,
     * makes no payment-confirmation decision, and trusts the caller's prior
     * verification exactly as UsageWalletManager::creditFromFunding() trusts
     * UsageBillingCheckoutManager's own prior verification (RFC-005 §22/M3
     * precedent) — its own responsibility is confined to: re-asserting the
     * proof's own recorded Workspace matches the target Workspace (§7),
     * enforcing this method's own independent idempotency guarantee (§8)
     * under the same Workspace row lock every other EntitlementManager
     * mutator uses (§9), applying the exact same tier/pricing validation
     * setAdditionalBusinessSlots() already applies (§9), and writing the
     * exact same durable audit trail with two additional, purely additive
     * columns (§6). Never a general admin-bypass precedent. Never callable
     * from customer-facing input.
     */
    public function allocateAdditionalBusinessSlotsFromVerifiedPayment(
        Workspace $workspace,
        int $additionalSlotsToAdd,
        int $requestingCustomerUserId,
        int $paymentVerifiedForWorkspaceId,
        string $paymentIdempotencyKey,
        string $paymentProviderReference,
        ?string $reason = null,
    ): WorkspacePlanAssignment {
        return DB::transaction(function () use (
            $workspace,
            $additionalSlotsToAdd,
            $requestingCustomerUserId,
            $paymentVerifiedForWorkspaceId,
            $paymentIdempotencyKey,
            $paymentProviderReference,
            $reason,
        ) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            if ($paymentVerifiedForWorkspaceId !== $lockedWorkspace->id) {
                throw new PaymentAllocationWorkspaceMismatchException($lockedWorkspace->id, $paymentVerifiedForWorkspaceId);
            }

            if ($paymentIdempotencyKey === '' || $paymentProviderReference === '' || $additionalSlotsToAdd <= 0) {
                throw new InvalidPaymentAllocationEvidenceException($lockedWorkspace->id);
            }

            $assignment = $this->assignmentRepository->findByWorkspaceId($lockedWorkspace->id);

            if ($assignment === null) {
                throw new WorkspacePlanUnassignedException($lockedWorkspace->id);
            }

            $fromCount = $assignment->additional_business_slots;
            $toCount = $fromCount + $additionalSlotsToAdd;

            $existingTransition = $this->transitionRepository->findByPaymentIdempotencyKey($paymentIdempotencyKey);

            if ($existingTransition !== null) {
                $isExactReplay = (int) $existingTransition->workspace_id === $lockedWorkspace->id
                    && (int) $existingTransition->requesting_customer_user_id === $requestingCustomerUserId
                    && (int) $existingTransition->to_additional_business_slots === $toCount;

                if (! $isExactReplay) {
                    throw new PaymentAllocationIdempotencyConflictException($paymentIdempotencyKey);
                }

                return $assignment;
            }

            if ($assignment->is_complimentary) {
                throw new ComplimentaryWorkspaceCannotAllocatePaidSlotsException($lockedWorkspace->id);
            }

            $catalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
            $tier = $catalog?->tier ?? WorkspacePlanTier::Core;

            $this->assertValidAdditionalBusinessSlots($tier, $toCount);

            $lockedCatalog = $this->catalogRepository->findForUpdate($assignment->workspace_plan_catalog_id);
            $this->assertBasePricingDefined($lockedCatalog);
            $this->assertSlotRatioDefined($lockedCatalog);

            $updated = $this->assignmentRepository->update($assignment, ['additional_business_slots' => $toCount]);

            $this->transitionRepository->create([
                'workspace_id' => $lockedWorkspace->id,
                'transition_type' => WorkspaceEntitlementTransitionType::AdditionalBusinessSlotsChanged,
                'actor_user_id' => null,
                'requesting_customer_user_id' => $requestingCustomerUserId,
                'from_additional_business_slots' => $fromCount,
                'to_additional_business_slots' => $toCount,
                'reason' => self::PAYMENT_VERIFIED_ALLOCATION_REASON_PREFIX . ": provider_reference={$paymentProviderReference}" . ($reason !== null ? " {$reason}" : ''),
                'payment_idempotency_key' => $paymentIdempotencyKey,
            ]);

            WorkspaceAdditionalBusinessSlotsChanged::dispatch($lockedWorkspace->id, $fromCount, $toCount, null);

            return $updated;
        });
    }

    // =====================================================================
    // Workspace overrides
    // =====================================================================

    public function createOrChangeOverride(
        Workspace $workspace,
        PlatformFeature $feature,
        WorkspaceEntitlementOverrideState $state,
        int $actorUserId,
        string $reason,
    ): WorkspaceEntitlementOverride {
        return DB::transaction(function () use ($workspace, $feature, $state, $actorUserId, $reason) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertPlatformAdministrator($actorUserId);

            if (trim($reason) === '') {
                throw new InvalidArgumentException('A non-empty reason is required to create or change a Workspace entitlement override.');
            }

            if ($state === WorkspaceEntitlementOverrideState::Allow && ! PlatformFeatureRegistry::isAvailable($feature->value)) {
                throw new UnavailablePlatformFeatureOverrideException($feature->value);
            }

            $existing = $this->overrideRepository->findByWorkspaceAndFeature($lockedWorkspace->id, $feature->value);

            if ($existing === null) {
                $override = $this->overrideRepository->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'feature_key' => $feature->value,
                    'state' => $state,
                    'reason' => $reason,
                    'created_by_user_id' => $actorUserId,
                ]);

                $this->writeOverrideTransition($lockedWorkspace->id, $feature->value, null, $state, $actorUserId, $reason);
                WorkspaceEntitlementOverrideChanged::dispatch($lockedWorkspace->id, $feature->value, null, $state->value, $actorUserId);

                return $override;
            }

            $fromState = $existing->state;

            if ($fromState === $state) {
                return $existing;
            }

            $updated = $this->overrideRepository->update($existing, $state);

            $this->writeOverrideTransition($lockedWorkspace->id, $feature->value, $fromState, $state, $actorUserId, $reason);
            WorkspaceEntitlementOverrideChanged::dispatch($lockedWorkspace->id, $feature->value, $fromState->value, $state->value, $actorUserId);

            return $updated;
        });
    }

    public function revertOverride(Workspace $workspace, PlatformFeature $feature, int $actorUserId): void
    {
        DB::transaction(function () use ($workspace, $feature, $actorUserId) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertPlatformAdministrator($actorUserId);

            $existing = $this->overrideRepository->findByWorkspaceAndFeature($lockedWorkspace->id, $feature->value);

            if ($existing === null) {
                return;
            }

            $fromState = $existing->state;

            $this->overrideRepository->delete($existing);

            $this->transitionRepository->create([
                'workspace_id' => $lockedWorkspace->id,
                'transition_type' => WorkspaceEntitlementTransitionType::EntitlementOverrideReverted,
                'actor_user_id' => $actorUserId,
                'feature_key' => $feature->value,
                'from_override_state' => $fromState,
                'to_override_state' => null,
                'reason' => null,
            ]);

            WorkspaceEntitlementOverrideChanged::dispatch($lockedWorkspace->id, $feature->value, $fromState->value, null, $actorUserId);
        });
    }

    private function writeOverrideTransition(
        int $workspaceId,
        string $featureKey,
        ?WorkspaceEntitlementOverrideState $fromState,
        WorkspaceEntitlementOverrideState $toState,
        int $actorUserId,
        string $reason,
    ): void {
        $transitionType = $toState === WorkspaceEntitlementOverrideState::Allow
            ? WorkspaceEntitlementTransitionType::EntitlementOverrideAllowed
            : WorkspaceEntitlementTransitionType::EntitlementOverrideDenied;

        $this->transitionRepository->create([
            'workspace_id' => $workspaceId,
            'transition_type' => $transitionType,
            'actor_user_id' => $actorUserId,
            'feature_key' => $featureKey,
            'from_override_state' => $fromState,
            'to_override_state' => $toState,
            'reason' => $reason,
        ]);
    }

    // =====================================================================
    // Business feature toggles
    // =====================================================================

    public function disableBusinessFeature(Business $business, PlatformFeature $feature, int $actorUserId, ?string $reason = null): BusinessFeatureToggle
    {
        return DB::transaction(function () use ($business, $feature, $actorUserId, $reason) {
            [$lockedWorkspace, $lockedBusiness] = $this->lockWorkspaceAndBusinessForToggle($business);

            $this->assertWorkspaceOwnerOrActiveAdmin($actorUserId, $lockedWorkspace);

            $decision = $this->decide($lockedWorkspace, $lockedBusiness, $feature->value, $actorUserId);

            if (! $decision->allowed) {
                throw new RuntimeException("Business [{$lockedBusiness->id}] is not currently entitled to feature [{$feature->value}]; nothing to disable.");
            }

            $existing = $this->toggleRepository->findByBusinessAndFeature($lockedBusiness->id, $feature->value);

            if ($existing !== null) {
                return $existing;
            }

            $toggle = $this->toggleRepository->create([
                'business_id' => $lockedBusiness->id,
                'feature_key' => $feature->value,
                'reason' => $reason,
                'created_by_user_id' => $actorUserId,
            ]);

            BusinessFeatureToggleChanged::dispatch($lockedBusiness->id, $lockedWorkspace->id, $feature->value, true, $actorUserId);

            return $toggle;
        });
    }

    public function enableBusinessFeature(Business $business, PlatformFeature $feature, int $actorUserId): void
    {
        DB::transaction(function () use ($business, $feature, $actorUserId) {
            [$lockedWorkspace, $lockedBusiness] = $this->lockWorkspaceAndBusinessForToggle($business);

            $this->assertWorkspaceOwnerOrActiveAdmin($actorUserId, $lockedWorkspace);

            $existing = $this->toggleRepository->findByBusinessAndFeature($lockedBusiness->id, $feature->value);

            if ($existing === null) {
                return;
            }

            $this->toggleRepository->delete($existing);

            BusinessFeatureToggleChanged::dispatch($lockedBusiness->id, $lockedWorkspace->id, $feature->value, false, $actorUserId);
        });
    }

    /**
     * @return array{0: Workspace, 1: Business}
     */
    private function lockWorkspaceAndBusinessForToggle(Business $business): array
    {
        $expectedWorkspaceId = (int) $business->workspace_id;

        $lockedWorkspace = $this->workspaceRepository->findForUpdate($expectedWorkspaceId);

        if ($lockedWorkspace === null) {
            throw new WorkspaceNotFoundException($expectedWorkspaceId);
        }

        $lockedBusiness = $this->businessRepository->findForUpdate($business->id);

        if ($lockedBusiness === null) {
            throw new WorkspaceBusinessNotFoundException($business->id);
        }

        if ((int) $lockedBusiness->workspace_id !== $expectedWorkspaceId) {
            throw new BusinessWorkspaceMismatchException(
                $lockedBusiness->id,
                $expectedWorkspaceId,
                (int) $lockedBusiness->workspace_id,
            );
        }

        if (! $lockedWorkspace->is_active) {
            throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
        }

        return [$lockedWorkspace, $lockedBusiness];
    }

    // =====================================================================
    // Catalog pricing mutation guard
    // =====================================================================

    /**
     * The entire authority-check-through-update sequence is one real
     * transaction: findForUpdate()'s SELECT ... FOR UPDATE only holds its
     * row lock across subsequent statements while an ambient transaction is
     * open — executed outside one, MySQL's autocommit mode releases the
     * lock the instant that single SELECT completes, before the
     * non-complimentary-reference check or the update ever runs.
     */
    public function updateCatalogPricing(WorkspacePlanCatalog $catalog, ?string $price, ?int $currencyId, int $actorUserId): WorkspacePlanCatalog
    {
        return DB::transaction(function () use ($catalog, $price, $currencyId, $actorUserId) {
            $this->assertPlatformAdministrator($actorUserId);

            $lockedCatalog = $this->catalogRepository->findForUpdate($catalog->id);

            if ($lockedCatalog === null) {
                throw new RuntimeException("Workspace plan catalog [{$catalog->id}] does not exist.");
            }

            $normalizedPrice = $price === null ? null : $this->normalizePrice($price);

            if (($normalizedPrice === null) !== ($currencyId === null)) {
                throw new InvalidArgumentException('Workspace plan catalog price and currency_id must both be null or both be populated.');
            }

            if ($normalizedPrice === null && $this->assignmentRepository->hasNonComplimentaryForCatalogForUpdate($lockedCatalog->id)) {
                throw new PlanCatalogPricingInUseException($lockedCatalog->id);
            }

            return $this->catalogRepository->update($lockedCatalog, [
                'price' => $normalizedPrice,
                'currency_id' => $currencyId,
            ]);
        });
    }

    /**
     * Exact DECIMAL(16,2) decimal-string validation/normalization (§13.L)
     * — never casts through a PHP float anywhere in this path.
     */
    private function normalizePrice(string $price): string
    {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $price)) {
            throw new InvalidArgumentException("Workspace plan catalog price [{$price}] is not a valid non-negative decimal string.");
        }

        $parts = explode('.', $price, 2);
        $integerPart = ltrim($parts[0], '0');
        $integerPart = $integerPart === '' ? '0' : $integerPart;

        if (strlen($integerPart) > 14) {
            throw new InvalidArgumentException("Workspace plan catalog price [{$price}] exceeds the maximum precision of DECIMAL(16,2).");
        }

        $fractionalPart = $parts[1] ?? '';
        $fractionalPart = match (strlen($fractionalPart)) {
            0 => '00',
            1 => $fractionalPart . '0',
            default => $fractionalPart,
        };

        return "{$integerPart}.{$fractionalPart}";
    }

    // =====================================================================
    // Shared authority/validation helpers
    // =====================================================================

    /**
     * Platform-administrator authority (§13.N) — checked via the existing
     * users.is_admin flag, matching EnsureUserIsAdministrator's own
     * established administrator truth exactly. Never a new or parallel
     * authorization mechanism.
     */
    private function assertPlatformAdministrator(int $actorUserId): void
    {
        $isAdmin = (bool) $this->userRepository->query()->whereKey($actorUserId)->value('is_admin');

        if (! $isAdmin) {
            throw new AuthorizationException('This action is restricted to platform administrators.');
        }
    }

    /**
     * Workspace owner or active Workspace Admin authority (§13.N),
     * independently reproducing WorkspaceManager's own
     * assertActorIsOwnerOrActiveAdmin()-equivalent check — EntitlementManager
     * never calls into WorkspaceManager (§5).
     */
    private function assertWorkspaceOwnerOrActiveAdmin(int $actorUserId, Workspace $workspace): void
    {
        if ((int) $workspace->owner_user_id === $actorUserId) {
            return;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $actorUserId);

        if ($membership !== null && $membership->is_active && $membership->role === WorkspaceMembershipRole::Admin) {
            return;
        }

        throw new UnauthorizedWorkspaceManagementException($actorUserId, $workspace->id);
    }

    private function assertCatalogActive(WorkspacePlanCatalog $catalog, WorkspacePlanTier $tier): void
    {
        if (! $catalog->is_active) {
            throw new InvalidArgumentException("Workspace plan catalog tier [{$tier->value}] is inactive and cannot receive new assignments.");
        }
    }

    private function assertBasePricingDefined(WorkspacePlanCatalog $catalog): void
    {
        if ($catalog->price === null || $catalog->currency_id === null) {
            throw new UndefinedPlanPricingException($catalog->id);
        }
    }

    private function assertSlotRatioDefined(WorkspacePlanCatalog $catalog): void
    {
        if ($catalog->additional_business_slot_price_ratio === null) {
            throw new UndefinedPlanPricingException($catalog->id);
        }
    }

    private function assertValidAdditionalBusinessSlots(WorkspacePlanTier $tier, int $additionalBusinessSlots): void
    {
        if ($tier === WorkspacePlanTier::Agency) {
            if ($additionalBusinessSlots !== 0) {
                throw new InvalidAdditionalBusinessSlotsException($tier->value, $additionalBusinessSlots);
            }

            return;
        }

        if (! in_array($additionalBusinessSlots, [0, 1, 2], true)) {
            throw new InvalidAdditionalBusinessSlotsException($tier->value, $additionalBusinessSlots);
        }
    }

    /**
     * Exact §13.F direction normalization: Core<->Growth preserves the
     * existing value unchanged ($requestedSlots must be null);
     * Core/Growth->Agency always resets to 0; Agency->Core/Growth (or an
     * unassigned/unknown prior tier) defaults to 0 or allocates the
     * explicitly requested value.
     */
    private function normalizeSlotsForDirection(
        ?WorkspacePlanTier $currentTier,
        WorkspacePlanTier $newTier,
        int $fromSlots,
        ?int $requestedSlots,
    ): int {
        $isCoreOrGrowth = static fn (WorkspacePlanTier $tier): bool => in_array($tier, [WorkspacePlanTier::Core, WorkspacePlanTier::Growth], true);

        if ($currentTier !== null && $isCoreOrGrowth($currentTier) && $isCoreOrGrowth($newTier)) {
            if ($requestedSlots !== null) {
                throw new InvalidArgumentException('additionalBusinessSlots must be null for a Core<->Growth plan change; slots are preserved unchanged.');
            }

            return $fromSlots;
        }

        if ($newTier === WorkspacePlanTier::Agency) {
            return 0;
        }

        return $requestedSlots ?? 0;
    }
}
