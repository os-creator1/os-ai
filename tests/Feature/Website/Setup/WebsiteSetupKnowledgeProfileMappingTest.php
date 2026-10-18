<?php

namespace Tests\Feature\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Library\Website\Setup\WebsiteSetupAnswerApplier;
use App\Library\Website\Setup\WizardPrefill;
use App\Models\BusinessKnowledgeProfile;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use App\Models\QuestionnaireVersion;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV3Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Every choice a photo-booth questionnaire offers for a `knowledge_profile` question must survive the
 * canonical Business Knowledge Profile validation. "Book now" (`primary_cta`) used to be rejected
 * ("Invalid primary_conversion_goal value."), failing Generate before any attempt existed.
 */
class WebsiteSetupKnowledgeProfileMappingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    /** @return array<string, array{0: array<int, array<string, mixed>>}> */
    public static function questionnaireVersions(): array
    {
        return [
            'v1' => [PhotoboothWebsiteSetupQuestionnaireSeeder::steps()],
            'v2' => [PhotoboothWebsiteSetupQuestionnaireV2Seeder::steps()],
            'v3' => [PhotoboothWebsiteSetupQuestionnaireV3Seeder::steps()],
        ];
    }

    private function responseFor($business, $website, array $steps, array $answers): QuestionnaireResponse
    {
        $definition = QuestionnaireDefinition::create(['key' => 'profile_map_' . uniqid('', true), 'name' => 'Profile mapping']);
        $version = QuestionnaireVersion::create([
            'questionnaire_definition_id' => $definition->id,
            'version_number' => 1,
            'state' => 'published',
            'definition' => ['steps' => $steps],
            'published_at' => now(),
        ]);

        return QuestionnaireResponse::create([
            'business_id' => $business->id,
            'website_id' => $website->id,
            'questionnaire_definition_id' => $definition->id,
            'questionnaire_version_id' => $version->id,
            'status' => QuestionnaireResponseStatus::InProgress,
            'answers' => $answers,
            'answers_revision' => 1,
            'started_at' => now(),
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('questionnaireVersions')]
    public function test_every_offered_knowledge_profile_choice_applies_in_every_questionnaire_version(array $steps): void
    {
        $applied = 0;

        foreach ($steps as $step) {
            if (($step['target_module'] ?? null) !== 'knowledge_profile' || ! in_array($step['input_type'], ['select', 'multi_select'], true)) {
                continue;
            }

            // Each single choice, and — for a multi-select — every choice at once.
            $choices = array_map(fn ($key) => $step['input_type'] === 'select' ? (string) $key : [(string) $key], array_keys($step['options']));
            if ($step['input_type'] === 'multi_select') {
                $choices[] = array_map('strval', array_keys($step['options']));
            }

            foreach ($choices as $choice) {
                [$customer, $business] = $this->entitledTenant();
                $website = $this->createWebsite($business);
                $response = $this->responseFor($business, $website, [$step], [$step['key'] => $choice]);

                app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);
                $applied++;

                $this->assertSame($choice, $response->fresh()->answers[$step['key']], 'The saved answer is never rewritten.');
            }
        }

        $this->assertGreaterThan(0, $applied, 'The questionnaire has no select-style knowledge_profile question to exercise.');
    }

    public function test_every_supported_primary_cta_choice_applies_and_book_now_is_a_calendar_booking(): void
    {
        $expected = ['quote_request' => 'quote_request', 'call' => 'call', 'book_now' => 'calendar_booking'];
        $step = ['key' => 'primary_cta', 'target_module' => 'knowledge_profile', 'target_field' => 'primary_conversion_goal', 'input_type' => 'select', 'options' => ['quote_request' => 'Request a quote', 'call' => 'Call now', 'book_now' => 'Book now']];

        foreach ($expected as $answer => $goal) {
            [$customer, $business] = $this->entitledTenant();
            $website = $this->createWebsite($business);
            $response = $this->responseFor($business, $website, [$step], ['primary_cta' => $answer]);

            app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);

            $this->assertSame($goal, BusinessKnowledgeProfile::where('business_id', $business->id)->first()->primary_conversion_goal->value, $answer);
            $this->assertSame($answer, $response->fresh()->answers['primary_cta'], 'The generator still reads the raw CTA answer.');
        }
    }

    public function test_an_unknown_primary_goal_is_still_rejected_by_canonical_validation(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $step = ['key' => 'primary_cta', 'target_module' => 'knowledge_profile', 'target_field' => 'primary_conversion_goal', 'input_type' => 'select', 'options' => ['nonsense' => 'Nonsense']];
        $response = $this->responseFor($business, $website, [$step], ['primary_cta' => 'nonsense']);

        $this->expectException(ValidationException::class);
        app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);
    }

    public function test_a_previously_applied_book_now_prefills_as_book_now_again(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $step = ['key' => 'primary_cta', 'target_module' => 'knowledge_profile', 'target_field' => 'primary_conversion_goal', 'input_type' => 'select', 'options' => ['quote_request' => 'Request a quote', 'call' => 'Call now', 'book_now' => 'Book now']];
        BusinessKnowledgeProfile::create(['business_id' => $business->id, 'primary_conversion_goal' => 'calendar_booking']);

        $this->assertSame(['primary_cta' => 'book_now'], WizardPrefill::forSteps($business, [$step], []));
    }
}
