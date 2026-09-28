<?php

namespace App\Library\Messaging\DTO;

use App\Models\BusinessMessagingNumber;

/**
 * Phone Numbers + A2P lane — the provider-neutral input to
 * MessagingProvisioningAdapter::releaseNumber(), mirroring
 * RegistrationStatusQuery's own shape: a plain DTO carrying only what the
 * carrier boundary actually needs, never the whole Eloquent model.
 *
 * providerPhoneNumberId is required and non-nullable here deliberately:
 * NumberLifecycleManager::confirmCarrierRelease() checks it is present
 * BEFORE ever constructing this object, so an adapter implementation can
 * assume a real reference to call the carrier with, never an empty string.
 */
final readonly class NumberReleaseQuery
{
    public function __construct(
        public string $providerPhoneNumberId,
        public string $phoneNumber,
    ) {
    }

    public static function fromModel(BusinessMessagingNumber $number): self
    {
        return new self(
            providerPhoneNumberId: (string) $number->provider_number_reference,
            phoneNumber: $number->phone_number,
        );
    }
}
