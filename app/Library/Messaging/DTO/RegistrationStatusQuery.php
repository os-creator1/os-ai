<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\PhoneNumberType;
use App\Models\BusinessMessagingRegistration;

/**
 * PR #295 Correction Round 1, item 7 — the honest, type-aware input to
 * refreshRegistrationStatus(), replacing the old (providerBrandId,
 * providerCampaignId) pair that a toll-free registration used to abuse by
 * writing the same opaque id into both. $numberType decides which of the
 * provider reference fields are actually meaningful; the adapter must
 * never guess a status by calling the wrong regime's endpoint.
 */
final readonly class RegistrationStatusQuery
{
    public function __construct(
        public PhoneNumberType $numberType,
        public ?string $providerBrandId,
        public ?string $providerCampaignId,
        public ?string $providerRegistrationId,
    ) {
    }

    public static function fromModel(BusinessMessagingRegistration $registration): self
    {
        return new self(
            numberType: $registration->number_type,
            providerBrandId: $registration->provider_brand_id,
            providerCampaignId: $registration->provider_campaign_id,
            providerRegistrationId: $registration->provider_registration_id,
        );
    }

    /** A stable cache/lookup key a fake test double can key scripted answers by. */
    public function key(): string
    {
        return $this->numberType->value . ':' . ($this->providerRegistrationId ?? ($this->providerBrandId . ':' . $this->providerCampaignId));
    }
}
