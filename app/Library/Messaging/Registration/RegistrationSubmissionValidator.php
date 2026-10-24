<?php

namespace App\Library\Messaging\Registration;

use App\Enums\Messaging\MessagingRegistrationStatus;
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

        $issues = array_merge($issues, $this->messageIssues($registration), $this->consentIssues($registration));

        return array_values(array_unique($issues));
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

        foreach (RegistrationTemplateCatalog::CONSENT_ITEMS as $key => $label) {
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
