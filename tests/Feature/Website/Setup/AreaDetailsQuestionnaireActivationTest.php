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

        // A pre-existing draft that is not the seeder's tree is never overwritten or published: activation refuses, loudly,
        // and everything stays exactly as it was.
        try {
            $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);
            $this->fail('Seeding must refuse a conflicting draft.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('version id ' . $stale->id, $e->getMessage());
            $this->assertStringContainsString('Refusing', $e->getMessage());
        }
        $this->assertSame($current->id, $definition->fresh()->publishedVersion()->id, 'Nothing was published.');
        $this->assertSame($stale->id, $definition->fresh()->draftVersion()->id);
        $this->assertSame($olderSteps, $definition->fresh()->draftVersion()->storedSteps());

        // The operator deliberately discards the draft, then activation proceeds.
        $stale->delete();
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);

        $definition = $definition->fresh();
        $published = $definition->publishedVersion();
        $this->assertTrue($published->steps()[array_search('service_area_cities', array_column($published->steps(), 'key'), true)]['area_details'], 'The new version carries area_details.');
        $this->assertGreaterThan($current->version_number, $published->version_number);
        $this->assertNull($definition->draftVersion());
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

    public function test_an_unrelated_draft_makes_the_whole_activation_refuse_and_roll_back(): void
    {
        // A database that has only v1, with a draft nobody can vouch for.
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $v1 = $definition->publishedVersion();
        $unrelated = array_slice($v1->storedSteps(), 0, 3);
        $draft = app(QuestionnaireVersionPublisher::class)->createDraft($definition, $unrelated);
        $snapshot = fn () => $definition->versions()->orderBy('id')->get(['id', 'version_number', 'state', 'definition', 'published_at'])->toArray();
        $before = $snapshot();

        foreach ([PhotoboothWebsiteSetupQuestionnaireV3Seeder::class, \Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder::class] as $seeder) {
            try {
                $this->seed($seeder);
                $this->fail("{$seeder} must refuse a conflicting draft.");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('version id ' . $draft->id, $e->getMessage());
            }

            $this->assertEquals($before, $snapshot(), 'No version was created, published, superseded or edited — including the intermediate v2.');
            $this->assertSame($draft->id, $definition->fresh()->draftVersion()->id);
            $this->assertSame($unrelated, $definition->fresh()->draftVersion()->storedSteps());
            $this->assertSame($v1->id, $definition->fresh()->publishedVersion()->id);
        }
    }

    public function test_a_draft_that_is_exactly_the_seeders_tree_is_simply_published_and_the_run_is_idempotent(): void
    {
        $this->seedGuidedWizard();
        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $current = $definition->publishedVersion();
        $full = $current->storedSteps();
        $current->forceFill(['definition' => ['steps' => $this->withoutAreaDetails($full)]])->save();
        $draft = app(QuestionnaireVersionPublisher::class)->createDraft($definition, $full);

        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);

        $this->assertSame($draft->id, $definition->fresh()->publishedVersion()->id, 'An identical draft is safe to publish.');
        $count = $definition->versions()->count();
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);
        $this->assertSame($count, $definition->versions()->count());
    }

    public function test_a_clean_environment_gets_the_capability_in_its_first_v3(): void
    {
        $this->seedGuidedWizard();
        $published = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail()->publishedVersion();

        $this->assertTrue($this->areaStep($published->steps())['area_details']);
    }
}
