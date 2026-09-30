<?php

namespace App\Library\Website\Setup;

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

    private const VALID_TARGET_MODULES = [
        'business', 'business_location', 'knowledge_profile', 'business_service',
        'catalog_item', 'backdrop', 'website_form', 'gallery', 'answers', 'custom_section',
    ];

    private const VALID_BUSINESS_FIELDS = ['name', 'phone', 'email', 'description'];

    private const VALID_CONDITIONS = ['equals', 'not_equals', 'in', 'not_empty'];

    private const OPTION_INPUT_TYPES = [QuestionType::Select->value, QuestionType::MultiSelect->value];

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

            if (! is_string($step['prompt'] ?? null) || trim($step['prompt']) === '') {
                throw new DomainException("Step '{$key}' is missing a prompt.");
            }

            $inputType = $step['input_type'] ?? null;
            if (! is_string($inputType) || QuestionType::tryFrom($inputType) === null) {
                throw new DomainException("Step '{$key}' has an unsupported input_type.");
            }

            $targetModule = $step['target_module'] ?? null;
            if (! is_string($targetModule) || ! in_array($targetModule, self::VALID_TARGET_MODULES, true)) {
                throw new DomainException("Step '{$key}' has an unsupported target_module.");
            }

            // A null target_field is tolerated even for 'business' — it is
            // a graceful no-op there too (WebsiteSetupAnswerApplier::
            // applyBusinessField() already returns early on one); only a
            // NON-null 'business' target_field must be a real column.
            $targetField = $step['target_field'] ?? null;
            if ($targetModule === 'business' && $targetField !== null && ! in_array($targetField, self::VALID_BUSINESS_FIELDS, true)) {
                throw new DomainException("Step '{$key}' targets 'business' with an unsupported target_field.");
            } elseif ($targetField !== null && (! is_string($targetField) || mb_strlen($targetField) > 64)) {
                throw new DomainException("Step '{$key}' has an invalid target_field.");
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

            if (! is_string($optionLabel) || trim($optionLabel) === '') {
                throw new DomainException("Step '{$key}' has an invalid option label.");
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
    }
}
