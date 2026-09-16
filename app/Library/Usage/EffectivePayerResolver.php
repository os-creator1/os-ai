<?php

namespace App\Library\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\AgencyRebillRelationshipInvalidException;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessPayerAssignment;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;

/**
 * Implementation Contract 09 §5.3 — the ONE canonical answer to "who pays
 * for this Business's usage": the only place business_payer_assignments.
 * payer_type decides a provider customer, a payment instrument, the scope of
 * spend controls, or whether an Agency-funded paid effect may proceed.
 *
 * Human authority — who may consent, configure funding or control spend —
 * is deliberately NOT here: that is BillingProfileManager's, the single
 * human payer/funding authority seam, which itself resolves the payer
 * through this class.
 *
 * Dependencies are repositories only. CustomerAccountAccessResolver and
 * EntitlementManager are resolved lazily where used: constructor-injecting
 * either would close a container cycle (UsageWalletManager -> this class ->
 * CustomerAccountAccessResolver -> EntitlementManager ->
 * RealUsageAuthorizationGateway -> UsageWalletManager).
 */
final class EffectivePayerResolver
{
    /** Paid-effect refusal reasons (Contract 09 §5.3/§11). */
    public const REFUSAL_AGENCY_REBILL_RELATIONSHIP_INVALID = 'agency_rebill_relationship_invalid';

    public const REFUSAL_AGENCY_REBILL_CONSENT_MISSING = 'agency_rebill_consent_missing';

    public const REFUSAL_AGENCY_REBILL_ACCOUNT_ACCESS_LOCKED = 'agency_rebill_account_access_locked';

    public const REFUSAL_PAYER_CHANGED = 'payer_changed';

    /** Every refusal above, for callers that classify them as policy refusals. */
    public const PAID_EFFECT_REFUSALS = [
        self::REFUSAL_AGENCY_REBILL_RELATIONSHIP_INVALID,
        self::REFUSAL_AGENCY_REBILL_CONSENT_MISSING,
        self::REFUSAL_AGENCY_REBILL_ACCOUNT_ACCESS_LOCKED,
        self::REFUSAL_PAYER_CHANGED,
    ];

    public function __construct(
        private readonly BusinessPayerAssignmentRepository $payerAssignmentRepository,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
    ) {
    }

    /**
     * Plain-read resolution, for authorization decisions, configuration and
     * display. A missing assignment resolves to Workspace (RFC-005 §32's
     * existing default).
     *
     * @throws AgencyRebillRelationshipInvalidException when an AgencyRebill
     *         payer does not currently resolve to a managing Agency — never
     *         a silent fallback to any client payer.
     */
    public function resolve(Business $business): EffectivePayer
    {
        return $this->fromAssignment(
            $business,
            $this->payerAssignmentRepository->findByBusinessId((int) $business->id),
            lockAgencyRebillRows: false,
        );
    }

    /**
     * The stored payer TYPE only, without resolving who pays: null when the
     * Business has no assignment row yet. For presentation and for labelling
     * a refusal — never for choosing a provider customer, an instrument or an
     * authority (those must use resolve()). It never throws, so a display can
     * still say "agency_rebill" truthfully while that payer fails closed.
     */
    public function assignedPayerType(Business $business): ?PayerType
    {
        return $this->payerAssignmentRepository->findByBusinessId((int) $business->id)?->payer_type;
    }

    /** assignedPayerType(), with RFC-005 §32's Workspace default for a missing row. */
    public function payerTypeOf(Business $business): PayerType
    {
        return $this->assignedPayerType($business) ?? PayerType::Workspace;
    }

    /**
     * Resolution for a paid effect, to be called INSIDE the effect's own
     * transaction after its wallet lock (Contract 09 §7). The assignment is
     * always read with a locking read, for every payer type: a plain
     * consistent read here would fix the transaction's REPEATABLE READ
     * snapshot BEFORE reserve() and claimAutoRechargeAdmissionUnderLock()
     * lock the Workspace controls row, and their Workspace aggregate
     * spend-cap / recharge-ceiling sums would then miss concurrently
     * committed reservations and attempts. For AgencyRebill the relationship
     * is also read with a locking read, so a consent revocation or
     * relationship termination committed before this point can never
     * authorize the effect. Lock order: wallet -> assignment -> (Workspace
     * controls | relationship); payer assignment changes lock only
     * assignment -> relationship, so no cycle is introduced.
     *
     * @throws AgencyRebillRelationshipInvalidException
     */
    public function resolveForPaidEffect(Business $business): EffectivePayer
    {
        return $this->fromAssignment(
            $business,
            $this->payerAssignmentRepository->findForUpdateByBusinessId((int) $business->id),
            lockAgencyRebillRows: true,
        );
    }

