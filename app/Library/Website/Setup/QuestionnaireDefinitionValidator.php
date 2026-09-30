<?php

namespace App\Library\Website\Setup;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Enums\Questionnaire\QuestionType;
use DomainException;

/**
 * Independent-review correction round — before this lane, nothing ever
 * validated a QuestionnaireVersion's `definition` JSON beyond "it is an
 * array of steps." Since QuestionnaireVersionPublisher::createDraft() is
 * this codebase's only write path onto that column, this validator is
 * called from there, once, before a draft is ever persisted — a
 * malformed definition can never reach the database, let alone be
 * published and pinned into a live QuestionnaireResponse.
 *
 * Deliberately conservative and closed-world: every step's `input_type`,
 * `target_module`, and (where relevant) `target_field` must be one of a
 * fixed known set, because an unknown value here would otherwise reach
 * QuestionnaireStepResolver/WebsiteWizardController/
 * WebsiteSetupAnswerApplier as silent, unhandled input.
 */
final class QuestionnaireDefinitionValidator
{
    public const MAX_STEPS = 60;

    public const MAX_HELP_TEXT = 500;

    public const MAX_AI_INSTRUCTIONS = 2000;

    public const MAX_OPTIONS = 50;

    public const MAX_PROMPT = 300;

    public const MAX_OPTION_LABEL = 160;

    public const MAX_OPTION_VALUE = 64;

    private const VALID_TARGET_MODULES = [
        'business', 'business_location', 'knowledge_profile', 'business_service',
        'catalog_item', 'backdrop', 'website_form', 'gallery', 'answers', 'custom_section', 'faq',
    ];

    private const VALID_BUSINESS_FIELDS = ['name', 'phone', 'email', 'description'];

    /**
     * Independent-review correction round 3 — every target_module in
     * this list has NO real WebsiteSetupAnswerApplier application path
     * that reads a per-step `target_field` at all: applyLocationFields()
     * takes the whole submitted value regardless of it, and
     * applyServices()/applyPackages()/applyBackdrops()/applyForm() (and
     * the wizard's own gallery/answers/custom_section handling) never
     * read it either. A non-null target_field for one of these modules
     * would therefore be silently ignored forever — accepted at publish
     * time but never actually wired to anything — so it is refused here
     * instead ("reject business[-location] targets that have no real
     * application path", generalized to every such module).
     */
    private const MODULES_WITHOUT_TARGET_FIELD = [
        'business_location', 'business_service', 'catalog_item', 'backdrop',
        'website_form', 'gallery', 'answers', 'custom_section', 'faq',
    ];

    private const VALID_CONDITIONS = ['equals', 'not_equals', 'in', 'not_empty'];

    /** A condition that compares against a specific value has nothing to compare without one. */
    private const CONDITIONS_REQUIRING_VALUE = ['equals', 'not_equals'];

    private const OPTION_INPUT_TYPES = [QuestionType::Select->value, QuestionType::MultiSelect->value];

    /**
     * Independent-review correction round 2 — the real, closed
     * input_type <-> target_module compatibility matrix. An enum value
     * accepted by QuestionType alone was never proof that this codebase's
     * runtime (WebsiteWizardController::valueFromRequest()/
     * normalizeRepeatableItem(), QuestionnaireAnswerValidator,
     * WebsiteSetupAnswerApplier) actually has a parser/validator/
     * application path for that COMBINATION — e.g. a `repeatable_group`
     * targeting `business` would reach applyBusinessField() with an
     * array value it cannot use. Every pairing this codebase genuinely
     * implements is listed here; anything else is refused at publish
     * time rather than failing later, silently, at answer or generation
     * time.
     */
    private const COMPATIBLE_TARGET_MODULES = [
        'text' => ['business', 'business_location', 'knowledge_profile', 'answers'],
        'tel' => ['business', 'business_location', 'knowledge_profile', 'answers'],
        'email' => ['business', 'knowledge_profile', 'answers'],
        'textarea' => ['business', 'knowledge_profile', 'answers'],
        'select' => ['knowledge_profile', 'answers'],
        'multi_select' => ['website_form', 'knowledge_profile', 'answers'],
        'boolean' => ['answers', 'knowledge_profile'],
        'repeatable_group' => ['business_service', 'catalog_item', 'backdrop', 'custom_section', 'faq', 'knowledge_profile'],
        'photo_upload' => ['gallery'],
    ];

