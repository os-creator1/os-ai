<?php

namespace Tests\Feature\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Enums\Questionnaire\QuestionnaireVersionState;
use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Library\Website\Setup\WebsiteSetupSessionManager;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Builder redesign — Niche Builder foundation. Proves the
 * versioning mechanics mirrored from AutomationWorkflowVersion/
 * AutomationEnrollment: draft/publish/supersede, at-most-one-of-each
 * enforced by the guard columns, and — the core requirement — an
 * in-progress QuestionnaireResponse stays pinned to the version it
 * started on even after a newer version is published underneath it.
 */
class QuestionnaireVersioningTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function simpleSteps(): array
    {
        return [
            ['key' => 'business_name', 'prompt' => 'What is your business called?', 'help_text' => null, 'input_type' => 'text', 'required' => true, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'business', 'target_field' => 'name', 'ai_instructions' => null],
        ];
    }

    public function test_publishing_a_draft_promotes_it_and_supersedes_the_prior_published_version(): void
    {
        $definition = QuestionnaireDefinition::create(['key' => 'test_questionnaire', 'name' => 'Test Questionnaire']);
        $publisher = app(QuestionnaireVersionPublisher::class);

        $v1Draft = $publisher->createDraft($definition, $this->simpleSteps());
        $v1 = $publisher->publish($v1Draft);

        $this->assertSame(QuestionnaireVersionState::Published, $v1->state);
        $this->assertNotNull($v1->published_at);

        $v2Draft = $publisher->createDraft($definition, $this->simpleSteps());
        $v2 = $publisher->publish($v2Draft);

        $this->assertSame(QuestionnaireVersionState::Published, $v2->state);
        $this->assertSame(QuestionnaireVersionState::Superseded, $v1->fresh()->state);
    }

    public function test_a_definition_can_never_have_two_draft_versions_at_once(): void
    {
        $definition = QuestionnaireDefinition::create(['key' => 'test_questionnaire_2', 'name' => 'Test Questionnaire 2']);
        $publisher = app(QuestionnaireVersionPublisher::class);

        $publisher->createDraft($definition, $this->simpleSteps());

        $this->expectException(\DomainException::class);
        $publisher->createDraft($definition, $this->simpleSteps());
    }

    public function test_only_a_draft_version_can_be_published(): void
    {
        $definition = QuestionnaireDefinition::create(['key' => 'test_questionnaire_3', 'name' => 'Test Questionnaire 3']);
        $publisher = app(QuestionnaireVersionPublisher::class);

        $draft = $publisher->createDraft($definition, $this->simpleSteps());
        $published = $publisher->publish($draft);

        $this->expectException(\DomainException::class);
        $publisher->publish($published);
    }

    public function test_an_in_progress_response_stays_pinned_to_its_starting_version_after_a_newer_one_publishes(): void
    {
        [, $business] = $this->entitledTenant();
        $definition = QuestionnaireDefinition::create(['key' => 'test_questionnaire_4', 'name' => 'Test Questionnaire 4']);
        $publisher = app(QuestionnaireVersionPublisher::class);

        $v1 = $publisher->publish($publisher->createDraft($definition, $this->simpleSteps()));

        $sessionManager = app(WebsiteSetupSessionManager::class);
        $response = $sessionManager->start($business, 'test_questionnaire_4');

        $this->assertSame($v1->id, $response->questionnaire_version_id);

        // The platform publishes a new version while the session is mid-flight.
        $v2Steps = [
            ['key' => 'business_name', 'prompt' => 'Changed prompt', 'help_text' => null, 'input_type' => 'text', 'required' => true, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'business', 'target_field' => 'name', 'ai_instructions' => null],
            ['key' => 'extra_question', 'prompt' => 'A brand new question', 'help_text' => null, 'input_type' => 'email', 'required' => false, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'business', 'target_field' => 'email', 'ai_instructions' => null],
        ];
        $v2 = $publisher->publish($publisher->createDraft($definition, $v2Steps));

        $response = $response->fresh();
        $this->assertSame($v1->id, $response->questionnaire_version_id, 'A mid-flight session must never be repointed to a newer version.');
        $this->assertNotSame($v2->id, $response->questionnaire_version_id);
        $this->assertCount(1, $response->version->steps(), 'The response must still read the ORIGINAL version\'s question tree, not the new one.');
    }

    public function test_starting_a_session_twice_for_the_same_business_resumes_rather_than_duplicates(): void
    {
        [, $business] = $this->entitledTenant();
        $definition = QuestionnaireDefinition::create(['key' => 'test_questionnaire_5', 'name' => 'Test Questionnaire 5']);
        $publisher = app(QuestionnaireVersionPublisher::class);
        $publisher->publish($publisher->createDraft($definition, $this->simpleSteps()));

        $sessionManager = app(WebsiteSetupSessionManager::class);
        $first = $sessionManager->start($business, 'test_questionnaire_5');
        $second = $sessionManager->start($business, 'test_questionnaire_5');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, QuestionnaireResponse::where('business_id', $business->id)->count());
    }

    public function test_starting_a_session_before_any_version_is_published_fails_clearly(): void
    {
        [, $business] = $this->entitledTenant();
        QuestionnaireDefinition::create(['key' => 'test_questionnaire_6', 'name' => 'Test Questionnaire 6']);

        $this->expectException(\DomainException::class);
        app(WebsiteSetupSessionManager::class)->start($business, 'test_questionnaire_6');
    }
}
