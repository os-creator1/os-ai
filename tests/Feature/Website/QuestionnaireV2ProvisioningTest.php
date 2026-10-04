<?php

namespace Tests\Feature\Website;

use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use App\Models\QuestionnaireVersion;
use App\Models\Website;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * Deploying v2 must not depend on somebody remembering a manual command, and
 * must be safe on an installation with live v1 data: idempotent, no duplicate
 * versions, nothing pinned to v1 changes, new setups deterministically get v2.
 */
class QuestionnaireV2ProvisioningTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_02_090002_publish_photobooth_website_setup_questionnaire_v2.php');
    }

    private function definition(): ?QuestionnaireDefinition
    {
        return QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->first();
    }

    private function versionNumbers(): array
    {
        return $this->definition()->versions()->orderBy('version_number')->pluck('version_number')->map(fn ($n) => (int) $n)->all();
    }

    public function test_the_migration_is_a_no_op_on_a_database_that_never_provisioned_the_questionnaire(): void
    {
        $this->assertNull($this->definition(), 'A fresh migrated database has no definition: the migration created nothing.');

        $this->migration()->up();

        $this->assertNull($this->definition(), 'The migration never creates the definition from nothing.');
        $this->assertSame(0, QuestionnaireVersion::count());
    }

    public function test_the_migration_publishes_v2_on_an_installation_that_already_has_v1_and_is_idempotent(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class); // the installation as deployed before this change
        $this->assertSame([1], $this->versionNumbers());

        $this->migration()->up();
        $this->migration()->up(); // re-run: nothing more happens
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class); // and the manual seeder is still a no-op

        $this->assertSame([1, 2], $this->versionNumbers(), 'Exactly one new version, never a duplicate.');
        $this->assertSame(1, QuestionnaireDefinition::count());
        $this->assertSame(2, (int) $this->definition()->publishedVersion()->version_number);
        $this->assertNull($this->definition()->draftVersion());
        $this->assertSame(['superseded', 'published'], $this->definition()->versions()->orderBy('version_number')->get()->pluck('state')->map(fn ($s) => $s->value)->all());
    }

    public function test_live_v1_sessions_responses_and_generated_websites_are_untouched_by_the_upgrade(): void
    {
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);

        // An owner mid-setup on v1, and one with a finished, generated v1 website.
        [$customerA, $midBusiness, $midWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customerA);
        $this->post($this->wizardUrl($midWorkspace, $midBusiness, 'setup.template'), ['template_key' => 'photo_booth_modern']);
        $this->postScreen($midWorkspace, $midBusiness, 'business_name', ['value' => 'Mid Setup Co']);

        [$customerB, $doneBusiness] = $this->entitledTenant();
        $doneWebsite = $this->createWebsite($doneBusiness);
        $this->homePage($doneWebsite);
        $v1 = $this->definition()->publishedVersion();
        $completed = QuestionnaireResponse::create([
            'business_id' => $doneBusiness->id,
            'website_id' => $doneWebsite->id,
            'questionnaire_definition_id' => $v1->questionnaire_definition_id,
            'questionnaire_version_id' => $v1->id,
            'status' => 'completed',
            'answers' => ['business_name' => 'Done Co', 'service_area_cities' => 'Naperville, Aurora'],
            'answers_revision' => 5,
            'current_step_key' => 'custom_section',
            'completed_at' => now(),
        ]);

        $before = [
            'mid' => $this->snapshotResponse(QuestionnaireResponse::where('business_id', $midBusiness->id)->sole()),
            'done' => $this->snapshotResponse($completed->fresh()),
            'pages' => $doneWebsite->pages()->orderBy('id')->get(['id', 'slug', 'title', 'sections', 'updated_at'])->toArray(),
            'v1_definition' => json_encode($v1->fresh()->definition),
        ];

        $this->migration()->up();

        $this->assertSame($before['mid'], $this->snapshotResponse(QuestionnaireResponse::where('business_id', $midBusiness->id)->sole()), 'The in-progress session is unchanged and still pinned to v1.');
        $this->assertSame($before['done'], $this->snapshotResponse($completed->fresh()), 'The completed response is unchanged and still pinned to v1.');
        $this->assertSame($before['pages'], $doneWebsite->pages()->orderBy('id')->get(['id', 'slug', 'title', 'sections', 'updated_at'])->toArray(), 'The generated website is untouched.');
        $this->assertSame($before['v1_definition'], json_encode($v1->fresh()->definition), 'The v1 definition itself is never edited.');
        $this->assertSame(1, (int) QuestionnaireResponse::where('business_id', $midBusiness->id)->sole()->version->version_number);

        // The mid-setup owner keeps working on v1 (one question per screen, legacy field names).
        $this->get($this->wizardUrl($midWorkspace, $midBusiness, 'setup.step', ['phone']))
            ->assertOk()
            ->assertSee('name="value"', false);
        $this->postScreen($midWorkspace, $midBusiness, 'phone', ['value' => '6305550100'])->assertSessionDoesntHaveErrors();
        $this->assertSame(1, (int) QuestionnaireResponse::where('business_id', $midBusiness->id)->sole()->version->version_number);
    }

    public function test_new_setups_deterministically_start_on_v2_after_the_upgrade(): void
    {
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        $this->migration()->up();

        foreach (range(1, 3) as $n) {
            [$customer, $business, $workspace] = $this->entitledTenant();
            $this->authenticateAsCustomer($customer);
            $this->startV2Setup($workspace, $business);

            $this->assertSame(2, (int) $this->activeResponse($business)->version->version_number, "Setup #{$n} starts on v2.");
        }
    }

    public function test_a_fresh_install_through_the_database_seeder_path_ends_with_v1_superseded_by_v2(): void
    {
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
        $this->assertStringContainsString('PhotoboothWebsiteSetupQuestionnaireV2Seeder::class', $source, 'A normal db:seed must provision the questionnaire.');

        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);

        $this->assertSame([1, 2], $this->versionNumbers());
        $this->assertSame(2, (int) $this->definition()->publishedVersion()->version_number);
    }

    public function test_the_migration_is_registered_after_the_schema_it_depends_on(): void
    {
        $this->assertTrue(Schema::hasTable('questionnaire_definitions'));
        $this->assertTrue(Schema::hasColumn('business_backdrops', 'category'));
        $names = array_map('basename', glob(database_path('migrations/2026_11_02_*.php')));
        sort($names);
        $this->assertSame('2026_11_02_090001_add_category_to_business_backdrops_table.php', $names[0]);
        $this->assertSame('2026_11_02_090002_publish_photobooth_website_setup_questionnaire_v2.php', $names[1]);
    }

    /** @return array<string, mixed> */
    private function snapshotResponse(QuestionnaireResponse $r): array
    {
        return [
            'version' => $r->questionnaire_version_id,
            'status' => $r->status->value,
            'answers' => json_encode($r->answers),
            'revision' => $r->answers_revision,
            'step' => $r->current_step_key,
            'edit_mode' => (bool) $r->edit_mode,
        ];
    }
}
