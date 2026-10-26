<?php

namespace Tests\Feature\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireVersionState;
use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Models\QuestionnaireDefinition;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV3Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * How the `area_details` capability reaches an environment that already runs an older v3: the seeder publishes a NEW version,
 * pinned sessions and their answers stay on the version they started with, a leftover draft is brought up to date instead of
 * being published stale, and running the seeder again changes nothing.
 */
class AreaDetailsQuestionnaireActivationTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;
    use RefreshDatabase;

    private function withoutAreaDetails(array $steps): array
    {
        return array_map(function (array $step) {
            unset($step['area_details']);

            return $step;
        }, $steps);
    }

    private function areaStep(array $steps): array
    {
        return collect($steps)->firstWhere('key', 'service_area_cities');
    }

    public function test_activation_publishes_a_new_version_and_leaves_pinned_sessions_alone(): void
    {
        $this->seedGuidedWizard();
        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();

        // Production today: a published v3 WITHOUT the capability, an owner mid-setup pinned to it, and a stale draft.
        $current = $definition->publishedVersion();
        $olderSteps = $this->withoutAreaDetails($current->storedSteps());
        $current->forceFill(['definition' => ['steps' => $olderSteps]])->save();
        $this->tenant(['name' => 'Glow Booths'], ['city' => 'Spokane', 'region' => 'WA']);
        $this->continueStep('base_location', ['value' => ['city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US']])->assertOk();
        $this->continueStep('service_area_cities', ['value' => ['Spokane, WA', 'Cheney, WA']])->assertOk();
        $session = $this->wizardSession();
        $answersBefore = $session->answers;
        $this->assertSame($current->id, $session->questionnaire_version_id);
        $this->assertArrayNotHasKey('area_details', $this->areaStep($session->version->steps()), 'The pinned version has no panel.');
        $stale = app(QuestionnaireVersionPublisher::class)->createDraft($definition, $olderSteps);

        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);

        $definition = $definition->fresh();
        $published = $definition->publishedVersion();
        $this->assertTrue($published->steps()[array_search('service_area_cities', array_column($published->steps(), 'key'), true)]['area_details'], 'The new version carries area_details.');
        $this->assertGreaterThan($current->version_number, $published->version_number);
        $this->assertNull($definition->draftVersion(), 'The leftover draft was consumed, not left behind.');
        $this->assertSame($stale->id, $published->id, 'The draft itself was brought up to date and published (never published stale).');
        $this->assertSame(QuestionnaireVersionState::Superseded, $current->fresh()->state);
        $this->assertSame($olderSteps, $current->fresh()->storedSteps(), 'The old version is immutable.');

        $session = $this->wizardSession()->fresh();
        $this->assertSame($current->id, $session->questionnaire_version_id, 'The session stays pinned.');
        $this->assertSame($answersBefore, $session->answers, 'Answers are untouched.');
        $this->assertArrayNotHasKey('area_details', $this->areaStep($session->version->steps()));

        // Idempotent: a second run publishes nothing.
        $count = $definition->versions()->count();
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);
        $this->assertSame($count, $definition->versions()->count());
        $this->assertSame($published->id, $definition->fresh()->publishedVersion()->id);
    }

    public function test_a_clean_environment_gets_the_capability_in_its_first_v3(): void
    {
        $this->seedGuidedWizard();
        $published = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail()->publishedVersion();

        $this->assertTrue($this->areaStep($published->steps())['area_details']);
    }
}
