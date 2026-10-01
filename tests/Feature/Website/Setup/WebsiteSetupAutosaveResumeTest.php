<?php

namespace Tests\Feature\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Library\Website\Setup\Exceptions\AnswerRevisionConflictException;
use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Library\Website\Setup\WebsiteSetupSessionManager;
use App\Models\QuestionnaireDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Builder redesign — proves autosave/resume: every answer is
 * persisted with an optimistic-concurrency revision counter, and a
 * resumed session lands exactly on `current_step_key`, never restarting.
 */
class WebsiteSetupAutosaveResumeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function twoStepDefinition(string $key): QuestionnaireDefinition
    {
        $definition = QuestionnaireDefinition::create(['key' => $key, 'name' => 'Two Step']);
        $steps = [
            ['key' => 'step_one', 'prompt' => 'First question', 'help_text' => null, 'input_type' => 'text', 'required' => true, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'business', 'target_field' => 'name', 'ai_instructions' => null],
            ['key' => 'step_two', 'prompt' => 'Second question', 'help_text' => null, 'input_type' => 'text', 'required' => true, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'business', 'target_field' => 'description', 'ai_instructions' => null],
        ];
        $publisher = app(QuestionnaireVersionPublisher::class);
        $publisher->publish($publisher->createDraft($definition, $steps));

        return $definition;
    }

    public function test_saving_an_answer_advances_to_the_next_step_and_bumps_the_revision(): void
    {
        [, $business] = $this->entitledTenant();
        $this->twoStepDefinition('autosave_test_1');
        $manager = app(WebsiteSetupSessionManager::class);

        $response = $manager->start($business, 'autosave_test_1');
        $this->assertSame('step_one', $response->current_step_key);
        $this->assertSame(1, $response->answers_revision);

        $response = $manager->saveAnswer($response, 'step_one', 'Snap Booth Co.', 1);

        $this->assertSame('Snap Booth Co.', $response->answers['step_one']);
        $this->assertSame('step_two', $response->current_step_key);
        $this->assertSame(2, $response->answers_revision);
    }

    public function test_a_stale_revision_is_refused_rather_than_clobbering_the_newer_write(): void
    {
        [, $business] = $this->entitledTenant();
        $this->twoStepDefinition('autosave_test_2');
        $manager = app(WebsiteSetupSessionManager::class);

        $response = $manager->start($business, 'autosave_test_2');
        $manager->saveAnswer($response, 'step_one', 'First real answer', 1);

        $this->expectException(AnswerRevisionConflictException::class);
        // Still using the stale revision 1, as if a second tab raced.
        $manager->saveAnswer($response, 'step_one', 'A conflicting stale write', 1);
    }

    public function test_resuming_lands_exactly_on_the_current_step_never_restarting(): void
    {
        [, $business] = $this->entitledTenant();
        $this->twoStepDefinition('autosave_test_3');
        $manager = app(WebsiteSetupSessionManager::class);

        $started = $manager->start($business, 'autosave_test_3');
        $manager->saveAnswer($started, 'step_one', 'An answer', 1);

        // Simulate the owner leaving and coming back: a fresh start() call
        // must resume, not create a new in_progress row, and the resumed
        // row must already be sitting on step_two.
        $resumedViaStart = $manager->start($business, 'autosave_test_3');
        $this->assertSame('step_two', $resumedViaStart->current_step_key);

        $resumedByUid = $manager->resume($business, $started->uid);
        $this->assertSame('step_two', $resumedByUid->current_step_key);
    }

    public function test_the_back_arrow_returns_to_a_previously_visited_step(): void
    {
        [, $business] = $this->entitledTenant();
        $this->twoStepDefinition('autosave_test_4');
        $manager = app(WebsiteSetupSessionManager::class);

        $response = $manager->start($business, 'autosave_test_4');
        $response = $manager->saveAnswer($response, 'step_one', 'An answer', 1);
        $this->assertSame('step_two', $response->current_step_key);

        $response = $manager->goToStep($response, 'step_one');

        $this->assertSame('step_one', $response->current_step_key);
        $this->assertSame('An answer', $response->answers['step_one'], 'Going back must not erase the previously saved answer.');
    }

    public function test_completing_requires_every_required_question_answered(): void
    {
        [, $business] = $this->entitledTenant();
        $this->twoStepDefinition('autosave_test_5');
        $manager = app(WebsiteSetupSessionManager::class);

        $response = $manager->start($business, 'autosave_test_5');
        $response = $manager->saveAnswer($response, 'step_one', 'An answer', 1);

        $this->expectException(\DomainException::class);
        $manager->complete($response);
    }

    public function test_completing_after_every_required_question_marks_it_completed(): void
    {
        [, $business] = $this->entitledTenant();
        $this->twoStepDefinition('autosave_test_6');
        $manager = app(WebsiteSetupSessionManager::class);

        $response = $manager->start($business, 'autosave_test_6');
        $response = $manager->saveAnswer($response, 'step_one', 'An answer', 1);
        $response = $manager->saveAnswer($response, 'step_two', 'Another answer', 2);

        $response = $manager->complete($response);

        $this->assertSame(QuestionnaireResponseStatus::Completed, $response->status);
        $this->assertNotNull($response->completed_at);
    }

    public function test_a_foreign_business_can_never_resume_another_businesses_session(): void
    {
        [, $business] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $this->twoStepDefinition('autosave_test_7');
        $manager = app(WebsiteSetupSessionManager::class);

        $response = $manager->start($business, 'autosave_test_7');

        $this->expectException(\DomainException::class);
        $manager->resume($otherBusiness, $response->uid);
    }
}
