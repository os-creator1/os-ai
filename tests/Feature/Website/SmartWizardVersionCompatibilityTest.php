<?php

namespace Tests\Feature\Website;

use App\Models\Business;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * Publishing the improved setup as a NEW questionnaire version must change
 * nothing for owners already on the old one: in-progress sessions,
 * completed responses and "Edit setup answers" stay pinned to v1 and keep
 * working; only NEW setups start on v2. No answers are migrated.
 */
class SmartWizardVersionCompatibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class); // v1 only
    }

    private function definition(): QuestionnaireDefinition
    {
        return QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
    }

    public function test_publishing_v2_supersedes_v1_and_is_idempotent(): void
    {
        $this->assertSame(1, (int) $this->definition()->publishedVersion()->version_number);

        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class); // re-run: a no-op

        $versions = $this->definition()->versions()->orderBy('version_number')->get();
        $this->assertSame([1, 2], $versions->pluck('version_number')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(['superseded', 'published'], $versions->pluck('state')->map(fn ($s) => $s->value)->all());
        $this->assertSame(2, (int) $this->definition()->publishedVersion()->version_number);
        $this->assertNull($this->definition()->draftVersion(), 'No stray draft is left behind.');
    }

    public function test_v2_is_a_valid_definition_with_nine_screens_and_a_niche_category_vocabulary(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);
        $steps = $this->definition()->publishedVersion()->steps();

        $screens = collect($steps)->map(fn ($s) => $s['screen'] ?? $s['key'])->unique()->values();
        $this->assertCount(9, $screens);

        $backdrops = collect($steps)->firstWhere('key', 'backdrops');
        $this->assertSame(['backdrop', 'booth_setup', 'event', 'package_example'], array_keys($backdrops['categories']));
        $this->assertSame($backdrops['categories'], collect($steps)->firstWhere('key', 'gallery')['categories'], 'Backdrops and gallery photos share the niche vocabulary.');
    }

    public function test_an_in_progress_v1_session_keeps_running_on_v1_after_v2_is_published(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        // The owner starts setup while v1 is the published version.
        $this->post($this->wizardUrl($workspace, $business, 'setup.template'), ['template_key' => 'photo_booth_modern']);
        $response = $this->activeResponse($business);
        $this->assertSame(1, (int) $response->version->version_number);

        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);

        // Still v1, still one question per screen with the original field names.
        $this->assertSame(1, (int) $this->activeResponse($business)->version->version_number);
        $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['business_name']))
            ->assertOk()
            ->assertSee('name="value"', false)
            ->assertDontSee('name="s[business_name][value]"', false)
            ->assertSee('Step 1 of 17'); // 16 visible questions (backdrops hidden) + review

        // A legacy comma-separated service-area answer still saves exactly as before.
        foreach (['business_name' => 'Legacy Booth Co', 'phone' => '6305550100', 'email' => 'old@legacy.test'] as $key => $value) {
            $this->postScreen($workspace, $business, $key, ['value' => $value]);
        }
        $this->postScreen($workspace, $business, 'service_area_cities', ['value' => 'Naperville, Aurora']);
        $this->assertSame('Naperville, Aurora', $this->activeResponse($business)->answer('service_area_cities'));
        $this->assertSame('primary_cta', $this->activeResponse($business)->current_step_key);
    }

    public function test_a_v1_session_renders_its_legacy_fixed_fields_with_the_new_add_remove_rows(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post($this->wizardUrl($workspace, $business, 'setup.template'), ['template_key' => 'photo_booth_modern']);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);

        foreach (['business_name' => 'x', 'phone' => '1', 'email' => 'a@b.test', 'service_area_cities' => 'Chicago'] as $key => $value) {
            $this->postScreen($workspace, $business, $key, ['value' => $value]);
        }
        $this->postScreen($workspace, $business, 'primary_cta', ['value' => 'call']);
        $this->postScreen($workspace, $business, 'brand_personality', ['value' => 'warm']);
        $this->postScreen($workspace, $business, 'booth_types', ['items' => [['name' => 'Booth']]]);
        $this->postScreen($workspace, $business, 'services_event_types', ['items' => []]);

        $html = $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['packages']))->assertOk()->getContent();

        // v1 packages keep their inline fields (one-per-line features); the rows are add/remove-able now.
        $this->assertStringContainsString('name="items[0][features_text]"', $html);
        $this->assertStringContainsString('name="items[0][price]"', $html);
        $this->assertStringContainsString('data-add-row', $html);
        $this->assertStringNotContainsString('name="items[1][name]"', $html, 'No fixed block of blank cards.');
    }

    public function test_a_business_that_starts_after_v2_is_published_gets_v2_and_another_stays_on_v1(): void
    {
        [$customer, $legacyBusiness, $legacyWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post($this->wizardUrl($legacyWorkspace, $legacyBusiness, 'setup.template'), ['template_key' => 'photo_booth_modern']);

        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);

        [$newCustomer, $newBusiness, $newWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($newCustomer);
        $this->post($this->wizardUrl($newWorkspace, $newBusiness, 'setup.template'), ['template_key' => 'photo_booth_modern']);

        $this->assertSame(2, (int) $this->activeResponse($newBusiness)->version->version_number);
        $this->assertSame(1, (int) QuestionnaireResponse::where('business_id', $legacyBusiness->id)->sole()->version->version_number);
        $this->assertInstanceOf(Business::class, $newBusiness);
    }
}