    /**
     * @param  array<int, array<string, mixed>>  $steps
     *
     * @throws DomainException
     */
    public function validate(array $steps): void
    {
        if ($steps === [] || count($steps) > self::MAX_STEPS) {
            throw new DomainException('A questionnaire must have between 1 and ' . self::MAX_STEPS . ' steps.');
        }

        $seenKeys = [];

        foreach ($steps as $position => $step) {
            $key = $step['key'] ?? null;

            if (! is_string($key) || $key === '' || preg_match('/^[a-z0-9_]{1,64}$/', $key) !== 1) {
                throw new DomainException("Step at position {$position} has an invalid key.");
            }

            if (isset($seenKeys[$key])) {
                throw new DomainException("Step key '{$key}' is used more than once.");
            }
            $seenKeys[$key] = true;

            $prompt = $step['prompt'] ?? null;
            if (! is_string($prompt) || trim($prompt) === '' || mb_strlen($prompt) > self::MAX_PROMPT) {
                throw new DomainException("Step '{$key}' is missing a prompt, or its prompt is too long.");
            }

            if (! is_bool($step['required'] ?? null)) {
                throw new DomainException("Step '{$key}' has a non-boolean required flag.");
            }

            $inputType = $step['input_type'] ?? null;
            if (! is_string($inputType) || QuestionType::tryFrom($inputType) === null) {
                throw new DomainException("Step '{$key}' has an unsupported input_type.");
            }

            $targetModule = $step['target_module'] ?? null;
            if (! is_string($targetModule) || ! in_array($targetModule, self::VALID_TARGET_MODULES, true)) {
                throw new DomainException("Step '{$key}' has an unsupported target_module.");
            }

            if (! in_array($targetModule, self::COMPATIBLE_TARGET_MODULES[$inputType] ?? [], true)) {
                throw new DomainException("Step '{$key}' combines input_type '{$inputType}' with a target_module '{$targetModule}' this codebase has no real renderer/parser/validator/application path for.");
            }

            // A null target_field is tolerated even for 'business' — it is
            // a graceful no-op there too (WebsiteSetupAnswerApplier::
            // applyBusinessField() already returns early on one); only a
            // NON-null 'business' target_field must be a real column.
            $targetField = $step['target_field'] ?? null;
            if ($targetModule === 'business' && $targetField !== null && ! in_array($targetField, self::VALID_BUSINESS_FIELDS, true)) {
                throw new DomainException("Step '{$key}' targets 'business' with an unsupported target_field.");
            } elseif ($targetModule === 'knowledge_profile') {
                $this->validateKnowledgeProfileCombination($key, $inputType, $targetField);
            } elseif (in_array($targetModule, self::MODULES_WITHOUT_TARGET_FIELD, true) && $targetField !== null) {
                throw new DomainException("Step '{$key}' targets '{$targetModule}' with a target_field, but this codebase has no application path that reads one for that module.");
            }

            $helpText = $step['help_text'] ?? null;
            if ($helpText !== null && (! is_string($helpText) || mb_strlen($helpText) > self::MAX_HELP_TEXT)) {
                throw new DomainException("Step '{$key}' has help_text that is too long.");
            }

            $aiInstructions = $step['ai_instructions'] ?? null;
            if ($aiInstructions !== null && (! is_string($aiInstructions) || mb_strlen($aiInstructions) > self::MAX_AI_INSTRUCTIONS)) {
                throw new DomainException("Step '{$key}' has ai_instructions that are too long.");
            }

            $this->validateOptions($key, $step, $inputType);
            $this->validateConditionalVisibility($key, $step, $seenKeys);
        }
    }

