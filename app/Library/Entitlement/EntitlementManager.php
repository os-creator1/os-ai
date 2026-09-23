<?php

namespace App\Library\Entitlement;

use App\DTO\Entitlement\LocationSlotCapacityDecision;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Entitlement\BusinessAdditionalLocationSlotsChanged;
use App\Events\Entitlement\BusinessFeatureToggleChanged;
use App\Events\Entitlement\WorkspaceAccessRestored;
use App\Events\Entitlement\WorkspaceAdditionalBusinessSlotsChanged;
use App\Events\Entitlement\WorkspaceComplimentaryStatusChanged;
use App\Events\Entitlement\WorkspaceEnteredGracePeriod;
use App\Events\Entitlement\WorkspaceEntitlementOverrideChanged;
use App\Events\Entitlement\WorkspaceLocked;
use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Events\Entitlement\WorkspacePlanCatalogPricingChanged;
use App\Events\Entitlement\WorkspacePlanChanged;
use App\Events\Entitlement\WorkspacePlanStatusChanged;
use App\Exceptions\Entitlement\BusinessSlotAllocationRequiredException;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\ComplimentaryWorkspaceCannotAllocatePaidSlotsException;
use App\Exceptions\Entitlement\InactiveWorkspacePlanException;
use App\Exceptions\Entitlement\InvalidAdditionalBusinessSlotsException;
use App\Exceptions\Entitlement\InvalidAdditionalLocationSlotsException;
use App\Exceptions\Entitlement\InvalidPaymentAllocationEvidenceException;
use App\Exceptions\Entitlement\LocationAllocationCancellationRefusedException;
use App\Exceptions\Entitlement\LocationAllocationNotPortableException;
use App\Exceptions\Entitlement\LocationSlotAllocationRequiredException;
use App\Exceptions\Entitlement\LocationSlotLimitExceededException;
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
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CurrencyRepository;
use App\Repositories\Contracts\UserRepository;
use App\Repositories\Contracts\WorkspaceEntitlementOverrideRepository;
use App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogPricingChangeRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use App\Repositories\Contracts\WorkspacePlanFeatureRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Carbon\CarbonInterface;
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

    /**
     * Contract 03 §5/§7 (Blueprint §27) — how long Grace runs before an
     * unpaid account locks. One constant, read by the scheduled sweep that
     * writes `locked_at` AND by CustomerAccountAccessResolver's defensive
     * elapsed-Grace derivation, so the writer and the reader can never drift
     * to different windows.
     */
    public const GRACE_PERIOD_DAYS = 3;

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
        private readonly CurrencyRepository $currencyRepository,
        private readonly WorkspacePlanCatalogPricingChangeRepository $pricingChangeRepository,
        private readonly BusinessLocationRepository $locationRepository,
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
     *
     * Shared customer request query-budget optimization (Automations V2
     * §18) — this is now a single-feature call onto
     * snapshotBusinessFeatureDecisions() rather than its own, second
     * implementation of the same 8 steps. That method's own docblock
     * already documents the two as deliberately identical policy,
     * evaluated over data "loaded once instead of per feature"; the two
     * having separate code was itself a standing duplication risk (its own
     * comment warns "if decide()'s policy changes, this must change with
     * it"), and every read it performs is now cached per-request at the
     * repository layer, so a caller of decide() for one feature and a
     * caller of snapshotBusinessFeatureDecisions() for several — the exact
     * shape of the controller-vs-menu duplication this optimization
     * targets — now share the same underlying reads instead of each
     * re-deriving them from scratch.
     */
    public function decide(Workspace $workspace, Business $business, string $featureKey, int $actorUserId): EntitlementDecision
    {
        return $this->snapshotBusinessFeatureDecisions($workspace, $business, [$featureKey], $actorUserId)[$featureKey];
    }

    /**
     * Slice 2A §6.3 — decide(), evaluated for many features against ONE
     * bulk-loaded snapshot.
     *
     * WHY THIS LIVES HERE. The navigation menu must hide an entry whose
     * feature is not entitled, and answering that per entry through decide()
     * costs three per-feature reads each (override, plan mapping, toggle) —
     * around 35 queries for one render. The alternative a menu resolver
     * might reach for is re-deriving the answer itself, which would create a
     * SECOND entitlement authority that can drift from RFC-004 §14. That is
     * the outcome this method exists to prevent: the precedence below is the
     * same eight steps decide() applies, in the same order, in the same
     * class, over data that was loaded once instead of per feature.
     *
     * Only the data ACCESS changes shape. If decide()'s policy changes, this
     * must change with it — they are deliberately adjacent so a reader
     * cannot miss that.
     *
     * Cost: three constant reads plus three bulk reads = 6, regardless of how
     * many feature keys are asked for. The usage-authorization step adds 0
     * while every feature stays unmetered, exactly as in decide(); if that
     * ever changes, correctness wins and the ceiling is raised in a
     * follow-up — this must never skip step 11 to stay under a number.
     *
     * @param  array<int, string>  $featureKeys
     * @return array<string, EntitlementDecision> keyed by feature key
     */
    public function snapshotBusinessFeatureDecisions(
        Workspace $workspace,
        Business $business,
        array $featureKeys,
        int $actorUserId,
    ): array {
        $decisions = [];

        // Steps 1-3 need no database at all, so anything the registry already
        // refuses is answered before a single read is issued.
        $evaluable = [];

        foreach (array_unique($featureKeys) as $featureKey) {
            $feature = PlatformFeature::tryFrom($featureKey);

            if ($feature === null) {
                $decisions[$featureKey] = new EntitlementDecision(false, 'platform_feature_unknown');

                continue;
            }

            if (! PlatformFeatureRegistry::isAvailable($feature->value)) {
                $decisions[$featureKey] = new EntitlementDecision(false, 'platform_feature_unavailable');

                continue;
            }

            if (! PlatformFeatureRegistry::isBusinessScoped($feature->value)) {
                $decisions[$featureKey] = new EntitlementDecision(false, 'wrong_feature_scope');

                continue;
            }

            $evaluable[$featureKey] = $feature;
        }

        if ($evaluable === []) {
            return $decisions;
        }

        // Read 1 — the Business, and the same consistency guard decide()
        // applies. Throwing here matches decide() exactly; a caller that
        // hands over a mismatched pair has a bug, not an unentitled feature.
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

        // Read 2 — the plan assignment.
        $assignment = $this->assignmentRepository->findByWorkspaceId((int) $workspace->id);

        if ($assignment === null) {
            foreach ($evaluable as $featureKey => $feature) {
                $decisions[$featureKey] = new EntitlementDecision(false, 'workspace_plan_unassigned');
            }

            return $decisions;
        }

        // Read 3 — the catalog. Reads 4-6 — the three per-feature tables, in
        // bulk. featureKeysForCatalog() is the pre-existing bulk seam and is
        // reused rather than duplicated.
        $catalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
        $overrides = $this->overrideRepository->allForWorkspace((int) $workspace->id);
        $planFeatureKeys = $catalog !== null
            ? $this->planFeatureRepository->featureKeysForCatalog($catalog)->all()
            : [];
        $toggles = $this->toggleRepository->allForBusiness((int) $currentBusiness->id);

        $planFeatureKeys = array_flip(array_map('strval', $planFeatureKeys));

        foreach ($evaluable as $featureKey => $feature) {
            $override = $overrides->get($feature->value);

            if ($override !== null) {
                $workspaceEntitled = $override->state === WorkspaceEntitlementOverrideState::Allow;
                $denialReasonIfNot = 'denied_by_workspace_override';
            } else {
                $workspaceEntitled = $catalog !== null && isset($planFeatureKeys[$feature->value]);
                $denialReasonIfNot = 'not_entitled_by_plan';
            }

            if (! $workspaceEntitled) {
                $decisions[$featureKey] = new EntitlementDecision(false, $denialReasonIfNot);

                continue;
            }

            if ($toggles->get($feature->value) !== null) {
                $decisions[$featureKey] = new EntitlementDecision(false, 'disabled_for_business');

                continue;
            }

            if ($assignment->status === WorkspacePlanAssignmentStatus::Suspended) {
                $decisions[$featureKey] = new EntitlementDecision(false, 'plan_suspended');

                continue;
            }

            if ($assignment->status === WorkspacePlanAssignmentStatus::Inactive) {
                $decisions[$featureKey] = new EntitlementDecision(false, 'plan_inactive');

                continue;
            }

            $usageResult = $this->usageAuthorizationGateway->check($currentBusiness, $feature);

            if (! $usageResult->authorized) {
                $decisions[$featureKey] = new EntitlementDecision(false, $usageResult->reason ?? 'usage_unauthorized');

                continue;
            }

            $decisions[$featureKey] = new EntitlementDecision(true, null);
        }

        return $decisions;
    }

    /**
     * Agency AI Prospecting foundation — decide()'s Business-independent
     * subset, for a PlatformFeature that is Workspace-level and has no
     * owning Business at all (unlike every feature decide() was written
     * for). RFC-004 §14's decide() requires a Business to evaluate two of
     * its eight steps: the per-Business feature toggle (step 8) and the
     * Business-scoped usage-authorization-gateway check (step 11) — neither
     * concept exists without a Business, so this method reproduces only
     * the remaining, Business-independent precedence chain: known key
     * (floor) → available (floor, never bypassable by an override) →
     * Workspace plan assignment exists → Workspace override if present,
     * else plan mapping → suspended/inactive status. It uses this class's
     * own existing repositories exclusively — assignmentRepository,
     * catalogRepository, overrideRepository, planFeatureRepository — never
     * a parallel authority, and never mutates anything. Omitting the
     * usage-authorization-gateway step is not a shortcut: RFC-005 keeps
     * every PlatformFeature at is_metered=false through M5, so that step
     * is already a behavioral no-op for every feature today, Business or
     * not. This method is never called once a Business is in hand —
     * decide() remains the sole authority whenever one is.
     */
    public function decideForWorkspace(Workspace $workspace, string $featureKey): EntitlementDecision
    {
        $feature = PlatformFeature::tryFrom($featureKey);

        if ($feature === null) {
            return new EntitlementDecision(false, 'platform_feature_unknown');
        }

        if (! PlatformFeatureRegistry::isAvailable($feature->value)) {
            return new EntitlementDecision(false, 'platform_feature_unavailable');
        }

        // Correction 1 — this method exists solely for Workspace-scoped
        // features with no owning Business (ProspectOutreach today). It
        // must never become a generic bypass around decide() for an
        // ordinary Business-scoped feature (Crm, Conversations,
        // Automations, ...), which would silently skip decide()'s
        // Business-toggle and usage-authorization steps.
        if (! PlatformFeatureRegistry::isWorkspaceScoped($feature->value)) {
            return new EntitlementDecision(false, 'wrong_feature_scope');
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

        if ($assignment->status === WorkspacePlanAssignmentStatus::Suspended) {
            return new EntitlementDecision(false, 'plan_suspended');
        }

        if ($assignment->status === WorkspacePlanAssignmentStatus::Inactive) {
            return new EntitlementDecision(false, 'plan_inactive');
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
    // Physical-location capacity — Customer Experience Slice 1A
    // (RFC-004 §33; contract §7.3, §7.3a, §7.5)
    //
    // A Business's physical locations are a separate capacity from its
    // Workspace's Business slots and never reuse business_slot_* or
    // additional_business_slots. This section is the ONE home of the
    // location-capacity arithmetic: BusinessLocationManager (the lifecycle
    // write boundary) and changePlan() both call into it, never re-derive it.
    // =====================================================================

    /**
     * Explainable, unlocked read of one Business's location capacity — the
     * only source UI and tests read counts from.
     */
    public function decideLocationSlotCapacity(Business $business): LocationSlotCapacityDecision
    {
        $current = $this->businessRepository->findById($business->id);

        if ($current === null) {
            throw new WorkspaceBusinessNotFoundException($business->id);
        }

        $counts = $this->locationRepository->countByLifecycle((int) $current->id);

        return $this->evaluateLocationCapacity($current, $counts['active'], $counts['archived']);
    }

    /**
     * Contract §7.3/§33.8 — asserted at every active-location-count-
     * increasing operation (create AND reactivate). Locks nothing itself:
     * the caller already holds the Business row lock and passes that locked
     * row, so the counters and the locking count below are the latest
     * committed state.
     */
    public function assertCanActivateAnotherLocation(Business $lockedBusiness): void
    {
        $active = $this->locationRepository->countActiveForUpdate((int) $lockedBusiness->id);
        $decision = $this->evaluateLocationCapacity($lockedBusiness, $active, 0);

        if ($decision->allowed) {
            return;
        }

        match ($decision->denialReason) {
            'workspace_plan_unassigned' => throw new WorkspacePlanUnassignedException((int) $lockedBusiness->workspace_id),
            'location_slot_allocation_required' => throw new LocationSlotAllocationRequiredException((int) $lockedBusiness->id),
            'location_slot_limit_exceeded' => throw new LocationSlotLimitExceededException((int) $lockedBusiness->id),
            default => throw new RuntimeException("Unexpected location capacity denial reason [{$decision->denialReason}] for Business [{$lockedBusiness->id}]."),
        };
    }

    /**
     * RFC-004 §33.4 — the authoritative, audited mutation of a Business's
     * additional-location allocation (the 4th and 5th active locations on
     * Core/Growth). Platform-administrator only, exactly like
     * setAdditionalBusinessSlots() (§13): no customer can grant one.
     *
     *  - An increase for a NON-complimentary assignment requires the tier's
     *    price, currency AND additional_location_slot_price_ratio (§12.5);
     *    while Core/Growth prices are unset this is always refused, so no
     *    paid location capacity can be activated for a paying account.
     *    A complimentary assignment needs no pricing: nothing is charged.
     *  - A reduction is permitted only when the Business's ACTIVE locations
     *    fit the reduced capacity (contract §7.3a rule 6). Archiving never
     *    reduces this counter: paid capacity is reusable (rules 4–5).
     *
     * RFC-004 performs no charge; a future billing slice that collects the
     * 50% location charge is expected to call this same method after payment.
     */
    public function setAdditionalLocationSlots(Business $business, int $count, int $actorUserId, ?string $reason = null): Business
    {
        return DB::transaction(function () use ($business, $count, $actorUserId, $reason) {
            [$lockedWorkspace, $lockedBusiness] = $this->lockWorkspaceAndBusinessForToggle($business);

            $this->assertPlatformAdministrator($actorUserId);

            $assignment = $this->assignmentRepository->findByWorkspaceId($lockedWorkspace->id);

            if ($assignment === null) {
                throw new WorkspacePlanUnassignedException($lockedWorkspace->id);
            }

            $catalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
            $tier = $catalog?->tier ?? WorkspacePlanTier::Core;
            $fromCount = (int) $lockedBusiness->additional_location_slots;

            $this->assertValidAdditionalLocationSlots($catalog, $tier, $count, $fromCount);

            if ($count === $fromCount) {
                return $lockedBusiness;
            }

            $active = $this->locationRepository->countActiveForUpdate($lockedBusiness->id);
            $included = (int) ($catalog?->location_slot_included ?? 0);
            $grandfathered = (int) $lockedBusiness->grandfathered_location_slots;

            if ($count > $fromCount && ! $assignment->is_complimentary) {
                $lockedCatalog = $this->catalogRepository->findForUpdate($assignment->workspace_plan_catalog_id);
                $this->assertBasePricingDefined($lockedCatalog);
                $this->assertLocationSlotRatioDefined($lockedCatalog);
            }

            if ($count < $fromCount && ! (bool) $catalog?->unlimited_location_slots) {
                $capacityAfter = $included + $count + $grandfathered;

                if ($active > $capacityAfter) {
                    throw new LocationAllocationCancellationRefusedException($lockedBusiness->id, $active, $capacityAfter, $active - $capacityAfter);
                }
            }

            $this->businessRepository->query()->whereKey($lockedBusiness->id)->update(['additional_location_slots' => $count]);

            $this->transitionRepository->create([
                'workspace_id' => $lockedWorkspace->id,
                'transition_type' => WorkspaceEntitlementTransitionType::AdditionalLocationSlotsChanged,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'payload' => [
                    'source' => 'platform_admin_allocation',
                    'business_id' => $lockedBusiness->id,
                    'from_additional_location_slots' => $fromCount,
                    'to_additional_location_slots' => $count,
                    'active_locations' => $active,
                    'included' => $included,
                    'grandfathered_location_slots' => $grandfathered,
                    'complimentary' => (bool) $assignment->is_complimentary,
                ],
            ]);

            BusinessAdditionalLocationSlotsChanged::dispatch($lockedWorkspace->id, $lockedBusiness->id, $fromCount, $count, $actorUserId);

            return $this->businessRepository->findById($lockedBusiness->id);
        });
    }

    /**
     * Contract §7.5.3 — after a location is archived (caller holds the
     * Business row lock, inside the archive's own transaction): complimentary
     * grandfathered excess is CONSUMED, never turned into a transferable free
     * slot. The allowance shrinks to the Business's remaining excess over its
     * normal entitlement (included + additional), and to zero once it is
     * back within that. Paid additional slots are never touched here.
     */
    public function reconcileGrandfatheredLocationsAfterArchive(Business $lockedBusiness, int $actorUserId): void
    {
        $catalog = $this->locationCatalogFor($lockedBusiness);

        if ($catalog === null || $catalog->unlimited_location_slots) {
            // Agency: the allowance is retained but unused (§7.5.3), so a
            // later downgrade is evaluated fresh rather than re-granted.
            return;
        }

        $from = (int) $lockedBusiness->grandfathered_location_slots;

        if ($from === 0) {
            return;
        }

        $active = $this->locationRepository->countActiveForUpdate($lockedBusiness->id);
        $to = min($from, $this->freshGrandfatheredLocations($active, (int) $catalog->location_slot_included, (int) $lockedBusiness->additional_location_slots));

        if ($to === $from) {
            return;
        }

        $this->businessRepository->query()->whereKey($lockedBusiness->id)->update(['grandfathered_location_slots' => $to]);

        $this->transitionRepository->create([
            'workspace_id' => $lockedBusiness->workspace_id,
            'transition_type' => WorkspaceEntitlementTransitionType::CapacityGrandfathered,
            'actor_user_id' => $actorUserId,
            'reason' => 'A grandfathered location was archived; its complimentary allowance is consumed, not reusable.',
            'payload' => [
                'source' => 'location_archived',
                'locations' => [[
                    'business_id' => $lockedBusiness->id,
                    'active_locations_after' => $active,
                    'included' => (int) $catalog->location_slot_included,
                    'additional_location_slots' => (int) $lockedBusiness->additional_location_slots,
                    'from_grandfathered_location_slots' => $from,
                    'to_grandfathered_location_slots' => $to,
                ]],
            ],
        ]);
    }

    /**
     * RFC-004 §33.7 — called ONLY by WorkspaceManager::reassignBusiness() for
     * a real cross-Workspace move, inside that method's own transaction,
     * after it locked both Workspace rows (ascending id) and then the
     * Business row, and after the target's Business-slot capacity was
     * asserted. The only lock added here is the Business's own location
     * rows, so the order stays Workspace → Business → business_locations.
     *
     *  - A paid additional-location allocation belongs to the SOURCE
     *    Workspace's plan and billing and never moves with the Business: the
     *    move is refused before anything changes. The allocation is neither
     *    cleared nor transferred.
     *  - Against a TARGET tier with bounded locations, the complimentary
     *    allowance is recalculated FRESH from the Business's current active
     *    locations (never carried over from the source): exactly their excess
     *    over the target's included capacity. Every location is kept; the
     *    allowance never opens room for another. An unlimited target changes
     *    nothing: the allowance is retained but unused.
     */
    public function reconcileLocationCapacityForReassignment(Business $lockedBusiness, Workspace $lockedSourceWorkspace, Workspace $lockedTargetWorkspace, int $actorUserId): void
    {
        $paid = (int) $lockedBusiness->additional_location_slots;

        if ($paid > 0) {
            throw new LocationAllocationNotPortableException((int) $lockedBusiness->id, $paid);
        }

        $assignment = $this->assignmentRepository->findByWorkspaceId($lockedTargetWorkspace->id);
        $catalog = $assignment === null ? null : $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);

        if ($catalog === null) {
            throw new WorkspacePlanUnassignedException($lockedTargetWorkspace->id);
        }

        if ($catalog->unlimited_location_slots) {
            return;
        }

        $active = $this->locationRepository->countActiveForUpdate((int) $lockedBusiness->id);
        $included = (int) $catalog->location_slot_included;
        $from = (int) $lockedBusiness->grandfathered_location_slots;
        $to = $this->freshGrandfatheredLocations($active, $included, $paid);

        if ($to === $from && $to === 0) {
            // Arrives inside the target's capacity with nothing grandfathered.
            return;
        }

        if ($to !== $from) {
            $this->businessRepository->query()->whereKey($lockedBusiness->id)->update(['grandfathered_location_slots' => $to]);

            // Keep the caller's locked row truthful without marking it dirty.
            $lockedBusiness->forceFill(['grandfathered_location_slots' => $to])->syncOriginalAttribute('grandfathered_location_slots');
        }

        $this->transitionRepository->create([
            'workspace_id' => $lockedTargetWorkspace->id,
            'transition_type' => WorkspaceEntitlementTransitionType::CapacityGrandfathered,
            'actor_user_id' => $actorUserId,
            'reason' => 'A Business moved to this Workspace; its physical-location allowance was recalculated against this plan.',
            'payload' => [
                'source' => 'business_reassignment',
                'business_id' => (int) $lockedBusiness->id,
                'source_workspace_id' => (int) $lockedSourceWorkspace->id,
                'target_workspace_id' => (int) $lockedTargetWorkspace->id,
                'target_plan_catalog_id' => (int) $catalog->id,
                'active_locations' => $active,
                'included' => $included,
                'from_grandfathered_location_slots' => $from,
                'to_grandfathered_location_slots' => $to,
            ],
        ]);
    }

    /**
     * The single location-capacity algorithm (contract §7.3, §7.5.2).
     *
     * Capacity comes from the Workspace's TIER only. Operational plan status
     * deliberately does not gate it: a location is part of the Business's
     * own record and costs nothing, and paid capacity still needs an
     * allocation. A Business's first location is always inside what every
     * tier includes, so it is never refused — onboarding's location step
     * stays exactly as it was. Grandfathered excess only protects locations
     * that already exist — it is exactly their excess, so it never opens
     * room for a new one — and the tier maximum is a hard ceiling no
     * allocation raises.
     */
    private function evaluateLocationCapacity(Business $business, int $active, int $archived): LocationSlotCapacityDecision
    {
        $paid = (int) ($business->additional_location_slots ?? 0);
        $grandfathered = (int) ($business->grandfathered_location_slots ?? 0);
        $assignment = $this->assignmentRepository->findByWorkspaceId((int) $business->workspace_id);

        if ($assignment === null) {
            // No tier to read capacity from: only the first location, which
            // every tier includes, is certain.
            $allowed = $active === 0;

            return new LocationSlotCapacityDecision($active, $archived, 0, $paid, $grandfathered, null, null, false, $allowed, $allowed ? null : 'workspace_plan_unassigned');
        }

        $catalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
        $included = (int) ($catalog?->location_slot_included ?? 0);

        if ($catalog !== null && $catalog->unlimited_location_slots) {
            return new LocationSlotCapacityDecision($active, $archived, $included, $paid, $grandfathered, null, null, true, true, null);
        }

        $maximum = $catalog?->location_slot_max ?? ($included + $paid);
        $effective = $included + $paid + $grandfathered;

        if ($active < $effective && $active < $maximum) {
            return new LocationSlotCapacityDecision($active, $archived, $included, $paid, $grandfathered, $maximum, $effective, false, true, null);
        }

        $reason = $active >= $maximum ? 'location_slot_limit_exceeded' : 'location_slot_allocation_required';

        return new LocationSlotCapacityDecision($active, $archived, $included, $paid, $grandfathered, $maximum, $effective, false, false, $reason);
    }

    /**
     * Contract §7.5.3 / RFC-004 §33.7 — called by changePlan() inside its own
     * transaction, after the tier row changed. Moving to a tier with bounded
     * location capacity re-evaluates every Business's complimentary
     * allowance FRESH against its current active locations (never restored
     * from a pre-upgrade value). Every existing Business and location is
     * kept; nothing is archived or hidden. Moving to an unlimited tier
     * changes nothing: the allowance is retained but unused.
     */
    private function regrandfatherForPlanChange(Workspace $lockedWorkspace, ?WorkspacePlanCatalog $fromCatalog, WorkspacePlanCatalog $toCatalog, int $actorUserId, ?string $reason): void
    {
        if ($toCatalog->unlimited_location_slots) {
            return;
        }

        $businesses = $this->businessRepository->query()
            ->where('workspace_id', $lockedWorkspace->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $included = (int) $toCatalog->location_slot_included;
        $locations = [];

        foreach ($businesses as $business) {
            $active = $this->locationRepository->countActiveForUpdate((int) $business->id);
            $paid = (int) $business->additional_location_slots;
            $from = (int) $business->grandfathered_location_slots;
            $to = $this->freshGrandfatheredLocations($active, $included, $paid);

            if ($to === $from) {
                continue;
            }

            $this->businessRepository->query()->whereKey($business->id)->update(['grandfathered_location_slots' => $to]);

            $locations[] = [
                'business_id' => (int) $business->id,
                'active_locations' => $active,
                'included' => $included,
                'additional_location_slots' => $paid,
                'from_grandfathered_location_slots' => $from,
                'to_grandfathered_location_slots' => $to,
            ];
        }

        $businessCount = $businesses->count();
        $overAt = fn (?WorkspacePlanCatalog $catalog) => $catalog !== null && ! $catalog->unlimited_business_slots && $businessCount > (int) $catalog->business_slot_included;
        $overBusinessCapacity = $overAt($toCatalog);
        $newlyOverBusinessCapacity = $overBusinessCapacity && ! $overAt($fromCatalog);

        if ($locations === [] && ! $newlyOverBusinessCapacity) {
            return;
        }

        $this->transitionRepository->create([
            'workspace_id' => $lockedWorkspace->id,
            'transition_type' => WorkspaceEntitlementTransitionType::CapacityGrandfathered,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'payload' => [
                'source' => 'plan_change',
                'from_plan_catalog_id' => $fromCatalog?->id,
                'to_plan_catalog_id' => $toCatalog->id,
                'businesses' => [
                    'business_ids' => $businesses->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'count' => $businessCount,
                    'included' => (int) $toCatalog->business_slot_included,
                    'max' => $toCatalog->business_slot_max,
                    'grandfathered_over_capacity' => $overBusinessCapacity,
                ],
                'locations' => $locations,
            ],
        ]);
    }

    /**
     * Contract §7.5.3 — the one fresh grandfathering rule shared by a plan
     * change, an archive and a cross-Workspace move: on a bounded tier the
     * complimentary allowance is exactly the Business's active excess over
     * its normal entitlement (included + paid), and zero once within it.
     */
    private function freshGrandfatheredLocations(int $active, int $included, int $paid): int
    {
        return max(0, $active - ($included + $paid));
    }

    private function locationCatalogFor(Business $business): ?WorkspacePlanCatalog
    {
        $assignment = $this->assignmentRepository->findByWorkspaceId((int) $business->workspace_id);

        return $assignment === null ? null : $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
    }

    private function assertValidAdditionalLocationSlots(?WorkspacePlanCatalog $catalog, WorkspacePlanTier $tier, int $count, int $fromCount): void
    {
        if ($count < 0) {
            throw new InvalidAdditionalLocationSlotsException($tier->value, $count);
        }

        if ($catalog === null) {
            if ($count > 0) {
                throw new InvalidAdditionalLocationSlotsException($tier->value, $count);
            }

            return;
        }

        if ($catalog->unlimited_location_slots) {
            // No additional-location concept on an unlimited tier: only a
            // reduction of a value carried over from a bounded tier is valid.
            if ($count > $fromCount) {
                throw new InvalidAdditionalLocationSlotsException($tier->value, $count);
            }

            return;
        }

        $allowed = max(0, (int) ($catalog->location_slot_max ?? $catalog->location_slot_included) - (int) $catalog->location_slot_included);

        if ($count > $allowed) {
            throw new InvalidAdditionalLocationSlotsException($tier->value, $count);
        }
    }

    private function assertLocationSlotRatioDefined(WorkspacePlanCatalog $catalog): void
    {
        if ($catalog->additional_location_slot_price_ratio === null) {
            throw new UndefinedPlanPricingException($catalog->id);
        }
    }

    // =====================================================================
    // Read / presentation (M3 §8 — no repository outside this class's own
    // dependencies is ever queried directly by a controller or view)
    // =====================================================================

    /**
     * Customer Experience Slice 1A, correction round 2 (RFC-004 §33.2) — the
     * additional-Business-slot counts an administrator may actually allocate,
     * per tier, derived from the catalog rows themselves so no surface can
     * offer a value this class would then refuse. The corrected Core and
     * Growth rows (1/1) and Agency (unlimited Businesses, no additional-slot
     * concept) all offer exactly [0]; a bounded row with room offers
     * 0..(business_slot_max − business_slot_included).
     *
     * @return array<string, array<int, int>> tier value => ascending valid counts, always starting at 0
     */
    public function additionalBusinessSlotOptionsByTier(): array
    {
        $options = [];

        foreach (WorkspacePlanTier::cases() as $tier) {
            $catalog = $this->catalogRepository->findByTier($tier);

            $options[$tier->value] = range(0, $this->additionalBusinessSlotCapacity($catalog));
        }

        return $options;
    }

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
                (int) $catalog->location_slot_included,
                $catalog->location_slot_max,
                (bool) $catalog->unlimited_location_slots,
                $catalog->additional_location_slot_price_ratio,
                $catalog->is_active,
                $planFeatureKeys,
                $featureAvailability,
            );
        }

        return $summaries;
    }

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — this used to ask findByWorkspaceAndFeature() once per
     * PlatformFeature case (one query per case, unconditionally, even
     * though this Workspace almost always has zero or a handful of
     * overrides). allForWorkspace() is the pre-existing bulk seam
     * (already used by snapshotBusinessFeatureDecisions()) that returns
     * the exact same rows in one query; no behavior changes, since a
     * feature absent from the bulk result is exactly a feature
     * findByWorkspaceAndFeature() would have returned null for.
     */
    public function getWorkspaceEntitlementSummary(Workspace $workspace): WorkspaceEntitlementSummary
    {
        $assignment = $this->assignmentRepository->findByWorkspaceId((int) $workspace->id);
        $capacity = $this->decideBusinessSlotCapacity($workspace);

        $allOverrides = $this->overrideRepository->allForWorkspace((int) $workspace->id);
        $overrides = [];

        foreach (PlatformFeature::cases() as $feature) {
            $override = $allOverrides->get($feature->value);

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
            // Contract 03 §5 — the lifecycle timestamps travel with the
            // summary so the read-only resolver never touches the table.
            $assignment->trial_ends_at,
            $assignment->grace_started_at,
            $assignment->locked_at,
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

            // Correction 1 — this API returns Business-addressable feature
            // decisions only. A Workspace-scoped feature (ProspectOutreach)
            // is never a Business's own feature to view, toggle, or
            // disable, so it is excluded here entirely rather than
            // appearing with a denied decision.
            if (! PlatformFeatureRegistry::isBusinessScoped($feature->value)) {
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

    /**
     * Implementation Contract 21 §7/§10.2 — narrow, SUBSCRIPTION-PROOF-
     * PROVENANCE-ONLY entry points, reachable only from lane A's
     * PlatformSubscriptionManager after that caller has already independently
     * verified a durable, provider-confirmed subscription for exactly this
     * Workspace.
     *
     * WHY THEY EXIST. assignFirstPlan() and changePlan() assert a platform
     * administrator, which is correct for an admin acting on someone else's
     * account and wrong for a customer buying the product: in self-serve
     * signup the authority is not a human administrator, it is a confirmed
     * payment. This is the same authority model RFC-004 Amendment 1 already
     * established for allocateAdditionalBusinessSlotsFromVerifiedPayment() —
     * "the caller has already verified a durable, successful, idempotent
     * payment record", and this class trusts that prior verification rather
     * than re-deciding it.
     *
     * WHY A FLAG RATHER THAN A SECOND IMPLEMENTATION. Copying the assignment
     * and plan-change bodies would create two versions of the tier/pricing/
     * slot validation and the audit trail, which is exactly the duplication
     * that lets the two drift. There is one implementation; these wrappers
     * change only WHO is permitted to reach it, and the actor recorded in the
     * audit trail is the Workspace OWNER — honest, because they are the party
     * who consented and paid.
     *
     * NEVER A GENERAL ADMIN BYPASS. The flag is private, is set only inside
     * these wrappers, is cleared in a `finally` so an exception cannot leak
     * it, and every wrapper validates its evidence first.
     */
    private bool $verifiedSubscriptionProvenance = false;

    public function assignFirstPlanFromVerifiedSubscription(
        Workspace $workspace,
        WorkspacePlanTier $tier,
        int $ownerUserId,
        string $subscriptionUid,
        string $providerReference,
        ?CarbonInterface $trialEndsAt,
        string $reason,
    ): WorkspacePlanAssignment {
        $this->assertVerifiedSubscriptionEvidence($subscriptionUid, $providerReference);

        return $this->withVerifiedSubscriptionProvenance(fn (): WorkspacePlanAssignment => $this->assignFirstPlan(
            $workspace,
            $tier,
            $ownerUserId,
            $reason,
            false,
            0,
            $trialEndsAt,
        ));
    }

    public function changePlanFromVerifiedSubscription(
        Workspace $workspace,
        WorkspacePlanTier $newTier,
        int $ownerUserId,
        string $subscriptionUid,
        string $providerReference,
        string $reason,
    ): WorkspacePlanAssignment {
        $this->assertVerifiedSubscriptionEvidence($subscriptionUid, $providerReference);

        return $this->withVerifiedSubscriptionProvenance(fn (): WorkspacePlanAssignment => $this->changePlan(
            $workspace,
            $newTier,
            $ownerUserId,
            $reason,
        ));
    }

    private function assertVerifiedSubscriptionEvidence(string $subscriptionUid, string $providerReference): void
    {
        if (trim($subscriptionUid) === '' || trim($providerReference) === '') {
            throw new InvalidArgumentException(
                'A verified lane-A subscription requires both a durable local subscription uid and a provider reference.'
            );
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable():TReturn  $operation
     * @return TReturn
     */
    private function withVerifiedSubscriptionProvenance(callable $operation)
    {
        $previous = $this->verifiedSubscriptionProvenance;
        $this->verifiedSubscriptionProvenance = true;

        try {
            return $operation();
        } finally {
            $this->verifiedSubscriptionProvenance = $previous;
        }
    }

    /**
     * Contract 03 §5 — `$trialEndsAt` is optional and trailing: every one of
     * the existing call sites uses positional arguments against the previous
     * six-parameter signature, so none of them changes. Pass it only when the
     * plan-selection flow actually grants a trial; `null` means this
     * assignment is plain Active from the start.
     */
    public function assignFirstPlan(
        Workspace $workspace,
        WorkspacePlanTier $tier,
        int $actorUserId,
        string $reason,
        bool $isComplimentary = false,
        int $additionalBusinessSlots = 0,
        ?CarbonInterface $trialEndsAt = null,
    ): WorkspacePlanAssignment {
        return DB::transaction(function () use ($workspace, $tier, $actorUserId, $reason, $isComplimentary, $additionalBusinessSlots, $trialEndsAt) {
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

            $this->assertValidAdditionalBusinessSlots($catalog, $tier, $additionalBusinessSlots, 0);

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
                'trial_ends_at' => $trialEndsAt,
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

            $this->assertValidAdditionalBusinessSlots($destinationCatalog, $newTier, $toSlots, $fromSlots);

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
                // Customer Experience Slice 1A (RFC-004 §33.7): physical-
                // location grandfathering is normalized in THIS transaction,
                // by the one location-capacity algorithm above.
                $this->regrandfatherForPlanChange($lockedWorkspace, $currentCatalog, $destinationCatalog, $actorUserId, $reason);
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
    // Account lifecycle — Trial / Grace / Locked (Contract 03, Slice 4)
    // =====================================================================
    //
    // The ONLY writers of trial_ends_at, grace_started_at and locked_at.
    // CustomerAccountAccessResolver reads what these three produce and
    // derives the customer-facing state from it; it never writes here, and
    // there is deliberately no second lifecycle authority (Contract 03 §3).
    //
    // All three share one shape, and it is the shape changePlanStatus()
    // already established: one transaction, the Workspace row locked first,
    // an Active-only target, an idempotent no-op when the state is already
    // what the caller asked for, and exactly one transition row plus one
    // event when a write actually happens.
    //
    // Two deliberate differences from changePlanStatus(), both required by
    // §5/§7: `$actorUserId` and `$reason` are nullable, because the
    // scheduled sweep (AdvanceWorkspaceAccountLifecycle) is a trusted system
    // path with no human actor — the same null-actor precedent
    // performVerifiedAllocation() already sets for payment-driven writes. A
    // non-null actor is still held to the platform-administrator check.

    /**
     * Contract 03 §6 cases B and C — start the Grace window, either because a
     * trial ended without conversion (case B: the scheduled sweep, null
     * actor, reached through advanceExpiredTrialIntoGrace()) or because a
     * renewal payment failed (case C: a platform administrator today, a
     * payment-provider integration later).
     *
     * Grace keeps FULL access (Blueprint §27); only the billing prompt
     * changes. The base status stays Active throughout, so this writes no
     * status and the transition row records no status change — because none
     * happened.
     *
     * Idempotent: an assignment already in Grace, or already Locked, is
     * returned unchanged. Re-entering Grace would silently extend the window
     * every time a retry failed, which is exactly the bug that would let a
     * delinquent account never lock.
     */
    public function enterGracePeriod(Workspace $workspace, ?int $actorUserId = null, ?string $reason = null): WorkspacePlanAssignment
    {
        return DB::transaction(function () use ($workspace, $actorUserId, $reason) {
            $assignment = $this->lockedLifecycleAssignment($workspace, $actorUserId);

            if ($assignment->grace_started_at !== null || $assignment->locked_at !== null) {
                return $assignment;
            }

            $updated = $this->assignmentRepository->update($assignment, ['grace_started_at' => now()]);

            $this->transitionRepository->create([
                'workspace_id' => $assignment->workspace_id,
                'transition_type' => WorkspaceEntitlementTransitionType::GraceStarted,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            WorkspaceEnteredGracePeriod::dispatch($assignment->workspace_id, $actorUserId, $reason);

            return $updated;
        });
    }

    /**
     * Contract 03 §6 case D — Grace elapsed without payment: lock the
     * account. This is the durable write behind the resolver's Locked state;
     * the scheduled sweep reaches it through lockElapsedGracePeriod().
     *
     * `grace_started_at` is deliberately left in place: it is the audit of
     * when the window opened, and clearing it would erase why this lock
     * exists. recoverAccess() is the one thing that clears either column.
     *
     * Idempotent: an already-locked assignment is returned unchanged, so a
     * re-run of the sweep can never move the lock timestamp forward.
     */
    public function lockForNonPayment(Workspace $workspace, ?int $actorUserId = null, ?string $reason = null): WorkspacePlanAssignment
    {
        return DB::transaction(function () use ($workspace, $actorUserId, $reason) {
            $assignment = $this->lockedLifecycleAssignment($workspace, $actorUserId);

            if ($assignment->locked_at !== null) {
                return $assignment;
            }

            $updated = $this->assignmentRepository->update($assignment, ['locked_at' => now()]);

            $this->transitionRepository->create([
                'workspace_id' => $assignment->workspace_id,
                'transition_type' => WorkspaceEntitlementTransitionType::AccountLocked,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            WorkspaceLocked::dispatch($assignment->workspace_id, $actorUserId, $reason);

            return $updated;
        });
    }

    /**
     * Contract 03 §6 case E — the account is paid up: return it to plain
     * Active in ONE write (Blueprint §27's immediate unlock). (Case F, the
     * move to Inactive, is changePlanStatus(), unchanged — not this method.)
     *
     * This clears all THREE lifecycle timestamps atomically, and that is the
     * contract's central invariant, not a convenience:
     *
     *   `trial_ends_at` records an OUTSTANDING trial, never "this customer
     *   once had a trial".
     *
     * A converted customer whose `trial_ends_at` survived would be selected
     * by the trial-expiry sweep the moment that old date passed, and would be
     * dropped into Grace — billing a paying customer for a trial they already
     * converted out of. Clearing it here, in the same write as the other two,
     * is what makes that impossible rather than merely unlikely. The sweep's
     * own predicate is the second guard, not the only one.
     *
     * One writer covers both the early conversion (Trial -> Active, nothing
     * else set) and the full recovery (Grace/Locked -> Active), because both
     * mean the same thing: nothing is outstanding any more.
     *
     * Idempotent: an assignment with all three already null is returned
     * unchanged, with no transition row and no event.
     */
    public function recoverAccess(Workspace $workspace, ?int $actorUserId = null, ?string $reason = null): WorkspacePlanAssignment
    {
        return DB::transaction(function () use ($workspace, $actorUserId, $reason) {
            $assignment = $this->lockedLifecycleAssignment($workspace, $actorUserId);

            if ($assignment->trial_ends_at === null && $assignment->grace_started_at === null && $assignment->locked_at === null) {
                return $assignment;
            }

            $updated = $this->assignmentRepository->update($assignment, [
                'trial_ends_at' => null,
                'grace_started_at' => null,
                'locked_at' => null,
            ]);

            $this->transitionRepository->create([
                'workspace_id' => $assignment->workspace_id,
                'transition_type' => WorkspaceEntitlementTransitionType::AccessRestored,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            WorkspaceAccessRestored::dispatch($assignment->workspace_id, $actorUserId, $reason);

            return $updated;
        });
    }

    /**
     * Contract 03 §6 / Contract 21 §10.4 — a PROVIDER-CONFIRMED TRIAL begins
     * on an assignment that already exists.
     *
     * WHY THIS IS NOT recoverAccess(). recoverAccess() means "nothing is
     * outstanding any more" and clears all three timestamps, which is exactly
     * right for a confirmed PAYMENT and exactly wrong for a confirmed TRIAL: a
     * trial IS outstanding, and clearing `trial_ends_at` would hide it from
     * the expiry sweep, so the account would sit on a trial that never ended.
     *
     * WHY IT IS NOT assignFirstPlan() EITHER. That path creates the
     * assignment. This one is for a Workspace that already has a plan
     * assignment and has just been confirmed onto a new trialing subscription
     * — a customer who cancelled, was locked, and came back (Contract 21
     * §10.4). Their old `locked_at` is stale the moment the provider confirms
     * the new trial, and leaving it there would tell a customer with a valid
     * Stripe trial that their account is locked.
     *
     * So this is the narrowest possible writer: the trial the PROVIDER
     * confirmed, and the removal of the two timestamps that contradict it.
     *
     * `$trialEndsAt` is PROVIDER TRUTH, never a catalog duration. The catalog
     * says what a new subscriber is offered; only the provider knows when this
     * subscription's trial actually ends, and it is the provider that decides
     * when to start charging. A local value that disagreed would either cut a
     * paid-for trial short or promise one Stripe will not honour. (§8's rule
     * that a later CATALOG edit cannot rewrite an existing trial is unaffected
     * — nothing here reads the catalog.)
     *
     * IDEMPOTENT, and that matters more here than anywhere else in this
     * family: every `customer.subscription.updated` delivery for a trialing
     * subscription reaches this method. An assignment already carrying this
     * exact trial end, with no grace and no lock, is returned untouched — no
     * write, no transition row, no event — so replay cannot move the trial
     * end forward or fabricate a second AccessRestored.
     *
     * Suspended and Inactive still throw, through the shared preamble: an
     * administrative suspension outranks any provider event (§5's precedence),
     * and failing closed is how that stays true.
     */
    public function startProviderConfirmedTrial(
        Workspace $workspace,
        CarbonInterface $trialEndsAt,
        ?int $actorUserId = null,
        ?string $reason = null,
    ): WorkspacePlanAssignment {
        return DB::transaction(function () use ($workspace, $trialEndsAt, $actorUserId, $reason) {
            $assignment = $this->lockedLifecycleAssignment($workspace, $actorUserId);

            // Second precision, because that is what the column stores: a
            // provider timestamp carrying microseconds must not read as a
            // different trial from the one already persisted.
            $sameTrial = $assignment->trial_ends_at !== null
                && $assignment->trial_ends_at->getTimestamp() === $trialEndsAt->getTimestamp();

            if ($sameTrial && $assignment->grace_started_at === null && $assignment->locked_at === null) {
                return $assignment;
            }

            $updated = $this->assignmentRepository->update($assignment, [
                'trial_ends_at' => $trialEndsAt,
                'grace_started_at' => null,
                'locked_at' => null,
            ]);

            // AccessRestored rather than a new transition type: what happened
            // to the ACCOUNT is that access was restored, and WHY travels in
            // the reason string — the same choice lockForNonPayment() already
            // makes for a cancellation.
            $this->transitionRepository->create([
                'workspace_id' => $assignment->workspace_id,
                'transition_type' => WorkspaceEntitlementTransitionType::AccessRestored,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            WorkspaceAccessRestored::dispatch($assignment->workspace_id, $actorUserId, $reason);

            return $updated;
        });
    }

    /**
     * Contract 03 §7 sweep 1's candidate list: Workspaces whose OUTSTANDING
     * trial has run out and that are not already in Grace or Locked.
     *
     * The sweep's query lives behind this method because RFC-004 §15/§20
     * makes this class and its own repositories the only readers of
     * workspace_plan_assignments — the scheduled command orchestrates, it
     * does not query the entitlement tables itself.
     *
     * @return array<int, int> workspace ids
     */
    public function findWorkspaceIdsWithExpiredOutstandingTrial(): array
    {
        return $this->assignmentRepository->findWorkspaceIdsWithOutstandingTrialEndedBy(now());
    }

    /**
     * Contract 03 §7 sweep 2's candidate list: Workspaces whose Grace window
     * has fully elapsed and that are not locked yet.
     *
     * GRACE_PERIOD_DAYS is subtracted HERE rather than expressed as an
     * interval inside the query, so the window's length stays one constant
     * shared with the resolver's own defensive derivation.
     *
     * @return array<int, int> workspace ids
     */
    public function findWorkspaceIdsWithElapsedGracePeriod(): array
    {
        return $this->assignmentRepository->findWorkspaceIdsWithGraceStartedBy(now()->subDays(self::GRACE_PERIOD_DAYS));
    }

    /**
     * Contract 03 §7 sweep 1, for ONE candidate Workspace: move it into Grace
     * only if the sweep's exact predicate still holds under the row lock.
     *
     * The candidate list above is read before any lock, and a sweep over
     * many Workspaces takes time. A trial that converts in between has had
     * all three lifecycle columns cleared by recoverAccess() — including the
     * two enterGracePeriod()'s own idempotency guard looks at, so that guard
     * alone would PASS and push a paying customer into Grace from a stale
     * list. Re-checking §7's whole predicate here, while holding the lock the
     * nested enterGracePeriod() call re-takes in this same transaction, is
     * what makes "a converted customer never re-enters Grace" true for a run
     * already in flight, not merely for the next one.
     *
     * The write itself is still enterGracePeriod() — the contract's named
     * writer, with its transition row and event — never a second copy of it.
     *
     * @return bool true when the Workspace was moved into Grace; false when it
     *              no longer matches the sweep (converted, suspended, closed,
     *              unassigned, or already advanced) and nothing was written.
     */
    public function advanceExpiredTrialIntoGrace(Workspace $workspace, string $reason): bool
    {
        return DB::transaction(function () use ($workspace, $reason): bool {
            $assignment = $this->assignmentRepository->findByWorkspaceIdForUpdate($this->lockWorkspaceRow($workspace)->id);

            // §7 sweep 1, clause for clause.
            $stillEligible = $assignment !== null
                && $assignment->status === WorkspacePlanAssignmentStatus::Active
                && $assignment->trial_ends_at !== null
                && ! $assignment->trial_ends_at->isFuture()
                && $assignment->grace_started_at === null
                && $assignment->locked_at === null;

            if (! $stillEligible) {
                return false;
            }

            $this->enterGracePeriod($workspace, null, $reason);

            return true;
        });
    }

    /**
     * Contract 03 §7 sweep 2, for ONE candidate Workspace: lock it only if
     * its Grace window has still fully elapsed under the row lock.
     *
     * Same stale-list problem as sweep 1, with a worse outcome: a customer
     * who paid between the candidate query and this write has
     * `locked_at = NULL` again, which is exactly what lockForNonPayment()'s
     * own guard checks — so without this re-check they would be locked out
     * seconds after paying, in a `grace_started_at = NULL, locked_at = set`
     * state no §5 transition produces.
     *
     * @return bool true when the Workspace was locked; false when it no
     *              longer matches the sweep and nothing was written.
     */
    public function lockElapsedGracePeriod(Workspace $workspace, string $reason): bool
    {
        return DB::transaction(function () use ($workspace, $reason): bool {
            $assignment = $this->assignmentRepository->findByWorkspaceIdForUpdate($this->lockWorkspaceRow($workspace)->id);

            // §7 sweep 2, clause for clause — the same instant the resolver's
            // defensive derivation flips to Locked.
            $stillEligible = $assignment !== null
                && $assignment->status === WorkspacePlanAssignmentStatus::Active
                && $assignment->grace_started_at !== null
                && ! $assignment->grace_started_at->copy()->addDays(self::GRACE_PERIOD_DAYS)->isFuture()
                && $assignment->locked_at === null;

            if (! $stillEligible) {
                return false;
            }

            $this->lockForNonPayment($workspace, null, $reason);

            return true;
        });
    }

    /**
     * Locks the Workspace row every lifecycle mutation serializes on — the
     * same first step changePlanStatus() takes.
     */
    private function lockWorkspaceRow(Workspace $workspace): Workspace
    {
        $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

        if ($lockedWorkspace === null) {
            throw new WorkspaceNotFoundException($workspace->id);
        }

        return $lockedWorkspace;
    }

    /**
     * The shared preamble of all three lifecycle writers: lock the Workspace
     * row, authorize a human actor if there is one, load the assignment, and
     * refuse anything whose base status is not Active.
     *
     * The assignment is read with findByWorkspaceIdForUpdate() — a current,
     * locking read — rather than the request-memoized findByWorkspaceId().
     * §7 requires the state to be read AFTER the lock is taken; a memoized
     * copy may have been read before it (earlier in the same request, or
     * anywhere in a long-running console process), and every idempotency
     * guard below would then decide on a snapshot another transaction has
     * already changed.
     *
     * Suspended and Inactive throw rather than writing a lifecycle timestamp
     * nobody would ever read: the resolver's §5 table only consults these
     * columns for an Active assignment, so writing one onto a Suspended row
     * would record an invisible state and quietly lose the caller's intent.
     * Suspension in particular is administrative — only changePlanStatus()
     * lifts it, and no payment event may override it (§5's precedence).
     */
    private function lockedLifecycleAssignment(Workspace $workspace, ?int $actorUserId): WorkspacePlanAssignment
    {
        $lockedWorkspace = $this->lockWorkspaceRow($workspace);

        // A null actor is the trusted system path (the scheduled sweep). A
        // non-null one is a human and is held to the same bar every other
        // administrative entitlement write already applies.
        if ($actorUserId !== null) {
            $this->assertPlatformAdministrator($actorUserId);
        }

        $assignment = $this->assignmentRepository->findByWorkspaceIdForUpdate($lockedWorkspace->id);

        if ($assignment === null) {
            throw new WorkspacePlanUnassignedException($lockedWorkspace->id);
        }

        if ($assignment->status === WorkspacePlanAssignmentStatus::Suspended) {
            throw new SuspendedWorkspacePlanException($lockedWorkspace->id);
        }

        if ($assignment->status === WorkspacePlanAssignmentStatus::Inactive) {
            throw new InactiveWorkspacePlanException($lockedWorkspace->id);
        }

        return $assignment;
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
            $fromCount = $assignment->additional_business_slots;

            $this->assertValidAdditionalBusinessSlots($catalog, $tier, $count, $fromCount);

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

            $existingTransition = $this->transitionRepository->findByPaymentIdempotencyKey($paymentIdempotencyKey);

            if ($existingTransition !== null) {
                $recordedDelta = $existingTransition->to_additional_business_slots - $existingTransition->from_additional_business_slots;

                $isExactReplay = (int) $existingTransition->workspace_id === $lockedWorkspace->id
                    && (int) $existingTransition->requesting_customer_user_id === $requestingCustomerUserId
                    && $recordedDelta === $additionalSlotsToAdd;

                if (! $isExactReplay) {
                    throw new PaymentAllocationIdempotencyConflictException($paymentIdempotencyKey);
                }

                return $assignment;
            }

            $toCount = $fromCount + $additionalSlotsToAdd;

            if ($assignment->is_complimentary) {
                throw new ComplimentaryWorkspaceCannotAllocatePaidSlotsException($lockedWorkspace->id);
            }

            $catalog = $this->catalogRepository->findById($assignment->workspace_plan_catalog_id);
            $tier = $catalog?->tier ?? WorkspacePlanTier::Core;

            $this->assertValidAdditionalBusinessSlots($catalog, $tier, $toCount, $fromCount);

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

    /**
     * Correction 2 — a Workspace-scoped feature (ProspectOutreach) can
     * never be created/deleted through either Business-feature-toggle
     * mutator, independently of decide()'s own wrong_feature_scope
     * denial (Correction 1 relied solely on disableBusinessFeature()'s
     * incidental call to decide() for this — enableBusinessFeature()
     * never calls decide() at all, so a stale/manually-seeded toggle row
     * for a Workspace-scoped feature could still be deleted through it).
     * This single guard is called first by both mutators so the
     * invariant cannot drift between them again.
     */
    private function assertFeatureIsBusinessScoped(PlatformFeature $feature): void
    {
        if (! PlatformFeatureRegistry::isBusinessScoped($feature->value)) {
            throw new RuntimeException("Feature [{$feature->value}] is Workspace-scoped, not Business-scoped; it cannot be toggled for a Business.");
        }
    }

    /**
     * Correction 1 — a Workspace-scoped feature (ProspectOutreach) can
     * never receive a business_feature_toggles row here: decide()'s own
     * wrong_feature_scope denial (never reaching the toggle-repository
     * write below) is the single, central scope authority this relies on
     * — never a separate, duplicated feature-key check that could drift
     * from decide()'s own rule. Correction 2 adds
     * assertFeatureIsBusinessScoped() as an explicit, independent guard
     * on top — no longer relying solely on decide()'s incidental denial.
     */
    public function disableBusinessFeature(Business $business, PlatformFeature $feature, int $actorUserId, ?string $reason = null): BusinessFeatureToggle
    {
        $this->assertFeatureIsBusinessScoped($feature);

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

    /**
     * Correction 2 — assertFeatureIsBusinessScoped() rejects a
     * Workspace-scoped feature before any lock or repository read, so a
     * stale/manually-seeded business_feature_toggles row for one (which
     * should never exist, but this method must not assume that) can
     * never be deleted through this path either.
     */
    public function enableBusinessFeature(Business $business, PlatformFeature $feature, int $actorUserId): void
    {
        $this->assertFeatureIsBusinessScoped($feature);

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
     * The entire authority-check-through-update-through-audit-write
     * sequence is one real transaction: findForUpdate()'s SELECT ... FOR
     * UPDATE only holds its row lock across subsequent statements while an
     * ambient transaction is open — executed outside one, MySQL's
     * autocommit mode releases the lock the instant that single SELECT
     * completes, before the non-complimentary-reference check, the update,
     * or the audit-row write ever runs (RFC-004 Amendment 2 §7).
     */
    public function updateCatalogPricing(
        WorkspacePlanCatalog $catalog,
        ?string $price,
        ?int $currencyId,
        ?string $additionalBusinessSlotPriceRatio,
        int $actorUserId,
        string $reason,
    ): WorkspacePlanCatalog {
        return DB::transaction(function () use ($catalog, $price, $currencyId, $additionalBusinessSlotPriceRatio, $actorUserId, $reason) {
            $this->assertPlatformAdministrator($actorUserId);

            if (trim($reason) === '') {
                throw new InvalidArgumentException('Workspace plan catalog pricing changes require a non-empty reason.');
            }

            $lockedCatalog = $this->catalogRepository->findForUpdate($catalog->id);

            if ($lockedCatalog === null) {
                throw new RuntimeException("Workspace plan catalog [{$catalog->id}] does not exist.");
            }

            $normalizedPrice = $price === null ? null : $this->normalizePrice($price);

            if (($normalizedPrice === null) !== ($currencyId === null)) {
                throw new InvalidArgumentException('Workspace plan catalog price and currency_id must both be null or both be populated.');
            }

            if ($currencyId !== null) {
                $this->assertCurrencyExistsAndActive($currencyId);
            }

            if ($normalizedPrice === null && $this->assignmentRepository->hasNonComplimentaryForCatalogForUpdate($lockedCatalog->id)) {
                throw new PlanCatalogPricingInUseException($lockedCatalog->id);
            }

            $normalizedRatio = $additionalBusinessSlotPriceRatio === null ? null : $this->normalizeRatio($additionalBusinessSlotPriceRatio);

            if ($normalizedRatio !== null && $lockedCatalog->tier === WorkspacePlanTier::Agency) {
                throw new InvalidArgumentException("Workspace plan catalog tier [{$lockedCatalog->tier->value}] does not support an additional-Business-slot price ratio.");
            }

            // Customer Experience Slice 1A (RFC-004 §33.2): a price ratio for
            // an extra Business is meaningless — and must never become
            // purchasable — on a row that offers no additional Business
            // capacity (business_slot_max <= business_slot_included), which
            // is exactly the corrected Core and Growth. Read from the row, not
            // the tier name, and refused before any update, audit row or event.
            if ($normalizedRatio !== null && $this->additionalBusinessSlotCapacity($lockedCatalog) === 0) {
                throw new InvalidArgumentException("Workspace plan catalog tier [{$lockedCatalog->tier->value}] offers no additional Business capacity, so it cannot carry an additional-Business-slot price ratio.");
            }

            $fromPrice = $lockedCatalog->price;
            $fromCurrencyId = $lockedCatalog->currency_id;
            $fromRatio = $lockedCatalog->additional_business_slot_price_ratio;

            $updated = $this->catalogRepository->update($lockedCatalog, [
                'price' => $normalizedPrice,
                'currency_id' => $currencyId,
                'additional_business_slot_price_ratio' => $normalizedRatio,
            ]);

            $this->pricingChangeRepository->create([
                'workspace_plan_catalog_id' => $lockedCatalog->id,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'from_price' => $fromPrice,
                'to_price' => $normalizedPrice,
                'from_currency_id' => $fromCurrencyId,
                'to_currency_id' => $currencyId,
                'from_additional_business_slot_price_ratio' => $fromRatio,
                'to_additional_business_slot_price_ratio' => $normalizedRatio,
            ]);

            WorkspacePlanCatalogPricingChanged::dispatch($lockedCatalog->id, $actorUserId);

            return $updated;
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

    /**
     * Exact DECIMAL(6,4) decimal-string validation/normalization (RFC-004
     * Amendment 2 §6) — never casts through a PHP float anywhere in this
     * path, mirroring normalizePrice()'s identical discipline. Enforces
     * only DECIMAL(6,4)'s own storage boundary (2 integer digits, 4
     * fractional digits) — no additional, narrower commercial-policy upper
     * bound is introduced here (Amendment 2 §6, locked).
     */
    private function normalizeRatio(string $ratio): string
    {
        if (! preg_match('/^\d+(\.\d{1,4})?$/', $ratio)) {
            throw new InvalidArgumentException("Workspace plan catalog additional-Business-slot price ratio [{$ratio}] is not a valid non-negative decimal string.");
        }

        $parts = explode('.', $ratio, 2);
        $integerPart = ltrim($parts[0], '0');
        $integerPart = $integerPart === '' ? '0' : $integerPart;

        if (strlen($integerPart) > 2) {
            throw new InvalidArgumentException("Workspace plan catalog additional-Business-slot price ratio [{$ratio}] exceeds the maximum precision of DECIMAL(6,4).");
        }

        $fractionalPart = $parts[1] ?? '';
        $fractionalPart = match (strlen($fractionalPart)) {
            0 => '0000',
            1 => $fractionalPart . '000',
            2 => $fractionalPart . '00',
            3 => $fractionalPart . '0',
            default => $fractionalPart,
        };

        return "{$integerPart}.{$fractionalPart}";
    }

    /**
     * RFC-004 Amendment 2 §6 — a supplied currency_id must reference an
     * existing, active currency. Reuses the existing, unmodified
     * CurrencyRepository via its query() builder, the identical pattern
     * assertPlatformAdministrator() already uses against userRepository.
     */
    private function assertCurrencyExistsAndActive(int $currencyId): void
    {
        $exists = $this->currencyRepository->query()->whereKey($currencyId)->where('status', true)->exists();

        if (! $exists) {
            throw new InvalidArgumentException("Currency [{$currencyId}] does not exist or is not active.");
        }
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
        // Implementation Contract 21 §7 — a confirmed, durable lane-A
        // subscription is its own authority. The flag is set only by the
        // narrow wrappers above, which validate their evidence first and clear
        // it in a `finally`; it is never reachable from customer input.
        if ($this->verifiedSubscriptionProvenance) {
            return;
        }

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

    /**
     * Customer Experience Slice 1A (RFC-004 §33.2): how many additional
     * Business slots a catalog row offers is read from the row itself —
     * business_slot_max − business_slot_included — never from a hard-coded
     * tier rule. The corrected Core/Growth rows (1/1) therefore offer none,
     * an unlimited row (Agency) has no additional-slot concept at all, and a
     * bounded row with no maximum fails closed at none.
     */
    private function additionalBusinessSlotCapacity(?WorkspacePlanCatalog $catalog): int
    {
        if ($catalog === null || $catalog->unlimited_business_slots) {
            return 0;
        }

        $included = (int) $catalog->business_slot_included;

        return max(0, (int) ($catalog->business_slot_max ?? $included) - $included);
    }

    /**
     * An INCREASE is valid only up to what the catalog row offers
     * (additionalBusinessSlotCapacity()). A reduction — or keeping a value
     * unchanged, e.g. a Core<->Growth change preserving it — is always
     * valid, so a stale counter left from the superseded 3/5 catalog can be
     * brought down to zero but never raised. Agency keeps its exact rule:
     * only 0 is valid.
     */
    private function assertValidAdditionalBusinessSlots(?WorkspacePlanCatalog $catalog, WorkspacePlanTier $tier, int $toSlots, int $fromSlots): void
    {
        if ($toSlots < 0) {
            throw new InvalidAdditionalBusinessSlotsException($tier->value, $toSlots);
        }

        if ($tier === WorkspacePlanTier::Agency || (bool) $catalog?->unlimited_business_slots) {
            if ($toSlots !== 0) {
                throw new InvalidAdditionalBusinessSlotsException($tier->value, $toSlots);
            }

            return;
        }

        if ($toSlots > $fromSlots && $toSlots > $this->additionalBusinessSlotCapacity($catalog)) {
            throw new InvalidAdditionalBusinessSlotsException($tier->value, $toSlots);
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
