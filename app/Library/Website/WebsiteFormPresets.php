<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteFormFieldType;

/**
 * The one shipped Forms preset: a Photo Booth quote request. Reusable
 * Forms foundation means the mechanics (config shape, validation,
 * submission handling, business inbox) never assume this exact field
 * list — a future preset for another vertical is just a different array
 * here, not a new code path.
 */
final class WebsiteFormPresets
{
    /**
     * @return array<int, array{key: string, label: string, type: string, required: bool}>
     */
    public static function photoBoothQuoteRequest(): array
    {
        return [
            ['key' => 'name', 'label' => 'Your name', 'type' => WebsiteFormFieldType::Text->value, 'required' => true],
            ['key' => 'phone', 'label' => 'Phone number', 'type' => WebsiteFormFieldType::Tel->value, 'required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => WebsiteFormFieldType::Email->value, 'required' => false],
            ['key' => 'event_date', 'label' => 'Event date', 'type' => WebsiteFormFieldType::Date->value, 'required' => false],
            ['key' => 'event_type', 'label' => 'Event type', 'type' => WebsiteFormFieldType::Text->value, 'required' => false],
            ['key' => 'event_location', 'label' => 'Event location', 'type' => WebsiteFormFieldType::Text->value, 'required' => false],
            ['key' => 'message', 'label' => 'Message / additional details', 'type' => WebsiteFormFieldType::Textarea->value, 'required' => false],
        ];
    }

    /**
     * The Dog Boarding stay-request form. It asks only what is needed to review a REQUEST (who, how to reach them, which dog,
     * which dates, which services). Private care detail (medication, medical or behavioural history) is deliberately not a
     * field: the owner asks for it from a known contact once they decide to proceed.
     *
     * @return array<int, array{key: string, label: string, type: string, required: bool}>
     */
    public static function dogBoardingStayRequest(): array
    {
        return [
            ['key' => 'name', 'label' => 'Your name', 'type' => WebsiteFormFieldType::Text->value, 'required' => true],
            ['key' => 'phone', 'label' => 'Phone number', 'type' => WebsiteFormFieldType::Tel->value, 'required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => WebsiteFormFieldType::Email->value, 'required' => false],
            ['key' => 'dog_name', 'label' => "Your dog's name", 'type' => WebsiteFormFieldType::Text->value, 'required' => false],
            ['key' => 'dog_breed_size', 'label' => 'Breed and size', 'type' => WebsiteFormFieldType::Text->value, 'required' => false],
            ['key' => 'dog_count', 'label' => 'Number of dogs', 'type' => WebsiteFormFieldType::Text->value, 'required' => false],
            ['key' => 'arrival_date', 'label' => 'Preferred arrival date', 'type' => WebsiteFormFieldType::Date->value, 'required' => false],
            ['key' => 'departure_date', 'label' => 'Preferred departure date', 'type' => WebsiteFormFieldType::Date->value, 'required' => false],
            ['key' => 'services_requested', 'label' => 'What are you looking for?', 'type' => WebsiteFormFieldType::Text->value, 'required' => false],
            ['key' => 'preferred_contact', 'label' => 'Best way to reach you', 'type' => WebsiteFormFieldType::Text->value, 'required' => false],
            ['key' => 'message', 'label' => 'Questions or notes', 'type' => WebsiteFormFieldType::Textarea->value, 'required' => false],
        ];
    }

    /** The CRM pipeline the Dog Boarding Blueprint installed for a Business (its stay requests), or null when it has none. */
    public static function dogBoardingPipelineId(int $businessId): ?int
    {
        $id = \App\Models\CrmPipeline::query()
            ->where('business_id', $businessId)
            ->where('template_key', \App\Library\NicheBlueprint\Niches\DogBoardingBlueprint::PIPELINE_TEMPLATE_KEY)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
