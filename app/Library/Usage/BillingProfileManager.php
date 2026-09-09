<?php

namespace App\Library\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Usage\BusinessBillingContactChanged;
use App\Events\Usage\BusinessPayerChanged;
use App\Exceptions\Usage\InvalidBillingContactDataException;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessBillingContact;
use App\Models\BusinessPayerAssignment;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use App\Repositories\Contracts\BusinessPayerTransitionRepository;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * RFC-005 §16/§17.A, M2 contract §6.E/§6.F/§10 — sole write authority for
 * business_billing_contacts, business_payer_assignments, and
 * business_payer_transitions. No credit/instrument/charge method exists
 * here — those are M3+ scope. No raw workspace_plan_catalog/
 * workspace_plan_assignments query anywhere in this class — the default
 * payer resolution goes exclusively through
 * EntitlementManager::getWorkspaceEntitlementSummary() (M2 contract audit
 * item 6).
 */
class BillingProfileManager
{
    public function __construct(
        private readonly BusinessPayerAssignmentRepository $payerAssignmentRepository,
        private readonly BusinessPayerTransitionRepository $payerTransitionRepository,
        private readonly BusinessBillingContactRepository $billingContactRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinessRepository,
    ) {
    }

    /**
     * Idempotent — a Business that already has a payer assignment is a
     * no-op. Default resolved exclusively via
     * EntitlementManager::getWorkspaceEntitlementSummary(): Agency tier ->
     * business; Core/Growth, or no plan assigned yet, -> workspace (M2
     * contract §6.E). effective_payment_instrument_id always starts null
     * — no instrument exists until M3.
     */
    public function initializePayerAssignmentForBusiness(int $businessId): void
    {
        if ($this->payerAssignmentRepository->findByBusinessId($businessId) !== null) {
            return;
        }

        $business = Business::query()->findOrFail($businessId);
        $business->loadMissing('workspace');

        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($business->workspace);
        $payerType = $summary->tier === WorkspacePlanTier::Agency ? PayerType::Business : PayerType::Workspace;

        try {
            DB::transaction(function () use ($businessId, $payerType) {
                $this->payerAssignmentRepository->create([
                    'business_id' => $businessId,
                    'payer_type' => $payerType->value,
                    'effective_payment_instrument_id' => null,
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateRace($e, 'business_payer_assignments_business_id_unique')) {
                return;
            }

            throw $e;
        }
    }

    /**
     * Explicit reassignment — Customer Experience Slice 5 (contract §12.4,
     * §18 S-7, §19; E-12 correction).
     *
     * Authority: billing responsibility is managed by the Agency — the
     * Workspace owner or an active Agency-wide (all-Business) Admin — and
     * only inside an Agency-tier Workspace. A Business user can never set
     * or transfer responsibility, and a Core/Growth Workspace (one
     * Business, always Workspace-paid, no selector) refuses every request.
     *
     * True no-op: submitting the currently-assigned payer performs no
     * update, writes no business_payer_transitions row, dispatches no
     * BusinessPayerChanged event and returns changed=false — the row's
     * updated_at is byte-for-byte unchanged. A real change is serialized
     * on the assignment row lock, audited and idempotent.
     *
     * @return array{assignment: BusinessPayerAssignment, changed: bool, from: PayerType, to: PayerType}
     */
    public function assignPayer(Business $business, PayerType $payerType, int $actorUserId, string $reason): array
    {
        return DB::transaction(function () use ($business, $payerType, $actorUserId, $reason) {
            if (! in_array($payerType, [PayerType::Business, PayerType::Workspace], true)) {
                throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
            }

            $assignment = $this->payerAssignmentRepository->findForUpdateByBusinessId((int) $business->id);

            if ($assignment === null) {
                // Self-healing: every Business is expected to already have
                // an assignment via the listener/backfill (RFC-005 §32),
                // but a genuine explicit reassignment request must never
                // fail merely because that lazy initialization has not yet
                // run for this Business.
                $this->initializePayerAssignmentForBusiness((int) $business->id);
                $assignment = $this->payerAssignmentRepository->findForUpdateByBusinessId((int) $business->id);
            }

            $fromPayerType = $assignment->payer_type;

            if ($fromPayerType === $payerType) {
                // A reaffirmation of the current responsibility: accepted
                // from the Agency manager or from the current payer
                // themself (the Workspace owner while the Workspace pays,
                // the direct Business owner while the Business pays), and
                // refused for everyone else. It writes nothing either way.
                if (! $this->isAgencyWideManager($business, $actorUserId) && ! $this->isCurrentPayer($business, $fromPayerType, $actorUserId)) {
                    throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
                }

                return ['assignment' => $assignment, 'changed' => false, 'from' => $fromPayerType, 'to' => $payerType];
            }

            $this->assertBillingResponsibilityAuthority($business, $payerType, $actorUserId);

            $this->payerTransitionRepository->create([
                'business_id' => $business->id,
                'from_payer_type' => $fromPayerType->value,
                'to_payer_type' => $payerType->value,
                'from_instrument_id' => null,
                'to_instrument_id' => null,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $updated = $this->payerAssignmentRepository->update($assignment, [
                'payer_type' => $payerType->value,
            ]);

            BusinessPayerChanged::dispatch((int) $business->id, $fromPayerType->value, $payerType->value, $actorUserId);

            return ['assignment' => $updated, 'changed' => true, 'from' => $fromPayerType, 'to' => $payerType];
        });
    }

    /**
     * Kept for existing callers: the same authorized, audited, no-op-safe
     * assignment, returning only the assignment row.
     */
    public function changePayer(Business $business, PayerType $payerType, int $actorUserId, string $reason): BusinessPayerAssignment
    {
        return $this->assignPayer($business, $payerType, $actorUserId, $reason)['assignment'];
    }

    /**
     * Customer Experience Slice 5 (contract §12.4; T-PAYER-1..4) — the
     * presentation facts the Usage & Billing page needs about billing
     * responsibility, computed server-side from the authoritative tier,
     * payer assignment and actor. Never a raw model.
     *
     * Correction Round 1 §9 — actor_manages_limits is the payer-side
     * financial-control authority (actorManagesPayerControls()), never the
     * generic billing-management authority; that one is exposed separately
     * as actor_manages_billing_contact for the billing contact card only.
     *
     * @return array{tier: ?string, payer_type: string, is_agency: bool, agency_paid: bool, actor_is_payer: bool, actor_manages_responsibility: bool, actor_manages_limits: bool, actor_manages_billing_contact: bool, actor_is_workspace_owner: bool}
     */
    public function billingResponsibilityFor(Business $business, int $actorUserId): array
    {
        $business->loadMissing('workspace');

        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($business->workspace);
        $isAgency = $summary->tier === WorkspacePlanTier::Agency;

        $assignment = $this->payerAssignmentRepository->findByBusinessId((int) $business->id);
        $payerType = $assignment?->payer_type ?? PayerType::Workspace;

        $isWorkspaceOwner = (int) $business->workspace->owner_user_id === $actorUserId;
        $isDirectOwner = (int) $business->customer_id === $actorUserId;

        $actorIsPayer = $payerType === PayerType::Workspace ? $isWorkspaceOwner : $isDirectOwner;

        return [
            'tier' => $summary->tier?->value,
            'payer_type' => $payerType->value,
            'is_agency' => $isAgency,
            'agency_paid' => $isAgency && $payerType === PayerType::Workspace,
            'actor_is_payer' => $actorIsPayer,
            'actor_manages_responsibility' => $isAgency && $this->isAgencyWideManager($business, $actorUserId),
            'actor_manages_limits' => $this->actorManagesPayerControls($business, $actorUserId),
            'actor_manages_billing_contact' => $this->canManageBusinessUsageBilling($business, $actorUserId),
            'actor_is_workspace_owner' => $isWorkspaceOwner,
        ];
    }

    /**
     * The one payer-authority rule of contract §12.4: Agency owner/admin
     * (Agency-wide), inside an Agency-tier Workspace, for either target.
     * Everyone else — Business users, scoped Admins, Staff, strangers, and
     * every Core/Growth actor — is refused.
     */
    private function assertBillingResponsibilityAuthority(Business $business, PayerType $payerType, int $actorUserId): void
    {
        $business->loadMissing('workspace');

        if (! in_array($payerType, [PayerType::Business, PayerType::Workspace], true)) {
            throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
        }

        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($business->workspace);

        if ($summary->tier !== WorkspacePlanTier::Agency) {
            throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
        }

        if (! $this->isAgencyWideManager($business, $actorUserId)) {
            throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
        }
    }

    private function isCurrentPayer(Business $business, PayerType $currentPayerType, int $actorUserId): bool
    {
        $business->loadMissing('workspace');

        return $currentPayerType === PayerType::Workspace
            ? (int) $business->workspace->owner_user_id === $actorUserId
            : (int) $business->customer_id === $actorUserId;
    }

    private function isAgencyWideManager(Business $business, int $actorUserId): bool
    {
        $business->loadMissing('workspace');

        if ((int) $business->workspace->owner_user_id === $actorUserId) {
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($business->workspace, $actorUserId);

        return $membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin
            && $membership->business_access_scope === WorkspaceBusinessAccessScope::All;
    }

    /**
     * Customer Experience Slice 5, Correction Round 1 §9 — the one
     * financial-control authority matrix. Payer-owned controls (adding
     * funds is separately consent-gated by RFC-005 §16; here: automatic
     * top-up configuration limits, the Business spending limit, capability
     * limits, pause/resume of paid activity) belong to the payer side:
     *
     *   - while the Workspace pays: the Workspace owner, or an active
     *     Agency-wide Admin (business_access_scope = all). A directly
     *     owned client Business's own user is NOT the payer side and is
     *     refused, even though M2 lets them edit the billing contact;
     *   - while the Business pays: the direct Business owner only. The
     *     Agency owner/Admin changes billing responsibility and views the
     *     summary, but never alters the client payer's controls.
     *
     * Staff, selected-scope Admins, inactive members and strangers are
     * always refused. Generic permission to edit Business billing data
     * (assertCanManageBusinessUsageBilling()) never implies any of this.
     */
    public function actorManagesPayerControls(Business $business, int $actorUserId): bool
    {
        $business->loadMissing('workspace');

        $assignment = $this->payerAssignmentRepository->findByBusinessId((int) $business->id);
        $payerType = $assignment?->payer_type ?? PayerType::Workspace;

        if ($payerType === PayerType::Business) {
            return (int) $business->customer_id === $actorUserId;
        }

        return $this->isAgencyWideManager($business, $actorUserId);
    }

    public function assertActorManagesPayerControls(Business $business, int $actorUserId): void
    {
        if (! $this->actorManagesPayerControls($business, $actorUserId)) {
            throw new UnauthorizedUsageBillingManagementException($actorUserId, (int) $business->id);
        }
    }

    private function canManageBusinessUsageBilling(Business $business, int $actorUserId): bool
    {
        try {
            $this->assertCanManageBusinessUsageBilling($business, $actorUserId);

            return true;
        } catch (UnauthorizedUsageBillingManagementException) {
            return false;
        }
    }

    /**
     * Created lazily on first write — no listener/backfill (M2 contract
     * §6.F). If contact_user_id is null, contact_name and contact_email
     * must both be supplied (manager-enforced, defense in depth alongside
     * the FormRequest's own validation).
     */
    public function updateBillingContact(
        Business $business,
        ?int $contactUserId,
        ?string $contactName,
        ?string $contactEmail,
        bool $notificationOptIn,
        int $actorUserId,
    ): BusinessBillingContact {
        if ($contactUserId === null && ($contactName === null || $contactName === '' || $contactEmail === null || $contactEmail === '')) {
            throw new InvalidBillingContactDataException((int) $business->id);
        }

        return DB::transaction(function () use ($business, $contactUserId, $contactName, $contactEmail, $notificationOptIn, $actorUserId) {
            $this->assertCanManageBusinessUsageBilling($business, $actorUserId);

            $attributes = [
                'business_id' => $business->id,
                'contact_user_id' => $contactUserId,
                'contact_name' => $contactUserId === null ? $contactName : null,
                'contact_email' => $contactUserId === null ? $contactEmail : null,
                'notification_opt_in' => $notificationOptIn,
                'updated_by_user_id' => $actorUserId,
            ];

            $existing = $this->billingContactRepository->findByBusinessId((int) $business->id);

            if ($existing === null) {
                $contact = $this->billingContactRepository->create($attributes);
            } else {
                unset($attributes['business_id']);
                $contact = $this->billingContactRepository->update($existing, $attributes);
            }

            BusinessBillingContactChanged::dispatch((int) $business->id, $actorUserId);

            return $contact;
        });
    }

    /**
     * M2 contract §7 non-payer mutation authority: Workspace owner,
     * active Admin whose business_access_scope covers this Business, or
     * the direct Business owner/customer. Staff is never authorized to
     * mutate, even with matching scope.
     */
    private function assertCanManageBusinessUsageBilling(Business $business, int $actorUserId): void
    {
        $business->loadMissing('workspace');

        if ((int) $business->customer_id === $actorUserId) {
            return;
        }

        if ((int) $business->workspace->owner_user_id === $actorUserId) {
            return;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($business->workspace, $actorUserId);

        if ($membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin
            && (
                $membership->business_access_scope === WorkspaceBusinessAccessScope::All
                || $this->membershipBusinessRepository->isAssigned($membership, (int) $business->id)
            )
        ) {
            return;
        }

        throw new UnauthorizedUsageBillingManagementException($actorUserId, (int) $business->id);
    }

    private function isDuplicateRace(QueryException $e, string $constraintName): bool
    {
        $driverErrorCode = (int) ($e->errorInfo[1] ?? 0);

        return $driverErrorCode === 1062 && str_contains($e->getMessage(), $constraintName);
    }
}
