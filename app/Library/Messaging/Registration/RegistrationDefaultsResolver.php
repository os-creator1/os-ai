<?php

namespace App\Library\Messaging\Registration;

use App\Models\Business;
use App\Models\BusinessMessagingRegistration;

/**
 * Registration form values: what the customer already saved always wins;
 * a blank field falls back to what onboarding already knows (name,
 * website, primary-location address, email, phone) and, once a use case is
 * saved, to that niche's template. Never invents an EIN, legal identity,
 * address, Privacy/Terms URL or authorization — those stay blank until the
 * customer supplies them.
 */
final class RegistrationDefaultsResolver
{
    private const FIELDS = [
        'legal_business_name', 'address_line_1', 'address_line_2', 'city', 'region', 'postal_code',
        'website_url', 'contact_email', 'contact_phone',
        'campaign_description', 'opt_in_method', 'sample_message_1', 'sample_message_2',
    ];

    /**
     * @return array{values: array<string, string>, prefilled: list<string>, topics: list<string>}
     */
    public function resolve(Business $business, ?BusinessMessagingRegistration $registration): array
    {
        $location = $business->primaryLocation;
        $known = [
            'legal_business_name' => $business->name,
            'address_line_1' => $location?->address_line_1,
            'address_line_2' => $location?->address_line_2,
            'city' => $location?->city,
            'region' => $location?->region,
            'postal_code' => $location?->postal_code,
            'website_url' => $business->website_url,
            'contact_email' => $business->email,
            'contact_phone' => $business->phone,
        ];

        $brand = $this->brandName($business, $registration);
        $website = $this->firstFilled($registration?->website_url, $business->website_url);
        $templates = RegistrationTemplateCatalog::forUseCase(
            $business->industry?->value,
            $registration?->use_case,
            $brand,
            $website,
        );

        if ($templates !== null) {
            $known += array_intersect_key($templates, array_flip(['campaign_description', 'opt_in_method', 'sample_message_1', 'sample_message_2']));
        }

        $values = [];
        $prefilled = [];

        foreach (self::FIELDS as $field) {
            $saved = $registration?->{$field};

            if (is_string($saved) && trim($saved) !== '') {
                $values[$field] = $saved;

                continue;
            }

            $default = $known[$field] ?? null;
            $values[$field] = is_string($default) ? $default : '';

            if ($values[$field] !== '') {
                $prefilled[] = $field;
            }
        }

        return ['values' => $values, 'prefilled' => $prefilled, 'topics' => $templates['topics'] ?? []];
    }

    /**
     * Templates for each of the three choices, so the page can offer the
     * matching copy the moment the customer picks one.
     *
     * The brand is left as the literal "{brand}" token: the page substitutes
     * whatever the legal-name input holds at the moment of switching.
     *
     * @return array<string, array<string, mixed>>
     */
    public function templatesByChoice(Business $business, ?BusinessMessagingRegistration $registration): array
    {
        $brand = '{brand}';
        $website = $this->firstFilled($registration?->website_url, $business->website_url);
        $byChoice = [];

        foreach (RegistrationTemplateCatalog::CHOICES as $choice => $definition) {
            $byChoice[$choice] = RegistrationTemplateCatalog::forUseCase($business->industry?->value, $definition['use_case'], $brand, $website) ?? [];
        }

        return $byChoice;
    }

    /** Registered brand name: the saved legal name, else the Business's own name. */
    public function brandName(Business $business, ?BusinessMessagingRegistration $registration): string
    {
        return (string) $this->firstFilled($registration?->legal_business_name, $business->name);
    }

    private function firstFilled(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
