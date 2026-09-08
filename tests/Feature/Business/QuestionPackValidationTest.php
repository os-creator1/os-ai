<?php

namespace Tests\Feature\Business;

use App\Models\QuestionPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §6.2 -- QuestionPack shape
 * validation, enforced at the model boundary on every create()/update()
 * so no caller can bypass it.
 */
class QuestionPackValidationTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    public function test_a_pack_cannot_target_both_industry_and_vertical(): void
    {
        $this->createVertical(['key' => 'roofing']);

        $this->expectException(ValidationException::class);
        $this->createQuestionPack([
            'applies_to_industry' => 'home_services',
            'applies_to_vertical_key' => 'roofing',
        ]);
    }

    public function test_a_pack_targeting_neither_is_valid_the_general_shape(): void
    {
        $pack = $this->createQuestionPack(['applies_to_industry' => null, 'applies_to_vertical_key' => null]);

        $this->assertNull($pack->applies_to_industry);
        $this->assertNull($pack->applies_to_vertical_key);
    }

    public function test_applies_to_industry_must_be_a_real_business_industry_value(): void
    {
        $this->expectException(ValidationException::class);
        $this->createQuestionPack(['applies_to_industry' => 'not_a_real_industry']);
    }

    public function test_every_question_field_key_must_be_a_known_field_key(): void
    {
        $this->expectException(ValidationException::class);
        $this->createQuestionPack([], [
            ['field_key' => 'not_a_real_field', 'prompt' => 'x', 'input_type' => 'text', 'options' => null, 'required' => false],
        ]);
    }

    public function test_hours_is_an_accepted_question_field_key(): void
    {
        $pack = $this->createQuestionPack([], [
            ['field_key' => 'hours', 'prompt' => 'What are your hours?', 'input_type' => 'text', 'options' => null, 'required' => false],
        ]);

        $this->assertSame('hours', $pack->questions[0]['field_key']);
    }

    public function test_question_input_type_must_be_one_of_the_five_contracted_values(): void
    {
        $this->expectException(ValidationException::class);
        $this->createQuestionPack([], [
            ['field_key' => 'brand_voice', 'prompt' => 'x', 'input_type' => 'not_a_type', 'options' => null, 'required' => false],
        ]);
    }

    public function test_a_question_requires_a_non_empty_prompt(): void
    {
        $this->expectException(ValidationException::class);
        $this->createQuestionPack([], [
            ['field_key' => 'brand_voice', 'prompt' => '', 'input_type' => 'text', 'options' => null, 'required' => false],
        ]);
    }

    public function test_a_question_requires_a_boolean_required_flag(): void
    {
        $this->expectException(ValidationException::class);
        $this->createQuestionPack([], [
            ['field_key' => 'brand_voice', 'prompt' => 'x', 'input_type' => 'text', 'options' => null],
        ]);
    }

    public function test_options_must_be_an_array_of_strings_when_present(): void
    {
        $pack = $this->createQuestionPack([], [
            ['field_key' => 'pricing_method', 'prompt' => 'How do you price?', 'input_type' => 'select', 'options' => ['fixed', 'hourly'], 'required' => true],
        ]);

        $this->assertSame(['fixed', 'hourly'], $pack->questions[0]['options']);

        $this->expectException(ValidationException::class);
        $this->createQuestionPack([], [
            ['field_key' => 'pricing_method', 'prompt' => 'x', 'input_type' => 'select', 'options' => 'not-an-array', 'required' => true],
        ]);
    }

    public function test_packs_are_a_pure_stable_key_never_a_ui_label_lookup(): void
    {
        $pack = $this->createQuestionPack(['key' => 'roofing_v1', 'applies_to_vertical_key' => null], [
            ['field_key' => 'credentials', 'prompt' => 'Do you carry a license?', 'input_type' => 'text', 'options' => null, 'required' => false],
        ]);

        $reloaded = QuestionPack::where('key', 'roofing_v1')->first();
        $this->assertSame($pack->id, $reloaded->id);
    }
}
