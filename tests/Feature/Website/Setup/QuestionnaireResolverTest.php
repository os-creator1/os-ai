<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Website\Setup\QuestionnaireResolver;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireVersion;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round — proves QuestionnaireResolver
 * resolves strictly by the Business's own niche: a Business of any OTHER
 * industry must never receive the Photobooth questionnaire, and a niche
 * with no published definition at all resolves to null rather than
 * falling back to any other definition.
 */
class QuestionnaireResolverTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_a_photo_booth_business_resolves_the_photobooth_definition(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        [, $business] = $this->entitledTenant(['industry' => 'photo_booth_service']);

        $definition = app(QuestionnaireResolver::class)->resolveForBusiness($business);

        $this->assertNotNull($definition);
        $this->assertSame(PhotoboothWebsiteSetupQuestionnaireSeeder::KEY, $definition->key);
    }

    public function test_a_business_of_a_different_industry_never_receives_the_photobooth_definition(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        [, $business] = $this->entitledTenant(['industry' => 'home_services']);

        $definition = app(QuestionnaireResolver::class)->resolveForBusiness($business);

        $this->assertNull($definition);
    }

    public function test_a_niche_with_only_a_draft_never_resolves(): void
    {
        [, $business] = $this->entitledTenant(['industry' => 'event_services']);

        $definition = QuestionnaireDefinition::create(['key' => 'event_services_setup', 'scope' => QuestionnaireResolver::SCOPE, 'niche_key' => 'event_services', 'name' => 'Event Services Setup']);
        QuestionnaireVersion::create([
            'questionnaire_definition_id' => $definition->id,
            'version_number' => 1,
            'state' => 'draft',
            'definition' => ['steps' => [
                ['key' => 'biz_name', 'prompt' => 'Business name?', 'help_text' => null, 'input_type' => 'text', 'required' => true, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'business', 'target_field' => 'name', 'ai_instructions' => null],
            ]],
        ]);

        $resolved = app(QuestionnaireResolver::class)->resolveForBusiness($business);

        $this->assertNull($resolved, 'A draft-only definition must never resolve — only a published version counts.');
    }

    public function test_a_niche_with_no_definition_at_all_resolves_to_null(): void
    {
        [, $business] = $this->entitledTenant(['industry' => 'wedding_vendor']);

        $this->assertNull(app(QuestionnaireResolver::class)->resolveForBusiness($business));
    }
}
