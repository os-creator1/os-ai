<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\PhoneNumberType;
use App\Models\BusinessMessagingRegistration;

/**
 * Text messaging setup/number/compliance hub — the exact, already-captured
 * fields a registration submission sends to the provider. Built only from
 * a persisted BusinessMessagingRegistration row (fromModel()), never from
 * raw request input directly, so the adapter can never receive a field
 * this platform has not already validated and stored.
 */
final readonly class MessagingRegistrationSubmission
{
    public function __construct(
        public PhoneNumberType $numberType,
        public string $legalBusinessName,
        public MessagingEntityType $entityType,
        public ?string $ein,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public string $region,
        public string $postalCode,
        public string $countryCode,
        public string $websiteUrl,
        public string $contactEmail,
        public string $contactPhone,
        public string $useCase,
        public string $optInMethod,
        public string $sampleMessage1,
        public string $sampleMessage2,
        public string $privacyPolicyUrl,
        public string $termsUrl,
    ) {
    }

    public static function fromModel(BusinessMessagingRegistration $registration): self
    {
        return new self(
            numberType: $registration->number_type,
            legalBusinessName: (string) $registration->legal_business_name,
            entityType: $registration->entity_type,
            ein: $registration->ein,
            addressLine1: (string) $registration->address_line_1,
            addressLine2: $registration->address_line_2,
            city: (string) $registration->city,
            region: (string) $registration->region,
            postalCode: (string) $registration->postal_code,
            countryCode: (string) $registration->country_code,
            websiteUrl: (string) $registration->website_url,
            contactEmail: (string) $registration->contact_email,
            contactPhone: (string) $registration->contact_phone,
            useCase: (string) $registration->use_case,
            optInMethod: (string) $registration->opt_in_method,
            sampleMessage1: (string) $registration->sample_message_1,
            sampleMessage2: (string) $registration->sample_message_2,
            privacyPolicyUrl: (string) $registration->privacy_policy_url,
            termsUrl: (string) $registration->terms_url,
        );
    }
}
