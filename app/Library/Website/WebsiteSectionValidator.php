<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteSectionType;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\ValidationException;

/**
 * Website Generation + Hosting Slice A contract §7/§16/§19. The single
 * source of truth for validating a page's `sections` JSON array against
 * the bounded Website Component Library. An unknown `type`, or a `data`
 * shape that fails validation, is rejected outright — never partially
 * persisted (contract §7). Used by both WebsiteDraftPageService (draft
 * save) and the AI output contract (§16, with asset references
 * forbidden entirely per that section's v1 simplification).
 */
final class WebsiteSectionValidator
{
    public const MAX_SECTIONS_PER_PAGE = 40;

    /**
     * @param array $sections the raw, decoded sections array
     * @param array $validAssetUids uids of assets belonging to this Website
     * @param bool $allowAssetReferences false for AI-generated output (§16) —
     *        any non-empty asset reference is itself a validation failure
     * @return array the validated (structurally unchanged) sections array
     * @throws ValidationException
     */
    public function validate(array $sections, array $validAssetUids, bool $allowAssetReferences = true): array
    {
        $errors = [];

        if (count($sections) > self::MAX_SECTIONS_PER_PAGE) {
            $errors['sections'][] = 'A page may have at most ' . self::MAX_SECTIONS_PER_PAGE . ' sections.';
            throw ValidationException::withMessages($errors);
        }

        foreach ($sections as $index => $section) {
            if (! is_array($section) || ! isset($section['type'], $section['data']) || ! is_array($section['data'])) {
                $errors["sections.{$index}"][] = 'Each section must have a type and a data object.';

                continue;
            }

            $type = WebsiteSectionType::tryFrom((string) $section['type']);

            if ($type === null) {
                $errors["sections.{$index}.type"][] = 'Unknown section type.';

                continue;
            }

            try {
                $this->validateData($type, $section['data'], $validAssetUids, $allowAssetReferences);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors["sections.{$index}.{$field}"] = $messages;
                }
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $sections;
    }

    /**
     * @throws ValidationException
     */
    private function validateData(WebsiteSectionType $type, array $data, array $validAssetUids, bool $allowAssetReferences): void
    {
        $rules = match ($type) {
            WebsiteSectionType::Hero => [
                'heading' => 'required|string|max:120',
                'subheading' => 'nullable|string|max:240',
                'background_image' => 'nullable|string',
                'primary_cta' => 'nullable|array',
                'primary_cta.label' => 'required_with:primary_cta|string|max:40',
                'primary_cta.url' => 'required_with:primary_cta|string',
                'secondary_cta' => 'nullable|array',
                'secondary_cta.label' => 'required_with:secondary_cta|string|max:40',
                'secondary_cta.url' => 'required_with:secondary_cta|string',
            ],
            WebsiteSectionType::Text => [
                'heading' => 'nullable|string|max:120',
                'body' => 'required|string|max:5000',
            ],
            WebsiteSectionType::ImageText => [
                'heading' => 'nullable|string|max:120',
                'body' => 'required|string|max:3000',
                'image' => 'required|string',
                'image_position' => 'required|in:left,right',
            ],
            WebsiteSectionType::Services => [
                'heading' => 'nullable|string|max:120',
                'items' => 'required|array|max:12',
                'items.*.name' => 'required|string|max:120',
                'items.*.description' => 'nullable|string|max:500',
                'items.*.price_label' => 'nullable|string|max:40',
                'items.*.image' => 'nullable|string',
            ],
            WebsiteSectionType::Testimonials => [
                'heading' => 'nullable|string|max:120',
                'items' => 'required|array|max:10',
                'items.*.quote' => 'required|string|max:400',
                'items.*.author_name' => 'required|string|max:80',
                'items.*.author_title' => 'nullable|string|max:80',
            ],
            WebsiteSectionType::Faq => [
                'heading' => 'nullable|string|max:120',
                'items' => 'required|array|max:20',
                'items.*.question' => 'required|string|max:200',
                'items.*.answer' => 'required|string|max:1000',
            ],
            WebsiteSectionType::Cta => [
                'heading' => 'required|string|max:120',
                'body' => 'nullable|string|max:300',
                'buttons' => 'required|array|min:1|max:2',
                'buttons.*.label' => 'required|string|max:40',
                'buttons.*.url' => 'required|string',
            ],
            WebsiteSectionType::ContactDetails => [
                'show_phone' => 'required|boolean',
                'show_email' => 'required|boolean',
                'show_address' => 'required|boolean',
            ],
        };

        $validator = ValidatorFacade::make($data, $rules);
        $validator->validate();

        $this->validateUrls($type, $data);
        $this->validateAssetReferences($type, $data, $validAssetUids, $allowAssetReferences);
    }

    /**
     * @throws ValidationException
     */
    private function validateUrls(WebsiteSectionType $type, array $data): void
    {
        $urlFields = match ($type) {
            WebsiteSectionType::Hero => ['primary_cta.url', 'secondary_cta.url'],
            WebsiteSectionType::Cta => ['buttons.*.url'],
            default => [],
        };

        $errors = [];

        foreach ($urlFields as $field) {
            if (str_contains($field, '*')) {
                $buttons = $data['buttons'] ?? [];
                foreach ($buttons as $i => $button) {
                    if (isset($button['url']) && ! WebsiteUrlRules::isValid((string) $button['url'])) {
                        $errors["buttons.{$i}.url"][] = 'The URL must be https, tel:, or mailto: only.';
                    }
                }

                continue;
            }

            [$group, $key] = explode('.', $field);

            if (isset($data[$group][$key]) && ! WebsiteUrlRules::isValid((string) $data[$group][$key])) {
                $errors[$field][] = 'The URL must be https, tel:, or mailto: only.';
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateAssetReferences(WebsiteSectionType $type, array $data, array $validAssetUids, bool $allowAssetReferences): void
    {
        $assetFields = match ($type) {
            WebsiteSectionType::Hero => ['background_image'],
            WebsiteSectionType::ImageText => ['image'],
            default => [],
        };

        $errors = [];

        foreach ($assetFields as $field) {
            $this->checkAssetValue($data[$field] ?? null, $field, $validAssetUids, $allowAssetReferences, $errors);
        }

        if ($type === WebsiteSectionType::Services) {
            foreach (($data['items'] ?? []) as $i => $item) {
                $this->checkAssetValue($item['image'] ?? null, "items.{$i}.image", $validAssetUids, $allowAssetReferences, $errors);
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function checkAssetValue(?string $value, string $field, array $validAssetUids, bool $allowAssetReferences, array &$errors): void
    {
        if (empty($value)) {
            return;
        }

        if (! $allowAssetReferences) {
            $errors[$field][] = 'Asset references are not permitted in AI-generated content.';

            return;
        }

        if (! in_array($value, $validAssetUids, true)) {
            $errors[$field][] = 'Unknown or foreign asset reference.';
        }
    }
}
