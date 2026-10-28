<?php

namespace App\Library\Messaging\Registration;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Models\BusinessMessagingRegistration;

/**
 * Read-only pre-submission check run before the (paid) submit action.
 * Returns plain-language corrections; it never changes a campaign choice,
 * never submits, and never touches billing — the funding checks stay in
 * the provisioning adapter.
 */
final class RegistrationSubmissionValidator
{
    private const REQUIRED = [
        'legal_business_name' => 'legal business name',
        'address_line_1' => 'street address',
        'city' => 'city',
        'region' => 'state',
        'postal_code' => 'ZIP code',
        'website_url' => 'website',
        'contact_email' => 'contact email',
        'contact_phone' => 'contact phone',
        'use_case' => 'messaging type',
        'campaign_description' => 'campaign description',
        'opt_in_method' => 'message-flow (how customers opt in) description',
        'sample_message_1' => 'sample message 1',
        'sample_message_2' => 'sample message 2',
        'privacy_policy_url' => 'Privacy Policy URL',
        'terms_url' => 'SMS Terms URL',
    ];

    private const PLACEHOLDER = '/\[[^\]]*\]|\{\{?[^}]*\}?\}|\.\.\.|…|\bTODO\b|\bTBD\b|lorem ipsum|add it here|your (business|company)\b|example\.(com|org|net)\b|\bxxx+\b/i';

    private const PROMOTIONAL = '/\b(promo\w*|special\w*|discount\w*|sale|deal\w*|\d+%|% off|save)\b/i';

    /** @return list<string> actionable corrections; empty when ready to submit */
    public function issues(BusinessMessagingRegistration $registration): array
    {
        $issues = [];

        if ($registration->status === MessagingRegistrationStatus::Pending) {
            return ['This registration has already been submitted and is awaiting review. Nothing more to submit.'];
        }

        if ($registration->status === MessagingRegistrationStatus::Approved) {
            return ['This registration is already approved. Nothing more to submit.'];
        }

        foreach (self::REQUIRED as $field => $label) {
            $value = $registration->{$field};

            if (! is_string($value) || trim($value) === '') {
                $issues[] = 'Add your ' . $label . '.';
            } elseif (preg_match(self::PLACEHOLDER, $value) === 1) {
                $issues[] = 'Replace the placeholder text in your ' . $label . ' with real details.';
            }
        }

        if ($registration->entity_type?->value === 'ein' && trim((string) $registration->ein) === '') {
            $issues[] = 'Add your EIN, or choose "Individual / sole proprietor".';
        }

        foreach (['website_url' => 'website', 'privacy_policy_url' => 'Privacy Policy URL', 'terms_url' => 'SMS Terms URL'] as $field => $label) {
            $url = (string) $registration->{$field};

            if ($url !== '' && ! preg_match('#^https?://[^/\s]+\.[^/\s]+#i', $url)) {
                $issues[] = 'Your ' . $label . ' must be a full web address starting with https://.';
            }
        }

        $privacy = rtrim((string) $registration->privacy_policy_url, '/');
        $terms = rtrim((string) $registration->terms_url, '/');
        if ($privacy !== '' && $privacy === $terms) {
            $issues[] = 'The Privacy Policy and SMS Terms need separate pages.';
        }

        $issues = array_merge($issues, $this->carrierFieldIssues($registration), $this->messageIssues($registration), $this->consentIssues($registration));

        return array_values(array_unique($issues));
    }

