<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Website\Setup\Exceptions\InvalidAnswerException;
use App\Library\Website\Setup\QuestionnaireAnswerValidator;
use Tests\TestCase;

/**
 * Independent-review correction round — before this lane, only
 * `template_key` was ever validated on the wizard's HTTP surface; every
 * question answer was trusted verbatim. Proves the closed set of rules
 * QuestionnaireAnswerValidator now enforces, in isolation (pure/
 * stateless, no DB needed).
 */
class QuestionnaireAnswerValidatorTest extends TestCase
{
    private function selectStep(bool $required = true): array
    {
        return ['input_type' => 'select', 'required' => $required, 'options' => ['playful' => 'Playful', 'elegant' => 'Elegant']];
    }

    public function test_a_forged_select_value_outside_the_declared_options_is_refused(): void
    {
        $this->expectException(InvalidAnswerException::class);

        (new QuestionnaireAnswerValidator())->validate($this->selectStep(), 'forged_value_not_in_options');
    }

    public function test_a_declared_select_option_is_accepted(): void
    {
        (new QuestionnaireAnswerValidator())->validate($this->selectStep(), 'playful');
        $this->addToAssertionCount(1);
    }

    public function test_a_forged_multi_select_value_is_refused(): void
    {
        $step = ['input_type' => 'multi_select', 'required' => false, 'options' => ['name' => 'Name', 'phone' => 'Phone']];

        $this->expectException(InvalidAnswerException::class);

        (new QuestionnaireAnswerValidator())->validate($step, ['name', 'forged']);
    }

    public function test_a_required_boolean_with_no_explicit_choice_is_refused(): void
    {
        $step = ['input_type' => 'boolean', 'required' => true];

        $this->expectException(InvalidAnswerException::class);

        // null is what the controller now returns for a genuinely
        // unsubmitted boolean field — it must never silently become false.
        (new QuestionnaireAnswerValidator())->validate($step, null);
    }

    public function test_a_required_boolean_explicitly_answered_false_is_accepted(): void
    {
        $step = ['input_type' => 'boolean', 'required' => true];

        (new QuestionnaireAnswerValidator())->validate($step, false);
        $this->addToAssertionCount(1);
    }

    public function test_an_oversized_repeatable_group_submission_is_refused(): void
    {
        $step = ['input_type' => 'repeatable_group', 'required' => false, 'target_module' => 'business_service'];
        $items = array_fill(0, QuestionnaireAnswerValidator::MAX_REPEATABLE_ITEMS + 1, ['key' => 'x', 'name' => 'Item']);

        $this->expectException(InvalidAnswerException::class);

        (new QuestionnaireAnswerValidator())->validate($step, $items);
    }

    public function test_a_repeatable_group_entry_with_no_name_is_refused(): void
    {
        $step = ['input_type' => 'repeatable_group', 'required' => false, 'target_module' => 'business_service'];

        $this->expectException(InvalidAnswerException::class);

        (new QuestionnaireAnswerValidator())->validate($step, [['key' => 'x', 'name' => '   ']]);
    }

    public function test_a_package_entry_with_a_negative_price_is_refused(): void
    {
        $step = ['input_type' => 'repeatable_group', 'required' => false, 'target_module' => 'catalog_item'];

        $this->expectException(InvalidAnswerException::class);

        (new QuestionnaireAnswerValidator())->validate($step, [['key' => 'x', 'name' => 'Pkg', 'price_minor' => -100]]);
    }

    public function test_a_required_text_field_with_an_empty_string_is_refused(): void
    {
        $step = ['input_type' => 'text', 'required' => true];

        $this->expectException(InvalidAnswerException::class);

        (new QuestionnaireAnswerValidator())->validate($step, '   ');
    }

    public function test_an_invalid_email_format_is_refused(): void
    {
        $step = ['input_type' => 'email', 'required' => true];

        $this->expectException(InvalidAnswerException::class);

        (new QuestionnaireAnswerValidator())->validate($step, 'not-an-email');
    }

    public function test_an_optional_question_left_entirely_unanswered_is_accepted(): void
    {
        (new QuestionnaireAnswerValidator())->validate(['input_type' => 'text', 'required' => false], null);
        $this->addToAssertionCount(1);
    }
}
