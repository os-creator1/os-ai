<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Website\Setup\QuestionnaireStepResolver;
use Tests\TestCase;

/**
 * Website Builder redesign — proves QuestionnaireStepResolver's
 * conditional-visibility evaluation in isolation (pure/stateless, no DB
 * needed): the Photobooth Backdrops step only appears once an earlier
 * "do you offer backdrops?" answer is true, and disappears again if that
 * answer changes.
 */
class QuestionnaireConditionalStepsTest extends TestCase
{
    private function steps(): array
    {
        return [
            ['key' => 'offers_backdrops', 'prompt' => 'Do you offer backdrops?', 'help_text' => null, 'input_type' => 'boolean', 'required' => true, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'answers', 'target_field' => null, 'ai_instructions' => null],
            ['key' => 'backdrops', 'prompt' => 'Tell us about your backdrops', 'help_text' => null, 'input_type' => 'repeatable_group', 'required' => true, 'options' => null, 'conditional_visibility' => ['depends_on' => 'offers_backdrops', 'condition' => 'equals', 'value' => true], 'target_module' => 'backdrops', 'target_field' => null, 'ai_instructions' => null],
            ['key' => 'gallery', 'prompt' => 'Show your work', 'help_text' => null, 'input_type' => 'photo_upload', 'required' => false, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'gallery', 'target_field' => null, 'ai_instructions' => null],
        ];
    }

    public function test_the_conditional_step_is_hidden_when_its_dependency_is_unanswered(): void
    {
        $resolver = new QuestionnaireStepResolver();

        $visible = $resolver->visibleSteps($this->steps(), []);

        $this->assertSame(['offers_backdrops', 'gallery'], array_column($visible, 'key'));
    }

    public function test_the_conditional_step_appears_once_its_dependency_answer_matches(): void
    {
        $resolver = new QuestionnaireStepResolver();

        $visible = $resolver->visibleSteps($this->steps(), ['offers_backdrops' => true]);

        $this->assertSame(['offers_backdrops', 'backdrops', 'gallery'], array_column($visible, 'key'));
    }

    public function test_the_conditional_step_disappears_again_if_the_dependency_answer_changes(): void
    {
        $resolver = new QuestionnaireStepResolver();

        $visible = $resolver->visibleSteps($this->steps(), ['offers_backdrops' => false]);

        $this->assertSame(['offers_backdrops', 'gallery'], array_column($visible, 'key'));
    }

    public function test_next_step_key_skips_a_hidden_conditional_step(): void
    {
        $resolver = new QuestionnaireStepResolver();

        $next = $resolver->nextStepKey($this->steps(), ['offers_backdrops' => false], 'offers_backdrops');

        $this->assertSame('gallery', $next);
    }

    public function test_next_step_key_includes_a_visible_conditional_step(): void
    {
        $resolver = new QuestionnaireStepResolver();

        $next = $resolver->nextStepKey($this->steps(), ['offers_backdrops' => true], 'offers_backdrops');

        $this->assertSame('backdrops', $next);
    }

    public function test_a_hidden_required_step_never_blocks_completion(): void
    {
        $resolver = new QuestionnaireStepResolver();

        // offers_backdrops answered false -> backdrops step hidden and
        // therefore not required, even though its own required flag is true.
        $isComplete = $resolver->isComplete($this->steps(), ['offers_backdrops' => false]);

        $this->assertTrue($isComplete);
    }

    public function test_a_visible_required_step_with_no_answer_blocks_completion(): void
    {
        $resolver = new QuestionnaireStepResolver();

        $isComplete = $resolver->isComplete($this->steps(), ['offers_backdrops' => true]);

        $this->assertFalse($isComplete);
    }
}
