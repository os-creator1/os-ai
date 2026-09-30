<?php

namespace App\Library\Website\Setup;

use App\Library\Website\Setup\Exceptions\InvalidAnswerException;

/**
 * Independent-review correction round — before this lane, only
 * `template_key` was ever validated on the wizard's HTTP surface; every
 * question's answer was trusted verbatim, so a forged select/multi_select
 * value, a missing-but-silently-false required boolean, or an unbounded
 * repeatable-group submission would reach WebsiteSetupAnswerApplier (and
 * from there, real canonical records) unchecked.
 *
 * Validates an ALREADY-SHAPED value (WebsiteWizardController::
 * valueFromRequest()'s output) against its pinned step definition. Never
 * validates raw request input directly, and never trusts `$step` itself
 * beyond what QuestionnaireDefinitionValidator already proved about it at
 * publish time — this class's own job is only "does THIS value satisfy
 * THIS step."
 */
final class QuestionnaireAnswerValidator
{
    public const MAX_TEXT = 500;

    public const MAX_TEXTAREA = 5000;

    public const MAX_REPEATABLE_ITEMS = 30;

    public const MAX_ITEM_NAME = 160;

    public const MAX_ITEM_DESCRIPTION = 2000;

    public const MAX_FEATURES = 20;

    public const MAX_FEATURE_LENGTH = 200;

    /**
     * @throws InvalidAnswerException
     */
    public function validate(array $step, mixed $value): void
    {
        $required = (bool) ($step['required'] ?? false);
        $inputType = (string) ($step['input_type'] ?? '');

        if ($value === null) {
            if ($required) {
                throw new InvalidAnswerException('This question requires an answer.');
            }

            return;
        }

        match ($inputType) {
            'text', 'tel' => $this->validateText($value, self::MAX_TEXT),
            'email' => $this->validateEmail($value, $required),
            'textarea' => $this->validateText($value, self::MAX_TEXTAREA),
            'select' => $this->validateSelect($step, $value),
            'multi_select' => $this->validateMultiSelect($step, $value),
            'boolean' => $this->validateBoolean($value),
            'repeatable_group' => $this->validateRepeatableGroup($step, $value),
            default => null,
        };

        if ($required && $this->isEffectivelyEmpty($value)) {
            throw new InvalidAnswerException('This question requires an answer.');
        }
    }

    private function isEffectivelyEmpty(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function validateText(mixed $value, int $max): void
    {
        if (! is_string($value)) {
            throw new InvalidAnswerException('That answer is not valid text.');
        }

        if (mb_strlen($value) > $max) {
            throw new InvalidAnswerException("That answer is too long (max {$max} characters).");
        }
    }

    private function validateEmail(mixed $value, bool $required): void
    {
        if (! is_string($value) || mb_strlen($value) > self::MAX_TEXT) {
            throw new InvalidAnswerException('That answer is not a valid email address.');
        }

        if (trim($value) !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidAnswerException('That answer is not a valid email address.');
        }

        if ($required && trim($value) === '') {
            throw new InvalidAnswerException('This question requires an answer.');
        }
    }

    private function validateSelect(array $step, mixed $value): void
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidAnswerException('Choose one of the listed options.');
        }

        $options = array_keys($step['options'] ?? []);

