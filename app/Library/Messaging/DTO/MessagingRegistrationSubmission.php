<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
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
        public int $businessId,
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
        /**
         * Review correction — Telnyx's toll-free verification submission
         * requires the already-owned number being verified (a `phoneNumbers`
         * array in the request body); 10DLC brand+campaign registration has
         * no such requirement, since it is a Business/account-level
         * submission Telnyx genuinely supports completing before any number
         * is purchased. Null for a local (10DLC) registration submitted
         * before a number exists; always populated for a toll-free
         * registration, since that regime can only ever be submitted once a
         * number is already owned (the controller enforces this before
         * calling submit()).
         */
        public ?string $phoneNumber = null,
        /**
         * A 10DLC brand this registration already created at the provider
         * on an earlier, partly-failed attempt — reused on retry so a
         * second brand is never created. Always null for toll-free.
         */
        public ?string $existingBrandId = null,
        /**
         * A campaign already created and persisted by an earlier attempt
         * that crashed before the fee was committed — never created twice.
         */
        public ?string $existingCampaignId = null,
        /**
         * Funding cycle: changes with every carrier rejection, so a fee
         * committed for an earlier, rejected submission never counts as
         * the fee for a resubmission.
         */
        public string $fundingCycle = '0',
        /** A toll-free verification request already accepted and saved by an earlier attempt. */
        public ?string $existingVerificationId = null,
        public ?string $contactFirstName = null,
        public ?string $contactLastName = null,
        /** Toll-free verification: expected monthly volume (provider enum value). */
        public ?string $messageVolume = null,
        /** Toll-free verification: image URL showing the opt-in flow. */
        public ?string $optInImageUrl = null,
        /** 10DLC brand industry vertical (provider enum value). */
        public ?string $brandVertical = null,
        /** Low Volume Mixed: the customer's real purposes (care / marketing). @var list<string> */
        public array $messagePurposes = [],
        /** Customer-edited campaign description; required for a 10DLC submission (the adapter refuses without it). */
        public ?string $campaignDescription = null,
        /** The Business's own customer-facing SMS help contact, only when it explicitly confirmed it; null otherwise. */
        public ?string $supportContact = null,
    ) {
    }

    public static function fromModel(BusinessMessagingRegistration $registration, ?string $phoneNumber = null): self
    {
        return new self(
            businessId: (int) $registration->business_id,
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
            supportContact: $registration->support_contact_confirmed_at !== null && trim((string) $registration->support_contact) !== '' ? trim((string) $registration->support_contact) : null,
            phoneNumber: $phoneNumber,
            existingBrandId: $registration->number_type === PhoneNumberType::Local && $registration->status === MessagingRegistrationStatus::NotStarted && $registration->provider_brand_id !== null && $registration->provider_brand_id !== ''
                ? (string) $registration->provider_brand_id
                : null,
            existingCampaignId: $registration->number_type === PhoneNumberType::Local && $registration->status === MessagingRegistrationStatus::NotStarted && $registration->provider_campaign_id !== null && $registration->provider_campaign_id !== ''
                ? (string) $registration->provider_campaign_id
                : null,
            existingVerificationId: $registration->number_type === PhoneNumberType::TollFree && $registration->status === MessagingRegistrationStatus::NotStarted && $registration->provider_registration_id !== null && $registration->provider_registration_id !== ''
                ? (string) $registration->provider_registration_id
                : null,
            contactFirstName: $registration->contact_first_name,
            contactLastName: $registration->contact_last_name,
            messageVolume: $registration->message_volume,
            optInImageUrl: $registration->opt_in_image_url,
            brandVertical: $registration->brand_vertical,
            messagePurposes: array_values((array) $registration->message_purposes),
            fundingCycle: (string) ($registration->rejected_at?->getTimestamp() ?? 0),
            campaignDescription: $registration->campaign_description,
        );
    }
}
