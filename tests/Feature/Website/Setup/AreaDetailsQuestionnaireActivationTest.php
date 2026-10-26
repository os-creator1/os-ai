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

    public function test_reactivating_a_superseded_tree_publishes_a_new_version_and_never_edits_history(): void
    {
        $this->seedGuidedWizard();                                       // A: the tree in code is published
        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $a = $definition->publishedVersion();
        $aSteps = $a->storedSteps();
        $this->assertTrue($this->areaStep($a->steps())['area_details']);

        // B: somebody publishes a different tree (here: without the capability), superseding A.
        $publisher = app(QuestionnaireVersionPublisher::class);
        $b = $publisher->publish($publisher->createDraft($definition, $this->withoutAreaDetails($aSteps)));
        $this->assertSame(QuestionnaireVersionState::Superseded, $a->fresh()->state);
        $this->assertSame($b->id, $definition->fresh()->publishedVersion()->id);
        $count = $definition->versions()->count();

        // A again: the old A row only matches the tree, it is not the live version, so a NEW version is published.
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);

        $live = $definition->fresh()->publishedVersion();
        $this->assertSame($count + 1, $definition->versions()->count());
        $this->assertNotSame($a->id, $live->id);
        $this->assertGreaterThan($b->version_number, $live->version_number);
        $this->assertTrue($this->areaStep($live->steps())['area_details']);
        $this->assertSame(QuestionnaireVersionState::Superseded, $a->fresh()->state, 'The old A row stays superseded.');
        $this->assertSame(QuestionnaireVersionState::Superseded, $b->fresh()->state);
        $this->assertSame($aSteps, $a->fresh()->storedSteps());
        $this->assertSame($this->withoutAreaDetails($aSteps), $b->fresh()->storedSteps(), 'No published or superseded version is rewritten.');

        // Repeated seeding is a no-op while the live version already matches.
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);
        $this->assertSame($count + 1, $definition->versions()->count());
        $this->assertSame($live->id, $definition->fresh()->publishedVersion()->id);
        $this->assertNull($definition->fresh()->draftVersion());
    }

    public function test_activation_touches_no_website_and_new_customers_get_the_latest_version(): void
    {
        $this->seedGuidedWizard();
        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $current = $definition->publishedVersion();
        $current->forceFill(['definition' => ['steps' => $this->withoutAreaDetails($current->storedSteps())]])->save();

        $this->tenant(['name' => 'Glow Booths'], ['city' => 'Spokane', 'region' => 'WA']);
        $website = \App\Models\Website::where("business_id", $this->business->id)->firstOrFail();
        $before = [\App\Models\Website::query()->orderBy('id')->get()->toArray(), \App\Models\WebsitePage::query()->count(), \App\Models\WebsiteRevision::query()->count()];

        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);

        $this->assertSame($before, [\App\Models\Website::query()->orderBy('id')->get()->toArray(), \App\Models\WebsitePage::query()->count(), \App\Models\WebsiteRevision::query()->count()], 'Seeding writes no website, page or revision.');
        $this->assertSame($website->template_key, $website->fresh()->template_key);

        // A customer who starts setup now is pinned to the version just published.
        $this->tenant(['name' => 'Fresh Booths'], ['city' => 'Boise', 'region' => 'ID']);
        $live = $definition->fresh()->publishedVersion();
        $this->assertNotSame($current->id, $live->id);
        $this->assertSame($live->id, $this->wizardSession()->questionnaire_version_id);
        $this->assertTrue($this->areaStep($this->wizardSession()->version->steps())['area_details']);
    }

    public function test_the_v2_step_never_publishes_an_unrelated_draft_or_leaves_an_intermediate_version_live(): void
    {
        // A database that has only v1, with a draft nobody recognises.
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $v1 = $definition->publishedVersion();
        $unrelated = array_slice($v1->storedSteps(), 0, 3);
        app(QuestionnaireVersionPublisher::class)->createDraft($definition, $unrelated);

        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);

        $definition = $definition->fresh();
        $versions = $definition->versions()->orderBy('version_number')->get();
        $published = $definition->publishedVersion();
        $this->assertTrue($this->areaStep($published->steps())['area_details'], 'The live version is v3.');
        $this->assertNull($definition->draftVersion());
        $this->assertSame(QuestionnaireVersionState::Superseded, $v1->fresh()->state);
        $this->assertSame($v1->storedSteps(), $v1->fresh()->storedSteps(), 'v1 is untouched.');
        $this->assertCount(3, $versions, 'v1, v2 and v3 — and nothing else.');
        $this->assertNotContains(json_encode($unrelated), $versions->map(fn ($v) => json_encode($v->storedSteps()))->all(), 'The unrelated draft was never published.');
        $this->assertSame(1, $versions->where('state', QuestionnaireVersionState::Published)->count());

        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);
        $this->assertSame(3, $definition->versions()->count(), 'Idempotent.');
    }

    public function test_a_clean_environment_gets_the_capability_in_its_first_v3(): void
    {
        $this->seedGuidedWizard();
        $published = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail()->publishedVersion();

        $this->assertTrue($this->areaStep($published->steps())['area_details']);
    }
}