        if (! in_array((string) $value, array_map('strval', $options), true)) {
            throw new InvalidAnswerException('Choose one of the listed options.');
        }
    }

    private function validateMultiSelect(array $step, mixed $value): void
    {
        if (! is_array($value)) {
            throw new InvalidAnswerException('Choose from the listed options.');
        }

        $options = array_map('strval', array_keys($step['options'] ?? []));

        foreach ($value as $selected) {
            if (! in_array((string) $selected, $options, true)) {
                throw new InvalidAnswerException('One of the selected options is not valid.');
            }
        }
    }

    /**
     * A required boolean question must have an EXPLICIT true/false
     * answer. WebsiteWizardController::valueFromRequest() is responsible
     * for returning null (not a defaulted false) when the field was never
     * submitted at all — this validator only confirms what reaches it is
     * a genuine boolean, and the required/null check in validate() above
     * catches a truly missing choice.
     */
    private function validateBoolean(mixed $value): void
    {
        if (! is_bool($value)) {
            throw new InvalidAnswerException('Choose yes or no.');
        }
    }

    public const MAX_FAQ_ITEMS = 20;

    public const MAX_FAQ_QUESTION = 200;

    public const MAX_FAQ_ANSWER = 1000;

    public const MAX_TESTIMONIALS = 5;

    public const MAX_TESTIMONIAL_QUOTE = 400;

    public const MAX_TESTIMONIAL_AUTHOR = 80;

    private function validateRepeatableGroup(array $step, mixed $value): void
    {
        if (! is_array($value)) {
            throw new InvalidAnswerException('That answer is not valid.');
        }

        $targetModule = $step['target_module'] ?? null;
        $isFaq = $targetModule === 'faq';
        $isTestimonial = $targetModule === 'knowledge_profile' && ($step['target_field'] ?? null) === 'testimonials';

        $maxItems = $isFaq ? self::MAX_FAQ_ITEMS : ($isTestimonial ? self::MAX_TESTIMONIALS : self::MAX_REPEATABLE_ITEMS);
        if (count($value) > $maxItems) {
            throw new InvalidAnswerException("Too many entries — the limit is {$maxItems}.");
        }

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new InvalidAnswerException('That answer is not valid.');
            }

            if ($isFaq) {
                $this->validateFaqEntry($item);

                continue;
            }

            if ($isTestimonial) {
                $this->validateTestimonialEntry($item);

                continue;
            }

            $name = $item['name'] ?? '';
            if (! is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_ITEM_NAME) {
                throw new InvalidAnswerException('Every entry needs a name of 1 to ' . self::MAX_ITEM_NAME . ' characters.');
            }

            $description = $item['description'] ?? null;
            if ($description !== null && (! is_string($description) || mb_strlen($description) > self::MAX_ITEM_DESCRIPTION)) {
                throw new InvalidAnswerException('An entry description is too long.');
            }

            if ($targetModule === 'catalog_item') {
                $this->validateCatalogItemEntry($item);
            }

            if ($targetModule === 'custom_section') {
                $this->validateCustomSectionEntry($item);
            }
        }
    }

    /**
     * Independent-review correction round 2 — bounds match
     * WebsiteSectionValidator::CustomSection's own real, generation-time
     * limits exactly, so a forged/oversized custom-section submission is
     * refused here at answer time rather than surfacing as a generation
     * failure much later. Asset ownership itself (an images.* uid
     * genuinely belonging to this Website, with the correct purpose) is
     * verified at upload/removal time — this method only bounds shape
     * and count, since uids reaching here always come from this
     * Website's own upload endpoint, never raw client input.
     */
    private function validateCustomSectionEntry(array $item): void
    {
        $body = $item['body'] ?? null;
        if ($body !== null && (! is_string($body) || mb_strlen($body) > 5000)) {
            throw new InvalidAnswerException('The custom section body is too long (max 5000 characters).');
        }

        $layout = $item['layout'] ?? 'stacked';
        if (! in_array($layout, ['stacked', 'image_left', 'image_right', 'grid'], true)) {
            throw new InvalidAnswerException('Choose a valid custom section layout.');
        }

        $images = $item['images'] ?? [];
        if (! is_array($images) || count($images) > 12) {
            throw new InvalidAnswerException('A custom section may have at most 12 images.');
        }
    }

    private function validateFaqEntry(array $item): void
    {
        $question = $item['question'] ?? '';
        if (! is_string($question) || trim($question) === '' || mb_strlen($question) > self::MAX_FAQ_QUESTION) {
            throw new InvalidAnswerException('Every FAQ entry needs a question of 1 to ' . self::MAX_FAQ_QUESTION . ' characters.');
        }

        $answer = $item['answer'] ?? '';
        if (! is_string($answer) || trim($answer) === '' || mb_strlen($answer) > self::MAX_FAQ_ANSWER) {
            throw new InvalidAnswerException('Every FAQ entry needs an answer of 1 to ' . self::MAX_FAQ_ANSWER . ' characters.');
        }
    }

    private function validateTestimonialEntry(array $item): void
    {
        $quote = $item['quote'] ?? '';
        if (! is_string($quote) || trim($quote) === '' || mb_strlen($quote) > self::MAX_TESTIMONIAL_QUOTE) {
            throw new InvalidAnswerException('Every testimonial needs a quote of 1 to ' . self::MAX_TESTIMONIAL_QUOTE . ' characters.');
        }

        $authorName = $item['author_name'] ?? '';
        if (! is_string($authorName) || trim($authorName) === '' || mb_strlen($authorName) > self::MAX_TESTIMONIAL_AUTHOR) {
            throw new InvalidAnswerException('Every testimonial needs an author name of 1 to ' . self::MAX_TESTIMONIAL_AUTHOR . ' characters.');
        }

        $authorTitle = $item['author_title'] ?? null;
        if ($authorTitle !== null && (! is_string($authorTitle) || mb_strlen($authorTitle) > self::MAX_TESTIMONIAL_AUTHOR)) {
            throw new InvalidAnswerException('A testimonial author title is too long.');
        }
    }

    private function validateCatalogItemEntry(array $item): void
    {
        $priceMinor = $item['price_minor'] ?? null;
        if ($priceMinor !== null && (! is_int($priceMinor) || $priceMinor < 0)) {
            throw new InvalidAnswerException('A package price must be a non-negative amount.');
        }

        $currencyCode = $item['currency_code'] ?? null;
        if ($currencyCode !== null && (! is_string($currencyCode) || strlen($currencyCode) !== 3)) {
            throw new InvalidAnswerException('A package currency must be a 3-letter code.');
        }

        $features = $item['features'] ?? [];
        if (! is_array($features) || count($features) > self::MAX_FEATURES) {
            throw new InvalidAnswerException('Too many included features — the limit is ' . self::MAX_FEATURES . '.');
        }

        foreach ($features as $feature) {
            if (! is_string($feature) || mb_strlen($feature) > self::MAX_FEATURE_LENGTH) {
                throw new InvalidAnswerException('An included feature is too long.');
            }
        }
    }
}