    /**
     * Contract 09 §5.3/§11 — whether a NEW paid effect funded by $payer may
     * proceed, beyond the Business wallet's own existing checks. Returns the
     * refusal reason, or null.
     *
     * Business and Workspace payers: always null — this slice adds no gate
     * to them. AgencyRebill: standing consent must exist, and the Client
     * Workspace's effective account access must be usable. That single
     * CustomerAccountAccessResolver::resolve() call is the whole access rule:
     * it refuses Client Locked/Inactive/Suspended and, through Contract 05's
     * composition, Agency Locked/Inactive/Suspended, while Grace stays usable.
     * The Client and Agency states are never recomputed here.
     */
    public function paidEffectRefusal(Business $business, EffectivePayer $payer): ?string
    {
        if ($payer->payerType !== PayerType::AgencyRebill) {
            return null;
        }

        if (! $payer->hasAgencyRebillStandingConsent()) {
            return self::REFUSAL_AGENCY_REBILL_CONSENT_MISSING;
        }

        $business->loadMissing('workspace');

        if (app(CustomerAccountAccessResolver::class)->resolve($business->workspace)->isLocked()) {
            return self::REFUSAL_AGENCY_REBILL_ACCOUNT_ACCESS_LOCKED;
        }

        return null;
    }

    private function fromAssignment(Business $business, ?BusinessPayerAssignment $assignment, bool $lockAgencyRebillRows): EffectivePayer
    {
        $payerType = $assignment?->payer_type ?? PayerType::Workspace;
        $instrumentId = $assignment?->effective_payment_instrument_id !== null ? (int) $assignment->effective_payment_instrument_id : null;

        return match ($payerType) {
            PayerType::Workspace => new EffectivePayer(
                payerType: PayerType::Workspace,
                businessId: (int) $business->id,
                effectivePaymentInstrumentId: $instrumentId,
                providerCustomerWorkspaceId: (int) $business->workspace_id,
            ),
            PayerType::Business => new EffectivePayer(
                payerType: PayerType::Business,
                businessId: (int) $business->id,
                effectivePaymentInstrumentId: $instrumentId,
                providerCustomerBusinessId: (int) $business->id,
            ),
            PayerType::AgencyRebill => $this->resolveAgencyRebill($business, $assignment, $lockAgencyRebillRows),
        };
    }

    /**
     * Defense in depth: the foreign key alone is never trusted. The recorded
     * relationship must exist, be Active, target THIS Business's own
     * Workspace, name a different Workspace as the Agency, and that Agency
     * Workspace must CURRENTLY hold Agency-tier entitlement (Contract 01 §6:
     * an Active relationship row is never proof of it) — otherwise the payer
     * is invalid, nothing is charged and nobody holds its controls. The provider customer is always
     * relationship.agency_workspace_id's.
     */
    private function resolveAgencyRebill(Business $business, BusinessPayerAssignment $assignment, bool $lock): EffectivePayer
    {
        $relationshipId = $assignment->managing_agency_relationship_id;

        if ($relationshipId === null) {
            throw new AgencyRebillRelationshipInvalidException((int) $business->id);
        }

        $relationship = $lock
            ? $this->relationshipRepository->findForUpdate((int) $relationshipId)
            : $this->relationshipRepository->findById((int) $relationshipId);

        if (! $this->relationshipFundsBusiness($relationship, $business)) {
            throw new AgencyRebillRelationshipInvalidException((int) $business->id);
        }

        return new EffectivePayer(
            payerType: PayerType::AgencyRebill,
            businessId: (int) $business->id,
            effectivePaymentInstrumentId: $assignment->effective_payment_instrument_id !== null ? (int) $assignment->effective_payment_instrument_id : null,
            providerCustomerWorkspaceId: (int) $relationship->agency_workspace_id,
            managingAgencyRelationshipId: (int) $relationship->id,
            agencyRebillConsentedAt: $assignment->agency_rebill_consented_at,
        );
    }

    private function relationshipFundsBusiness(?AgencyClientWorkspaceRelationship $relationship, Business $business): bool
    {
        return $relationship !== null
            && $relationship->isActive()
            && (int) $relationship->client_workspace_id === (int) $business->workspace_id
            && (int) $relationship->agency_workspace_id !== (int) $business->workspace_id
            && $this->agencyWorkspaceCurrentlyHoldsAgencyTier($relationship);
    }

    /** The same tier rule BillingProfileManager applies when consent is granted. */
    private function agencyWorkspaceCurrentlyHoldsAgencyTier(AgencyClientWorkspaceRelationship $relationship): bool
    {
        $agencyWorkspace = $relationship->agencyWorkspace;

        return $agencyWorkspace !== null
            && app(EntitlementManager::class)->getWorkspaceEntitlementSummary($agencyWorkspace)->tier === WorkspacePlanTier::Agency;
    }
}
