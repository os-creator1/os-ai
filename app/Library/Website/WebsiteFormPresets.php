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
            ['key' => 'message', 'label' => 'Tell us about your event', 'type' => WebsiteFormFieldType::Textarea->value, 'required' => false],
        ];
    }
}