    /**
     * Low Volume Mixed is only for a business that genuinely sends the
     * purposes it registers: at least one real purpose, a sample for each,
     * and never a purpose the customer did not choose.
     *
     * @param  array<int, string>  $promotional
     * @param  array{0: string, 1: string}  $samples
     * @return list<string>
     */
    private function lowVolumeIssues(BusinessMessagingRegistration $registration, array $promotional, array $samples): array
    {
        $issues = [];
        $purposes = array_values(array_intersect(array_keys(CarrierRegistrationOptions::LOW_VOLUME_PURPOSES), (array) $registration->message_purposes));

        // Low Volume Mixed is defined for several genuine, distinct purposes.
        // A single purpose is never "mixed": that traffic belongs in its own
        // (standard) campaign type, and is never relabelled to get a lower fee.
        if (count($purposes) < count(CarrierRegistrationOptions::LOW_VOLUME_PURPOSES)) {
            return ['Low Volume Mixed is only for a business that really sends both service texts and promotional texts. Tick both, or choose the single message type you send instead.'];
        }

        $promoCount = count($promotional);

        if ($promoCount === 2) {
            $issues[] = 'Both samples are promotions. One sample must be a service or customer-care example.';
        }

        if ($promoCount === 0) {
            $issues[] = 'Neither sample is a promotion. One sample must be a promotional example.';
        }

        foreach ($promotional as $message) {
            if (stripos($message, 'stop') === false) {
                $issues[] = 'Every promotional sample message needs opt-out wording such as "Reply STOP to opt out".';
            }
        }

        return $issues;
    }

    /** A valid email address, or a phone number with 10-15 digits. */
    public static function isUsableSupportContact(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 191) {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        $digits = preg_replace('/\D/', '', $value);

        return preg_match('/^[+()\-.\s0-9]+$/', $value) === 1 && strlen($digits) >= 10 && strlen($digits) <= 15;
    }

