<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Website\Setup\QuestionnaireStepResolver;
use App\Library\Website\Setup\WebsiteSetupSessionManager;
use App\Models\QuestionnaireDefinition;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Builder redesign — proves the seeded Photobooth questionnaire
 * is real and usable: exactly one published version, a genuinely
 * conditional Backdrops step, and a full setup session can be started
 * against it end to end. Re-running the seeder must be a no-op, matching
 * every other operator-owned seeder in this codebase.
 */
class PhotoboothWebsiteSetupQuestionnaireSeederTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_seeding_publishes_exactly_one_version_with_every_required_step(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);

        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $published = $definition->publishedVersion();

        $this->assertNotNull($published);
        $this->assertSame(1, $definition->versions()->count());

        $keys = array_column($published->steps(), 'key');
        foreach (['business_name', 'phone', 'email', 'service_area_cities', 'booth_types', 'packages', 'offers_backdrops', 'backdrops', 'gallery', 'contact_form_fields', 'custom_section'] as $expectedKey) {
            $this->assertContains($expectedKey, $keys, "Expected the seeded questionnaire to include the '{$expectedKey}' step.");
        }
    }

    public function test_re_seeding_is_a_no_op(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);

        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $this->assertSame(1, $definition->versions()->count());
        $this->assertSame(1, QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->count());
    }

    public function test_the_backdrops_step_is_genuinely_conditional_in_the_seeded_definition(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);

        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $steps = $definition->publishedVersion()->steps();
        $resolver = app(QuestionnaireStepResolver::class);

        $this->assertNotContains('backdrops', array_column($resolver->visibleSteps($steps, ['offers_backdrops' => false]), 'key'));
        $this->assertContains('backdrops', array_column($resolver->visibleSteps($steps, ['offers_backdrops' => true]), 'key'));
    }

    public function test_a_full_setup_session_can_be_started_against_the_seeded_questionnaire(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        [, $business] = $this->entitledTenant();

        $response = app(WebsiteSetupSessionManager::class)->start($business, PhotoboothWebsiteSetupQuestionnaireSeeder::KEY);

        $this->assertSame('business_name', $response->current_step_key);
    }
}
