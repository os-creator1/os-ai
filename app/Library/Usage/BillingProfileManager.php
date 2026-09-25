<?php

namespace App\Library\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Usage\BusinessBillingContactChanged;
use App\Events\Usage\BusinessPayerChanged;
use App\Exceptions\Usage\AgencyRebillRelationshipInvalidException;
use App\Exceptions\Usage\InvalidBillingContactDataException;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessBillingContact;
use App\Models\BusinessPayerAssignment;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use App\Repositories\Contracts\BusinessPayerTransitionRepository;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
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
 *
 * Implementation Contract 09 §6.2 — also the single HUMAN payer/funding
 * authority seam: who may assign or consent to a payer, configure funding
 * (payment instruments, automatic top-up), originate a charge, or control
 * the payer-side spend controls. UsageBillingCheckoutManager,
 * PaymentInstrumentManager and UsageWalletManager ask this class and keep no
 * authority algorithm of their own. WHO pays is EffectivePayerResolver's;
 * this class never re-derives a provider customer from payer_type.
 */
class BillingProfileManager
{
    /** business_payer_transitions.agency_rebill_consent values (Contract 09 §5.1). */
    public const AGENCY_REBILL_CONSENT_GRANTED = 'granted';

    public const AGENCY_REBILL_CONSENT_REVOKED = 'revoked';

