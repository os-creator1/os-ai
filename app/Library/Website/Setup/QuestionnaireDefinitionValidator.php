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

    public const MAX_PROMPT = 300;

    public const MAX_OPTION_LABEL = 160;

    public const MAX_OPTION_VALUE = 64;

    private const VALID_TARGET_MODULES = [
        'business', 'business_location', 'knowledge_profile', 'business_service',
        'catalog_item', 'backdrop', 'website_form', 'gallery', 'answers', 'custom_section', 'faq',
    ];

    /**
     * Independent-review correction round 4 (item 3) — the exact accepted
     * input_type(s) for every Business column a questionnaire step may
     * target. Previously ANY of text/tel/email/textarea could target ANY
     * of these 4 columns (e.g. a `tel` step writing into `description`),
     * which would reach WebsiteSetupAnswerApplier::applyBusinessField()
     * and silently store a value of the wrong shape for that column.
     */
    private const BUSINESS_FIELD_TYPES = [
        'name' => ['text'],
        'phone' => ['tel'],
        'email' => ['email'],
        'description' => ['text', 'textarea'],
    ];

    /**
     * Independent-review correction round 4 (item 3) — the exact accepted
     * input_type(s) for every BusinessKnowledgeProfileFieldKey, derived
     * directly from BusinessKnowledgeProfileManager::normalizeField()'s
     * own real per-field normalization:
     *  - boolean-normalized (`financing_available`) -> `boolean` only;
     *  - enum-normalized (`vertical_key`, `pricing_method`,
     *    `primary_conversion_goal`) -> `select` only (options are a
     *    platform-owner-defined bounded list the wizard already renders
     *    as a dropdown; a `text` step could never match a real enum
     *    value reliably);
     *  - bounded string-list (`differentiators`, `customer_problems`,
     *    `prohibited_claims`) -> `multi_select` only, whose own value
     *    shape (`array_values($request->input('value', []))`) is exactly
     *    the flat `array<string>` these fields normalize — never
     *    `repeatable_group`, whose generic shape is an array of
     *    name/description OBJECTS, not bare strings;
     *  - free string (`ideal_customers`, `warranties_guarantees`,
     *    `brand_voice`) -> `text`/`textarea` (`brand_voice` also accepts
     *    `select`, matching the seeded Photobooth definition's own
     *    bounded "brand personality" dropdown — the manager itself never
     *    restricts it to an enum, so a platform owner may choose either
     *    shape);
     *  - `conversion_target` (a validated tel:/mailto:/https: string) ->
     *    `text`;
     *  - `testimonials` -> `repeatable_group` only — the ONE field this
     *    codebase's normalizeRepeatableItem() gives its own real object
     *    shape (quote/author_name/author_title) matching
     *    normalizeTestimonials()'s expectation exactly.
     *
     * Deliberately ABSENT (never valid for any input_type today, so any
     * step targeting them is refused at publish time): `offers`,
     * `credentials` (each normalizes an array of OBJECTS with fields this
     * generic wizard form has no way to collect), `years_operating` (an
     * integer — no numeric QuestionType exists), `growth_priority_
     * service_ids`/`growth_priority_location_ids` (arrays of this
     * Business's OWN real record ids — no generic wizard input offers a
     * dynamic choice from the Business's own services/locations). `hours`
     * is never valid for any module (its only write path is
     * updateLocationHours(), never updateFields()).
     */
    private const KNOWLEDGE_PROFILE_FIELD_TYPES = [
        'vertical_key' => ['select'],
        'pricing_method' => ['select'],
        'financing_available' => ['boolean'],
        'differentiators' => ['multi_select'],
        'ideal_customers' => ['text', 'textarea'],
        'customer_problems' => ['multi_select'],
        'warranties_guarantees' => ['text', 'textarea'],
        'primary_conversion_goal' => ['select'],
        'conversion_target' => ['text'],
        'brand_voice' => ['select', 'text', 'textarea'],
        'prohibited_claims' => ['multi_select'],
        'testimonials' => ['repeatable_group'],
    ];

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
        // A flat list of strings: service areas land on the primary
        // location's service_area_cities; `answers` keeps a list in the
        // response only.
        'string_list' => ['business_location', 'answers'],
        'catalog_selection' => ['catalog_item'],
    ];

    /** A screen groups consecutive steps onto one wizard screen (presentation only). */
    private const MAX_STEPS_PER_SCREEN = 6;

    private const MAX_CATEGORIES = 20;

    /**
     * The only steps that may carry a niche-defined `categories`
     * vocabulary (value => label): backdrop entries and gallery photos
     * choose their category from it instead of typing free text.
     */
    private const CATEGORY_TARGETS = [
        'repeatable_group' => ['backdrop'],
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

            // Independent-review correction round 4 (item 3) — a null
            // target_field for 'business' is no longer tolerated: it
            // reaches WebsiteSetupAnswerApplier::applyBusinessField(),
            // which silently no-ops on a null field — a step that writes
            // nowhere at all is refused here rather than published as a
            // dead question.
            $targetField = $step['target_field'] ?? null;
            if ($targetModule === 'business') {
                if (! is_string($targetField) || ! isset(self::BUSINESS_FIELD_TYPES[$targetField])) {
                    throw new DomainException("Step '{$key}' targets 'business' with an unsupported or missing target_field.");
                }

                if (! in_array($inputType, self::BUSINESS_FIELD_TYPES[$targetField], true)) {
                    throw new DomainException("Step '{$key}' combines input_type '{$inputType}' with business field '{$targetField}', which has no real application path for that type.");
                }
            } elseif ($targetModule === 'knowledge_profile') {
                if (! is_string($targetField) || ! isset(self::KNOWLEDGE_PROFILE_FIELD_TYPES[$targetField])) {
                    throw new DomainException("Step '{$key}' targets 'knowledge_profile' with an unsupported or missing target_field.");
                }

                if (! in_array($inputType, self::KNOWLEDGE_PROFILE_FIELD_TYPES[$targetField], true)) {
                    throw new DomainException("Step '{$key}' combines input_type '{$inputType}' with knowledge_profile field '{$targetField}', which has no real application path for that type.");
                }
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
            $this->validateCategories($key, $step, $inputType, $targetModule);
            $this->validateConditionalVisibility($key, $step, $seenKeys);
        }

        $this->validateScreens($steps);
    }

    /**
     * A step's optional `screen` groups it with the consecutive steps that
     * share the same screen key onto ONE wizard screen. Steps stay atomic
     * (own key, validation, answer and application path) — a screen is only
     * presentation, so a screen's steps must be adjacent, bounded, and
     * never conditional on a step of the same screen (the owner could not
     * see the dependent field appear without leaving the screen).
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function validateScreens(array $steps): void
    {
        $closedScreens = [];
        $currentScreen = null;
        $currentSize = 0;
        $currentKeys = [];

        foreach ($steps as $step) {
            $screen = $step['screen'] ?? null;
            $key = (string) $step['key'];

            if ($screen !== null && (! is_string($screen) || preg_match('/^[a-z0-9_]{1,64}$/', $screen) !== 1)) {
                throw new DomainException("Step '{$key}' has an invalid screen key.");
            }

            if ($screen !== $currentScreen) {
                if ($currentScreen !== null) {
                    $closedScreens[$currentScreen] = true;
                }

                if ($screen !== null && isset($closedScreens[$screen])) {
                    throw new DomainException("Screen '{$screen}' is split: its steps must be consecutive.");
                }

                $currentScreen = $screen;
                $currentSize = 0;
                $currentKeys = [];
            }

            if ($screen !== null) {
                if (++$currentSize > self::MAX_STEPS_PER_SCREEN) {
                    throw new DomainException("Screen '{$screen}' has more than " . self::MAX_STEPS_PER_SCREEN . ' steps.');
                }

                $dependsOn = $step['conditional_visibility']['depends_on'] ?? null;
                if (is_string($dependsOn) && isset($currentKeys[$dependsOn])) {
                    throw new DomainException("Step '{$key}' depends on a step of its own screen '{$screen}'.");
                }

                $currentKeys[$key] = true;
            }
        }
    }

    private function validateCategories(string $key, array $step, string $inputType, string $targetModule): void
    {
        $categories = $step['categories'] ?? null;

        if ($categories === null) {
            return;
        }

        if (! in_array($targetModule, self::CATEGORY_TARGETS[$inputType] ?? [], true)) {
            throw new DomainException("Step '{$key}' declares categories, which only backdrop and gallery steps support.");
        }

        if (! is_array($categories) || $categories === [] || count($categories) > self::MAX_CATEGORIES) {
            throw new DomainException("Step '{$key}' requires 1 to " . self::MAX_CATEGORIES . ' categories.');
        }

        if (! array_is_list($categories)) {
            throw new DomainException("Step '{$key}' must declare categories as an ordered list of {value, label} entries.");
        }

        $seen = [];
        foreach ($categories as $category) {
            $value = is_array($category) ? ($category['value'] ?? null) : null;
            $label = is_array($category) ? ($category['label'] ?? null) : null;

            if (! is_string($value) || preg_match('/^[a-z0-9_]{1,40}$/', $value) !== 1 || isset($seen[$value])) {
                throw new DomainException("Step '{$key}' has an invalid or repeated category value.");
            }
            $seen[$value] = true;

            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > self::MAX_OPTION_LABEL) {
                throw new DomainException("Step '{$key}' has an invalid or too-long category label.");
            }
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

            // Independent-review correction round 4 (item 3) — a numeric
            // option key is stored as a PHP array key, which is PHP's own
            // actual on-disk/in-memory shape for this JSON object; it
            // deserves the same bound a string key already has, not an
            // unexamined pass purely because it happens to be an int.
            if (is_int($optionValue) && ($optionValue < 0 || mb_strlen((string) $optionValue) > self::MAX_OPTION_VALUE)) {
                throw new DomainException("Step '{$key}' has an option value that is out of bounds.");
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
