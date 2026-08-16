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
     * Explicit reassignment. Consent-gated (M2 contract §7): only the
     * Workspace owner may set 'workspace'; only the direct Business
     * owner/customer may set 'business'; neither side may set the other's.
     * A repeated call with the same target payer_type still records a
     * transition row (M2 contract §6.E's own "reaffirmation" resolution).
     * Never touches effective_payment_instrument_id (always null at M2).
     */
    public function changePayer(Business $business, PayerType $payerType, int $actorUserId, string $reason): BusinessPayerAssignment
    {
        return DB::transaction(function () use ($business, $payerType, $actorUserId, $reason) {
            $this->assertPayerConsent($business, $payerType, $actorUserId);

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

            return $updated;
        });
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
     * M2 contract §7 payer-consent matrix: 'workspace' requires the
     * Workspace owner; 'business' requires the direct Business
     * owner/customer; Active Admin and Staff can never change payer;
     * agency_rebill is never a valid target at M2.
     */
    private function assertPayerConsent(Business $business, PayerType $payerType, int $actorUserId): void
    {
        $business->loadMissing('workspace');

        if ($payerType === PayerType::Workspace) {
            if ((int) $business->workspace->owner_user_id === $actorUserId) {
                return;
            }

            throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
        }

        if ($payerType === PayerType::Business) {
            if ((int) $business->customer_id === $actorUserId) {
                return;
            }

            throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
        }

        throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
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