    /**
     * `target_field` for `knowledge_profile` must be a real
     * BusinessKnowledgeProfileFieldKey column — `hours` is deliberately
     * excluded (never a valid BusinessKnowledgeProfileManager::
     * updateFields() key; its own write path is updateLocationHours()
     * alone). A `repeatable_group` targeting `knowledge_profile` is only
     * ever wired for `testimonials` — WebsiteSetupAnswerApplier has no
     * other repeatable knowledge_profile application path.
     */
    private function validateKnowledgeProfileCombination(string $key, string $inputType, mixed $targetField): void
    {
        if (! is_string($targetField)) {
            throw new DomainException("Step '{$key}' targets 'knowledge_profile' without a target_field.");
        }

        $validFields = array_filter(
            array_map(fn (BusinessKnowledgeProfileFieldKey $case) => $case->value, BusinessKnowledgeProfileFieldKey::cases()),
            fn (string $value) => $value !== BusinessKnowledgeProfileFieldKey::Hours->value,
        );

        if (! in_array($targetField, $validFields, true)) {
            throw new DomainException("Step '{$key}' targets 'knowledge_profile' with an unsupported target_field.");
        }

        if ($inputType === QuestionType::RepeatableGroup->value && $targetField !== BusinessKnowledgeProfileFieldKey::Testimonials->value) {
            throw new DomainException("Step '{$key}' is a repeatable_group targeting 'knowledge_profile' with no application path for target_field '{$targetField}'.");
        }
    }

    private function validateOptions(string $key, array $step, string $inputType): void
    {
        $options = $step['options'] ?? null;

        if (! in_array($inputType, self::OPTION_INPUT_TYPES, true)) {
            return;
        }

        if (! is_array($options) || $options === [] || count($options) > self::MAX_OPTIONS) {
            throw new DomainException("Step '{$key}' requires 1 to " . self::MAX_OPTIONS . ' options.');
        }

        foreach ($options as $optionValue => $optionLabel) {
            if (! is_string($optionValue) && ! is_int($optionValue)) {
                throw new DomainException("Step '{$key}' has an invalid option value.");
            }

            if (is_string($optionValue) && mb_strlen($optionValue) > self::MAX_OPTION_VALUE) {
                throw new DomainException("Step '{$key}' has an option value that is too long.");
            }

            if (! is_string($optionLabel) || trim($optionLabel) === '' || mb_strlen($optionLabel) > self::MAX_OPTION_LABEL) {
                throw new DomainException("Step '{$key}' has an invalid or too-long option label.");
            }
        }
    }

    /**
     * @param  array<string, bool>  $priorKeys  every step key seen so far, in definition order
     */
    private function validateConditionalVisibility(string $key, array $step, array $priorKeys): void
    {
        $rule = $step['conditional_visibility'] ?? null;

        if ($rule === null) {
            return;
        }

        if (! is_array($rule)) {
            throw new DomainException("Step '{$key}' has an invalid conditional_visibility rule.");
        }

        $dependsOn = $rule['depends_on'] ?? null;

        if (! is_string($dependsOn) || ! isset($priorKeys[$dependsOn]) || $dependsOn === $key) {
            throw new DomainException("Step '{$key}' depends_on an unknown or non-preceding step.");
        }

        $condition = $rule['condition'] ?? 'equals';
        if (! in_array($condition, self::VALID_CONDITIONS, true)) {
            throw new DomainException("Step '{$key}' has an unsupported conditional_visibility condition.");
        }

        if ($condition === 'in' && ! is_array($rule['value'] ?? null)) {
            throw new DomainException("Step '{$key}' uses condition 'in' with a non-array value.");
        }

        // Independent-review correction round 3 — 'equals'/'not_equals'
        // compare the depended-on step's answer against a specific
        // value; with no value in the rule at all, there is nothing to
        // compare against and the rule can never mean anything
        // consistent. A scalar (including `false`) is required; `null`
        // is refused since QuestionnaireStepResolver could never
        // distinguish "compare against null" from "no value given".
        if (in_array($condition, self::CONDITIONS_REQUIRING_VALUE, true)
            && (! array_key_exists('value', $rule) || ! is_scalar($rule['value']))) {
            throw new DomainException("Step '{$key}' uses condition '{$condition}' without a comparison value.");
        }
    }
}
