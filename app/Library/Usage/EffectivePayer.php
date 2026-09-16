<?php

namespace App\Library\Usage;

use App\Enums\Usage\PayerType;
use Carbon\CarbonInterface;

/**
 * RFC-005 §6/§16, Implementation Contract 09 §5.2 — who currently pays for a
 * Business's usage, as resolved by EffectivePayerResolver (the only producer
 * of this object).
 *
 * Exactly one of providerCustomerWorkspaceId / providerCustomerBusinessId is
 * non-null for every resolved payer:
 *   - Workspace:    providerCustomerWorkspaceId = the Business's own Workspace
 *   - Business:     providerCustomerBusinessId  = the Business itself
 *   - AgencyRebill: providerCustomerWorkspaceId = the MANAGING AGENCY's
 *                   Workspace, taken from the validated Contract 01
 *                   relationship — never the Client Workspace, never the
 *                   Client Business.
 *
 * managingAgencyRelationshipId and agencyRebillConsentedAt are set for
 * AgencyRebill only. A null consent timestamp means no standing consent.
 */
final readonly class EffectivePayer
{
    public function __construct(
        public PayerType $payerType,
        public int $businessId,
        public ?int $effectivePaymentInstrumentId,
        public ?int $providerCustomerWorkspaceId = null,
        public ?int $providerCustomerBusinessId = null,
        public ?int $managingAgencyRelationshipId = null,
        public ?CarbonInterface $agencyRebillConsentedAt = null,
    ) {
    }

    public function hasAgencyRebillStandingConsent(): bool
    {
        return $this->payerType === PayerType::AgencyRebill && $this->agencyRebillConsentedAt !== null;
    }

    /**
     * Whether two resolutions fund from the same source — used to refuse a
     * paid effect whose payer changed between an authorization read and the
     * locked re-read (Contract 09 §7). Consent is deliberately not compared:
     * the locked re-read's own consent is what the paid-effect gate checks.
     */
    public function fundsFromSameSourceAs(self $other): bool
    {
        return $this->businessId === $other->businessId
            && $this->payerType === $other->payerType
            && $this->providerCustomerWorkspaceId === $other->providerCustomerWorkspaceId
            && $this->providerCustomerBusinessId === $other->providerCustomerBusinessId
            && $this->managingAgencyRelationshipId === $other->managingAgencyRelationshipId;
    }
}