    public function __construct(
        private readonly BusinessPayerAssignmentRepository $payerAssignmentRepository,
        private readonly BusinessPayerTransitionRepository $payerTransitionRepository,
        private readonly BusinessBillingContactRepository $billingContactRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinessRepository,
        private readonly EffectivePayerResolver $effectivePayerResolver,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
        private readonly WorkspaceRepository $workspaceRepository,
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
     * Contract 09 §6.2 — PayerType::AgencyRebill is accepted, routed through
     * assignAgencyRebill(): only the managing Agency Workspace owner, and the
     * write is also the grant of standing consent. Switching AWAY from
     * AgencyRebill uses the unchanged Business/Workspace rule below and
     * clears every AgencyRebill column.
     *
     * @return array{assignment: BusinessPayerAssignment, changed: bool, from: PayerType, to: PayerType}
     */
    public function assignPayer(Business $business, PayerType $payerType, int $actorUserId, string $reason): array
    {
        return DB::transaction(function () use ($business, $payerType, $actorUserId, $reason) {
            if (! in_array($payerType, [PayerType::Business, PayerType::Workspace, PayerType::AgencyRebill], true)) {
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

            if ($payerType === PayerType::AgencyRebill) {
                return $this->assignAgencyRebill($business, $assignment, $actorUserId, $reason);
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
                // Leaving AgencyRebill: the audit keeps which relationship
                // stopped funding this Business.
                'managing_agency_relationship_id' => $fromPayerType === PayerType::AgencyRebill ? $assignment->managing_agency_relationship_id : null,
                'agency_rebill_consent' => null,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            // Contract 09 §5.1 — the AgencyRebill columns are NULL for every
            // other payer type, so a switch away clears them in the same write.
            $updated = $this->payerAssignmentRepository->update($assignment, [
                'payer_type' => $payerType->value,
                'managing_agency_relationship_id' => null,
                'agency_rebill_consented_at' => null,
                'agency_rebill_consented_by_user_id' => null,
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
     * Contract 09 §6.2 — the managing Agency Workspace owner withdraws its
     * standing consent. The payer stays agency_rebill and the relationship
     * stays recorded: nothing silently falls back to a client payer, and
     * every NEW Agency-funded paid effect is refused from this commit on
     * (EffectivePayerResolver::paidEffectRefusal()). History is untouched.
     * Revoking consent that is already absent is an authorized no-op.
     *
     * @return array{assignment: BusinessPayerAssignment, changed: bool}
     *
     * @throws UnauthorizedPayerAssignmentException for anyone other than the
     *         owner of the recorded, still-valid managing Agency Workspace
     */
    public function revokeAgencyRebillConsent(Business $business, int $actorUserId, string $reason): array
    {
        return DB::transaction(function () use ($business, $actorUserId, $reason) {
            $assignment = $this->payerAssignmentRepository->findForUpdateByBusinessId((int) $business->id);

            if ($assignment === null || $assignment->payer_type !== PayerType::AgencyRebill) {
                throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, PayerType::AgencyRebill->value);
            }

            // Locking re-resolution: the recorded relationship must still be
            // Active and target this Business while its owner revokes.
            $payer = $this->resolvePayerOrNull($business, forPaidEffect: true);

            if ($payer === null || ! $this->ownsPayingAgencyWorkspace($payer, $actorUserId)) {
                throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, PayerType::AgencyRebill->value);
            }

            if ($assignment->agency_rebill_consented_at === null) {
                return ['assignment' => $assignment, 'changed' => false];
            }

            $this->payerTransitionRepository->create([
                'business_id' => $business->id,
                'from_payer_type' => PayerType::AgencyRebill->value,
                'to_payer_type' => PayerType::AgencyRebill->value,
                'from_instrument_id' => null,
                'to_instrument_id' => null,
                'managing_agency_relationship_id' => $payer->managingAgencyRelationshipId,
                'agency_rebill_consent' => self::AGENCY_REBILL_CONSENT_REVOKED,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $updated = $this->payerAssignmentRepository->update($assignment, [
                'agency_rebill_consented_at' => null,
                'agency_rebill_consented_by_user_id' => null,
            ]);

            return ['assignment' => $updated, 'changed' => true];
        });
    }

    /**
     * Contract 09 §6.2 — funding-configuration authority: setting up,
     * attaching, detaching or defaulting a payment instrument, and
     * configuring automatic top-up. Returns the resolved payer the actor may
     * configure, or null when they may not.
     *
     *   Workspace payer:    the Workspace owner (unchanged)
     *   Business payer:     the direct Business owner (unchanged)
     *   AgencyRebill payer: the managing Agency Workspace owner only — with
     *                       NO standing-consent requirement, so the Agency can
     *                       configure its funding instrument before any
     *                       charge; and never blocked by locked account access,
     *                       since configuration is not a paid effect.
     *
     * An AgencyRebill payer whose relationship no longer proves who pays is
     * configurable by nobody (fail closed).
     */
    public function authorizedFundingPayer(Business $business, int $actorUserId): ?EffectivePayer
    {
        $payer = $this->resolvePayerOrNull($business);

        return $payer !== null && $this->actorMayConfigureFundingFor($business, $payer, $actorUserId) ? $payer : null;
    }

    /**
     * Contract 09 §6.2 — charge-origination authority (manual top-up, add-on
     * purchase): funding authority plus, for AgencyRebill, standing consent.
     * RFC-005 §16: no platform-administrator override for origination.
     */
    public function authorizedChargePayer(Business $business, int $actorUserId): ?EffectivePayer
    {
        $payer = $this->resolvePayerOrNull($business);

        return $payer !== null && $this->actorMayOriginateChargeFor($business, $payer, $actorUserId) ? $payer : null;
    }

    /**
     * The funding-configuration rule, evaluated against an ALREADY-resolved
     * payer (so a caller holding a locked resolution can re-check it without
     * resolving again).
     */
    public function actorMayConfigureFundingFor(Business $business, EffectivePayer $payer, int $actorUserId): bool
    {
        $business->loadMissing('workspace');

        return match ($payer->payerType) {
            PayerType::Workspace => (int) $business->workspace->owner_user_id === $actorUserId,
            PayerType::Business => (int) $business->customer_id === $actorUserId,
            PayerType::AgencyRebill => $this->ownsPayingAgencyWorkspace($payer, $actorUserId),
        };
    }

    /** The charge-origination rule, against an already-resolved payer. */
    public function actorMayOriginateChargeFor(Business $business, EffectivePayer $payer, int $actorUserId): bool
    {
        if (! $this->actorMayConfigureFundingFor($business, $payer, $actorUserId)) {
            return false;
        }

        return $payer->payerType !== PayerType::AgencyRebill || $payer->hasAgencyRebillStandingConsent();
    }

    /**
     * @throws UnauthorizedPayerAssignmentException
     */
    public function assertAuthorizedFundingPayer(Business $business, int $actorUserId): EffectivePayer
    {
        return $this->authorizedFundingPayer($business, $actorUserId)
            ?? throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $this->effectivePayerResolver->payerTypeOf($business)->value);
    }

    /**
     * @throws UnauthorizedPayerAssignmentException
     */
    public function assertAuthorizedChargePayer(Business $business, int $actorUserId): EffectivePayer
    {
        return $this->authorizedChargePayer($business, $actorUserId)
            ?? throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $this->effectivePayerResolver->payerTypeOf($business)->value);
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
     * Contract 09 — tri-state: an AgencyRebill Business is agency-paid (by its
     * managing Agency), its payer is that Agency's owner, and who_pays says so
     * — it is never presented as client-paid.
     *
     * Contract 09 §6.2/§7 correction — agency_rebill_consented_at surfaces
     * the standing-consent timestamp alongside payer_type so a caller can
     * tell "consent granted and active" apart from "payer_type is still
     * agency_rebill but the owner revoked consent" (revokeAgencyRebillConsent()
     * never falls back to another payer, so payer_type alone is ambiguous).
     * Always null when payer_type is not agency_rebill.
     *
     * @return array{tier: ?string, payer_type: string, is_agency: bool, agency_paid: bool, who_pays: string, actor_is_payer: bool, actor_manages_responsibility: bool, actor_manages_limits: bool, actor_manages_billing_contact: bool, actor_is_workspace_owner: bool, agency_rebill_consented_at: ?\Illuminate\Support\Carbon}
     */
    public function billingResponsibilityFor(Business $business, int $actorUserId): array
    {
        $business->loadMissing('workspace');

        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($business->workspace);
        $isAgency = $summary->tier === WorkspacePlanTier::Agency;

        $payerType = $this->effectivePayerResolver->payerTypeOf($business);

        $isWorkspaceOwner = (int) $business->workspace->owner_user_id === $actorUserId;

        $assignment = $payerType === PayerType::AgencyRebill
            ? $this->payerAssignmentRepository->findByBusinessId((int) $business->id)
            : null;

        return [
            'tier' => $summary->tier?->value,
            'payer_type' => $payerType->value,
            'is_agency' => $isAgency,
            'agency_paid' => match ($payerType) {
                PayerType::Workspace => $isAgency,
                PayerType::AgencyRebill => true,
                PayerType::Business => false,
            },
            'who_pays' => match ($payerType) {
                PayerType::Workspace, PayerType::AgencyRebill => 'agency',
                PayerType::Business => 'business',
            },
            'actor_is_payer' => $this->isCurrentPayer($business, $payerType, $actorUserId),
            'actor_manages_responsibility' => $isAgency && $this->isAgencyWideManager($business, $actorUserId),
            'actor_manages_limits' => $this->actorManagesPayerControls($business, $actorUserId),
            'actor_manages_billing_contact' => $this->canManageBusinessUsageBilling($business, $actorUserId),
            'actor_is_workspace_owner' => $isWorkspaceOwner,
            'agency_rebill_consented_at' => $assignment?->agency_rebill_consented_at,
        ];
    }

    /**
     * The one payer-authority rule of contract §12.4: Agency owner/admin
     * (Agency-wide), inside an Agency-tier Workspace, for either target.
     * Everyone else — Business users, scoped Admins, Staff, strangers, and
     * every Core/Growth actor — is refused.
     *
     * Contract 09 §6 — AgencyRebill is decided first and separately: the
     * managing Agency Workspace owner, resolved through the relationship
     * before ownership is checked. The Business/Workspace branch below is
     * unchanged.
     */
    private function assertBillingResponsibilityAuthority(Business $business, PayerType $payerType, int $actorUserId): void
    {
        $business->loadMissing('workspace');

        if ($payerType === PayerType::AgencyRebill) {
            if (! $this->isManagingAgencyOwner($business, $actorUserId)) {
                throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
            }

            return;
        }

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

    /**
     * Contract 09 §6.2/§7 — the AgencyRebill assignment, which is also the
     * standing-consent grant. Runs inside assignPayer()'s transaction, with
     * the assignment row already locked.
     *
     * @return array{assignment: BusinessPayerAssignment, changed: bool, from: PayerType, to: PayerType}
     */
    private function assignAgencyRebill(Business $business, BusinessPayerAssignment $assignment, int $actorUserId, string $reason): array
    {
        $this->assertBillingResponsibilityAuthority($business, PayerType::AgencyRebill, $actorUserId);

        // §7 — re-verify under a locking read immediately before writing: a
        // relationship terminated (or replaced) between the authority check
        // and this point must never be recorded as the payer.
        $relationship = $this->relationshipRepository->findActiveForClientWorkspaceForUpdate((int) $business->workspace_id);

        if ($relationship === null
            || (int) $relationship->agency_workspace_id === (int) $business->workspace_id
            || ! $this->isOwnerOfWorkspace((int) $relationship->agency_workspace_id, $actorUserId)) {
            throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, PayerType::AgencyRebill->value);
        }

        $fromPayerType = $assignment->payer_type;
        $relationshipChanged = (int) $assignment->managing_agency_relationship_id !== (int) $relationship->id;

        if ($fromPayerType === PayerType::AgencyRebill && ! $relationshipChanged && $assignment->agency_rebill_consented_at !== null) {
            return ['assignment' => $assignment, 'changed' => false, 'from' => $fromPayerType, 'to' => PayerType::AgencyRebill];
        }

        $this->payerTransitionRepository->create([
            'business_id' => $business->id,
            'from_payer_type' => $fromPayerType->value,
            'to_payer_type' => PayerType::AgencyRebill->value,
            'from_instrument_id' => null,
            'to_instrument_id' => null,
            'managing_agency_relationship_id' => $relationship->id,
            'agency_rebill_consent' => self::AGENCY_REBILL_CONSENT_GRANTED,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        $updated = $this->payerAssignmentRepository->update($assignment, [
            'payer_type' => PayerType::AgencyRebill->value,
            'managing_agency_relationship_id' => $relationship->id,
            'agency_rebill_consented_at' => now(),
            'agency_rebill_consented_by_user_id' => $actorUserId,
        ]);

        // The funding source changed (a new payer type or a new managing
        // Agency). Re-granting consent for the same relationship is audited
        // above but is not a payer change.
        if ($fromPayerType !== PayerType::AgencyRebill || $relationshipChanged) {
            BusinessPayerChanged::dispatch((int) $business->id, $fromPayerType->value, PayerType::AgencyRebill->value, $actorUserId);
        }

        return ['assignment' => $updated, 'changed' => true, 'from' => $fromPayerType, 'to' => PayerType::AgencyRebill];
    }

    private function isCurrentPayer(Business $business, PayerType $currentPayerType, int $actorUserId): bool
    {
        $business->loadMissing('workspace');

        return match ($currentPayerType) {
            PayerType::Workspace => (int) $business->workspace->owner_user_id === $actorUserId,
            PayerType::Business => (int) $business->customer_id === $actorUserId,
            PayerType::AgencyRebill => ($payer = $this->resolvePayerOrNull($business)) !== null
                && $this->ownsPayingAgencyWorkspace($payer, $actorUserId),
        };
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
     * Contract 09 §6.2 — the one new authority check, used ONLY for granting
     * AgencyRebill. isAgencyWideManager() cannot express it: it checks the
     * Business's own Workspace, which under V1 is the Client Workspace.
     *
     *   1. the Active Contract 01 relationship for this Business's own
     *      Workspace — none means a plain refusal, disclosing nothing;
     *   2. only then: the actor owns that relationship's Agency Workspace
     *      (owner only — no Admin/Staff bypass, no platform override);
     *   3. that Agency Workspace is still Agency-tier (Contract 01 requires
     *      every Agency-only capability to re-check current entitlement).
     */
    private function isManagingAgencyOwner(Business $business, int $actorUserId): bool
    {
        $relationship = $this->relationshipRepository->findActiveForClientWorkspace((int) $business->workspace_id);

        if ($relationship === null || (int) $relationship->agency_workspace_id === (int) $business->workspace_id) {
            return false;
        }

        $agencyWorkspace = $this->workspaceRepository->findById((int) $relationship->agency_workspace_id);

        if ($agencyWorkspace === null || (int) $agencyWorkspace->owner_user_id !== $actorUserId) {
            return false;
        }

        return $this->entitlementManager->getWorkspaceEntitlementSummary($agencyWorkspace)->tier === WorkspacePlanTier::Agency;
    }

    /**
     * Whether the actor owns the Agency Workspace that $payer (an already
     * validated AgencyRebill resolution) is funded by.
     */
    private function ownsPayingAgencyWorkspace(EffectivePayer $payer, int $actorUserId): bool
    {
        return $payer->payerType === PayerType::AgencyRebill
            && $payer->providerCustomerWorkspaceId !== null
            && $this->isOwnerOfWorkspace($payer->providerCustomerWorkspaceId, $actorUserId);
    }

    private function isOwnerOfWorkspace(int $workspaceId, int $actorUserId): bool
    {
        $workspace = $this->workspaceRepository->findById($workspaceId);

        return $workspace !== null && (int) $workspace->owner_user_id === $actorUserId;
    }

    /**
     * An AgencyRebill payer that no longer resolves authorizes nobody, so an
     * invalid relationship is a null payer here — never a fallback.
     */
    private function resolvePayerOrNull(Business $business, bool $forPaidEffect = false): ?EffectivePayer
    {
        try {
            return $forPaidEffect
                ? $this->effectivePayerResolver->resolveForPaidEffect($business)
                : $this->effectivePayerResolver->resolve($business);
        } catch (AgencyRebillRelationshipInvalidException) {
            return null;
        }
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
     *     summary, but never alters the client payer's controls;
     *   - while the managing Agency pays (Contract 09 AgencyRebill): the
     *     managing Agency Workspace owner only. Agency Admin/Staff, the
     *     client's owner and staff, and platform administrators are refused;
     *     a relationship that no longer proves who pays leaves nobody in
     *     control (fail closed).
     *
     * Staff, selected-scope Admins, inactive members and strangers are
     * always refused. Generic permission to edit Business billing data
     * (assertCanManageBusinessUsageBilling()) never implies any of this.
     */
    public function actorManagesPayerControls(Business $business, int $actorUserId): bool
    {
        $business->loadMissing('workspace');

        $payer = $this->resolvePayerOrNull($business);

        if ($payer === null) {
            return false;
        }

        return match ($payer->payerType) {
            PayerType::Business => (int) $business->customer_id === $actorUserId,
            PayerType::Workspace => $this->isAgencyWideManager($business, $actorUserId),
            PayerType::AgencyRebill => $this->ownsPayingAgencyWorkspace($payer, $actorUserId),
        };
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
