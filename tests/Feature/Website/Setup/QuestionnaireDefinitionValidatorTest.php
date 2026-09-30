<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Website\Setup\QuestionnaireDefinitionValidator;
use DomainException;
use Tests\TestCase;

/**
 * Independent-review correction round 2 — proves the real, closed
 * input_type <-> target_module <-> target_field compatibility matrix:
 * an enum value QuestionType accepts is not by itself proof this
 * codebase's runtime (WebsiteWizardController's parser, Questionnaire
 * AnswerValidator, WebsiteSetupAnswerApplier) actually has a working
 * path for that COMBINATION. Every malformed combination below is
 * refused at publish time, before it could ever reach a live
 * QuestionnaireResponse and fail later, silently, at answer or
 * generation time.
 */
class QuestionnaireDefinitionValidatorTest extends TestCase
{
    private function step(array $overrides = []): array
    {
        return array_merge([
            'key' => 'step_one',
            'prompt' => 'A prompt',
            'help_text' => null,
            'input_type' => 'text',
            'required' => true,
            'options' => null,
            'conditional_visibility' => null,
            'target_module' => 'business',
            'target_field' => 'name',
            'ai_instructions' => null,
        ], $overrides);
    }

    public function test_a_repeatable_group_targeting_business_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'repeatable_group', 'target_module' => 'business', 'target_field' => null]),
        ]);
    }

    public function test_a_boolean_targeting_business_service_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'boolean', 'target_module' => 'business_service', 'target_field' => null]),
        ]);
    }

    public function test_a_photo_upload_targeting_anything_but_gallery_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'photo_upload', 'target_module' => 'answers', 'target_field' => null]),
        ]);
    }

    public function test_a_repeatable_group_targeting_knowledge_profile_with_a_non_testimonials_field_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'repeatable_group', 'target_module' => 'knowledge_profile', 'target_field' => 'brand_voice']),
        ]);
    }

    public function test_a_repeatable_group_targeting_knowledge_profile_testimonials_is_accepted(): void
    {
        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'repeatable_group', 'target_module' => 'knowledge_profile', 'target_field' => 'testimonials']),
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_knowledge_profile_target_field_hours_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'select', 'target_module' => 'knowledge_profile', 'target_field' => 'hours', 'options' => ['a' => 'A']]),
        ]);
    }

    public function test_knowledge_profile_target_field_not_a_real_column_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'select', 'target_module' => 'knowledge_profile', 'target_field' => 'not_a_real_field', 'options' => ['a' => 'A']]),
        ]);
    }

    public function test_a_non_boolean_required_flag_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['required' => 'yes']),
        ]);
    }

    public function test_an_oversized_prompt_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['prompt' => str_repeat('x', QuestionnaireDefinitionValidator::MAX_PROMPT + 1)]),
        ]);
    }

    public function test_a_condition_depending_on_a_later_step_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['key' => 'first', 'conditional_visibility' => ['depends_on' => 'second', 'condition' => 'equals', 'value' => true]]),
            $this->step(['key' => 'second', 'input_type' => 'boolean', 'target_module' => 'answers', 'target_field' => null]),
        ]);
    }

    /**
     * Every input_type QuestionType still declares must have at least
     * ONE genuinely compatible target_module — proving the closed-world
     * matrix does not silently orphan a type with nowhere it can ever be
     * published to.
     */
    public function test_every_declared_input_type_has_at_least_one_compatible_target_module(): void
    {
        $validator = new QuestionnaireDefinitionValidator();

        $combinations = [
            'text' => ['target_module' => 'business', 'target_field' => 'name'],
            'tel' => ['target_module' => 'business', 'target_field' => 'phone'],
            'email' => ['target_module' => 'business', 'target_field' => 'email'],
            'textarea' => ['target_module' => 'business', 'target_field' => 'description'],
            'select' => ['target_module' => 'knowledge_profile', 'target_field' => 'brand_voice', 'options' => ['a' => 'A']],
            'multi_select' => ['target_module' => 'website_form', 'target_field' => null, 'options' => ['a' => 'A']],
            'boolean' => ['target_module' => 'answers', 'target_field' => null],
            'repeatable_group' => ['target_module' => 'catalog_item', 'target_field' => null],
            'photo_upload' => ['target_module' => 'gallery', 'target_field' => null],
        ];

        foreach ($combinations as $inputType => $overrides) {
            $validator->validate([$this->step(array_merge(['input_type' => $inputType], $overrides))]);
        }

        $this->addToAssertionCount(count($combinations));
    }

    public function test_price_or_quote_is_no_longer_a_supported_input_type(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'price_or_quote', 'target_module' => 'catalog_item', 'target_field' => null]),
        ]);
    }

    /**
     * Independent-review correction round 3 — a business_location step
     * has no real application path that ever reads target_field
     * (WebsiteSetupAnswerApplier::applyLocationFields() takes the whole
     * submitted value regardless of it), so a non-null value is refused
     * here rather than silently accepted-and-ignored forever.
     */
    public function test_business_location_with_a_non_null_target_field_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'text', 'target_module' => 'business_location', 'target_field' => 'address']),
        ]);
    }

    public function test_business_location_with_a_null_target_field_is_accepted(): void
    {
        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'text', 'target_module' => 'business_location', 'target_field' => null]),
        ]);
        $this->addToAssertionCount(1);
    }

    /**
     * Every non-business, non-knowledge_profile module has no real
     * target_field application path — a representative sample proves the
     * rule generalizes, not merely business_location.
     */
    public function test_every_module_without_a_target_field_path_refuses_a_non_null_one(): void
    {
        $validator = new QuestionnaireDefinitionValidator();

        $modules = [
            ['input_type' => 'repeatable_group', 'target_module' => 'business_service'],
            ['input_type' => 'repeatable_group', 'target_module' => 'catalog_item'],
            ['input_type' => 'repeatable_group', 'target_module' => 'backdrop'],
            ['input_type' => 'multi_select', 'target_module' => 'website_form', 'options' => ['a' => 'A']],
            ['input_type' => 'photo_upload', 'target_module' => 'gallery'],
            ['input_type' => 'boolean', 'target_module' => 'answers'],
            ['input_type' => 'repeatable_group', 'target_module' => 'custom_section'],
            ['input_type' => 'repeatable_group', 'target_module' => 'faq'],
        ];

        foreach ($modules as $overrides) {
            try {
                $validator->validate([$this->step(array_merge($overrides, ['target_field' => 'something']))]);
                $this->fail("Module '{$overrides['target_module']}' should have refused a non-null target_field.");
            } catch (DomainException) {
                // Expected.
            }
        }

        $this->addToAssertionCount(count($modules));
    }

    public function test_an_oversized_option_label_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'select', 'target_module' => 'knowledge_profile', 'target_field' => 'brand_voice', 'options' => [
                'a' => str_repeat('x', QuestionnaireDefinitionValidator::MAX_OPTION_LABEL + 1),
            ]]),
        ]);
    }

    public function test_an_oversized_option_value_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['input_type' => 'select', 'target_module' => 'knowledge_profile', 'target_field' => 'brand_voice', 'options' => [
                str_repeat('a', QuestionnaireDefinitionValidator::MAX_OPTION_VALUE + 1) => 'A label',
            ]]),
        ]);
    }

    public function test_an_equals_condition_with_no_comparison_value_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['key' => 'first', 'input_type' => 'boolean', 'target_module' => 'answers', 'target_field' => null]),
            $this->step(['key' => 'second', 'conditional_visibility' => ['depends_on' => 'first', 'condition' => 'equals']]),
        ]);
    }

    public function test_a_not_equals_condition_with_no_comparison_value_is_refused(): void
    {
        $this->expectException(DomainException::class);

        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['key' => 'first', 'input_type' => 'boolean', 'target_module' => 'answers', 'target_field' => null]),
            $this->step(['key' => 'second', 'conditional_visibility' => ['depends_on' => 'first', 'condition' => 'not_equals']]),
        ]);
    }

    public function test_an_equals_condition_with_a_false_comparison_value_is_accepted(): void
    {
        (new QuestionnaireDefinitionValidator())->validate([
            $this->step(['key' => 'first', 'input_type' => 'boolean', 'target_module' => 'answers', 'target_field' => null]),
            $this->step(['key' => 'second', 'conditional_visibility' => ['depends_on' => 'first', 'condition' => 'equals', 'value' => false]]),
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_the_actual_seeded_photobooth_definition_publishes_cleanly(): void
    {
        (new QuestionnaireDefinitionValidator())->validate(\Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder::steps());
        $this->addToAssertionCount(1);
    }
}