    /** @return list<string> */
    private function carrierFieldIssues(BusinessMessagingRegistration $registration): array
    {
        $issues = [];

        foreach (['contact_first_name' => 'contact first name', 'contact_last_name' => 'contact last name'] as $field => $label) {
            if (trim((string) $registration->{$field}) === '') {
                $issues[] = 'Add your ' . $label . '.';
            }
        }

        if (trim((string) $registration->region) !== '' && CarrierRegistrationOptions::stateCode($registration->region) === null) {
            $issues[] = 'Your state must be a US state (for example TX or Texas).';
        }

        if ($registration->number_type === PhoneNumberType::TollFree) {
            if (! isset(CarrierRegistrationOptions::MESSAGE_VOLUMES[(string) $registration->message_volume])) {
                $issues[] = 'Choose your expected messages per month.';
            }

            if (! filter_var((string) $registration->opt_in_image_url, FILTER_VALIDATE_URL)) {
                $issues[] = 'Add a link to a screenshot of your opt-in form (the carrier requires an image).';
            }

            if (! isset(CarrierRegistrationOptions::TOLL_FREE_USE_CASES[(string) $registration->use_case])) {
                $issues[] = 'This messaging type can\'t be submitted for toll-free verification. Choose another.';
            }
        } else {
            if (! isset(CarrierRegistrationOptions::BRAND_VERTICALS[(string) $registration->brand_vertical])) {
                $issues[] = 'Choose your industry category.';
            }

            if (! self::isUsableSupportContact((string) $registration->support_contact)) {
                $issues[] = 'Add the customer-facing support email or phone your Business publishes for text-message help (not the brand contact above).';
            } elseif ($registration->support_contact_confirmed_at === null) {
                $issues[] = 'Confirm that your text-message support contact is published by your Business and answered.';
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function messageIssues(BusinessMessagingRegistration $registration): array
    {
        $issues = [];
        $one = trim((string) $registration->sample_message_1);
        $two = trim((string) $registration->sample_message_2);
        $useCase = (string) $registration->use_case;

        if ($one !== '' && strcasecmp($one, $two) === 0) {
            $issues[] = 'Sample messages 1 and 2 must be different examples.';
        }

        $promotional = array_filter([$one, $two], fn (string $m): bool => preg_match(self::PROMOTIONAL, $m) === 1);

        if (RegistrationTemplateCatalog::isCareOnly($useCase) && preg_match(self::PROMOTIONAL, (string) $registration->campaign_description) === 1) {
            $issues[] = 'Your campaign description mentions promotions, but you chose service and customer-care messages. Reword it, or change your choice to Promotional or Both.';
        }

        if (RegistrationTemplateCatalog::isCareOnly($useCase) && $promotional !== []) {
            $issues[] = 'A sample message reads as a promotion, but you chose service and customer-care messages. Reword it, or change your choice to Promotional or Both.';
        }

        if ($useCase === 'mixed' && ! config('messaging.ten_dlc_mixed_enabled')) {
            $issues[] = 'Registering both message types together is not available yet. Choose service and customer-care, or promotional and marketing.';
        }

        if ($useCase === 'marketing' && count($promotional) < 2) {
            $issues[] = 'Promotional and marketing campaigns need both sample messages to be promotions. Reword the sample that is not.';
        }

        if ($useCase === 'low_volume_mixed') {
            $issues = array_merge($issues, $this->lowVolumeIssues($registration, $promotional, [$one, $two]));
        }

        if ($useCase === 'mixed' && count($promotional) === 2) {
            $issues[] = 'You chose both message types, but both samples are promotions. Make one a service or customer-care example.';
        }

        if (in_array($useCase, ['marketing', 'mixed'], true)) {
            if ($promotional === []) {
                $issues[] = 'Your choice includes promotional messages, but neither sample message is a promotion. Add a promotional example.';
            }

            foreach ($promotional as $message) {
                if (stripos($message, 'stop') === false) {
                    $issues[] = 'Every promotional sample message needs opt-out wording such as "Reply STOP to opt out".';
                }
            }
        }

        $issues = array_merge($issues, $this->staleSuggestionIssues($registration));

        $brand = trim((string) $registration->legal_business_name);
        if ($brand !== '' && $one !== '' && $two !== '' && stripos($one . ' ' . $two, $this->firstWords($brand)) === false) {
            $issues[] = 'Include your business name in at least one sample message.';
        }

        return $issues;
    }

    /**
     * An untouched suggestion written for a *different* messaging type is
     * not silently accepted for the saved one.
     *
     * @return list<string>
     */
    private function staleSuggestionIssues(BusinessMessagingRegistration $registration): array
    {
        $own = RegistrationTemplateCatalog::choiceForUseCase($registration->use_case);

        if ($own === null) {
            return [];
        }

        $industry = $registration->business?->industry?->value;
        $fields = ['campaign_description' => 'campaign description', 'opt_in_method' => 'message-flow description', 'sample_message_1' => 'sample message 1', 'sample_message_2' => 'sample message 2'];
        $issues = [];

        foreach (RegistrationTemplateCatalog::CHOICES as $choice => $definition) {
            if ($choice === $own) {
                continue;
            }

            $other = RegistrationTemplateCatalog::forUseCase($industry, $definition['use_case'], trim((string) $registration->legal_business_name), $registration->website_url);
            $mine = RegistrationTemplateCatalog::forUseCase($industry, $registration->use_case, trim((string) $registration->legal_business_name), $registration->website_url);

            foreach ($fields as $field => $label) {
                $value = trim((string) $registration->{$field});

                if ($value !== '' && $value === $other[$field] && $value !== $mine[$field]) {
                    $issues[] = 'Your ' . $label . ' is still the suggestion for "' . $definition['label'] . '". Update it to match the messaging type you chose.';
                }
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function consentIssues(BusinessMessagingRegistration $registration): array
    {
        $issues = [];

        if (strlen(trim((string) $registration->opt_in_method)) < 40) {
            $issues[] = 'Describe in more detail how customers opt in (where they enter their number and what they agree to).';
        }

        $confirmed = is_array($registration->consent_confirmations) ? $registration->consent_confirmations : [];

        foreach (RegistrationTemplateCatalog::consentItemsFor($registration->use_case, $registration->message_purposes) as $key => $label) {
            if (! in_array($key, $confirmed, true)) {
                $issues[] = 'Confirm on your website, then check: ' . $label . '.';
            }
        }

        return $issues;
    }

    private function firstWords(string $brand): string
    {
        return implode(' ', array_slice(preg_split('/\s+/', $brand) ?: [], 0, 2));
    }
}
