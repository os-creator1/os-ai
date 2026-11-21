<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Business\BusinessUpdated;
use App\Library\NicheBlueprint\PhotoBoothBlueprintProvisioner;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\Workspace;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\NicheBlueprint\V2\Support\SeedsPhotoBoothBlueprintV2;
use Tests\TestCase;

/**
 * Seeding a Blueprint publishes the niche; it installs nothing for any Business. These tests
 * pin the repair for Photo Booth Businesses (Agency clients above all) that hold no
 * installation records: the automatic path for new/changed Businesses, and the dry-run-first
 * `blueprint:repair-photo-booth` command for existing ones.
 */
class PhotoBoothBlueprintProvisioningRepairTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhotoBoothBlueprintV2;

    private function repair(array $options = []): string
    {
        $code = Artisan::call('blueprint:repair-photo-booth', $options);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);

        return $output;
    }

    private function records(Business $business): int
    {
        return BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->count();
    }

    private function snapshot(Business $business): array
    {
        return BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->orderBy('id')->get()->toArray();
    }

    private function changeIndustry(Business $business, string $industry): void
    {
        Business::query()->whereKey($business->id)->update(['industry' => $industry]);
        BusinessUpdated::dispatch($business->id, ['industry']);
    }

    public function test_an_existing_photo_booth_business_with_a_missing_blueprint_is_reported_then_repaired(): void
    {
        // The Business and its plan predate the Blueprint, as for every pre-existing client.
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Kaleb Hill Photography', 'Kaleb WS');
        $this->seedPhotoBoothBlueprintV2();

        $this->assertSame(0, $this->records($business), 'seeding installs nothing for an existing Business');
        $this->assertNull(app(BlueprintConfigReader::class)->websiteConfig($business));

        $dry = $this->repair(['--business' => (string) $business->id]);
        $this->assertStringContainsString('MODE: DRY RUN', $dry);
        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::ELIGIBLE, $dry);
        $this->assertStringContainsString('Kaleb Hill Photography', $dry);
        $this->assertSame(0, $this->records($business), 'a dry run writes nothing');

        $applied = $this->repair(['--business' => (string) $business->id, '--apply' => true]);
        $this->assertStringContainsString('MODE: APPLY', $applied);
        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::INSTALLED, $applied);
        $this->assertGreaterThan(0, $this->records($business));
        $this->assertSame('photo_booth_editorial', app(BlueprintConfigReader::class)->websiteConfig($business)['template_key']);

        $before = $this->snapshot($business);
        $again = $this->repair(['--apply' => true]);
        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::ALREADY_PROVISIONED, $again);
        $this->assertSame($before, $this->snapshot($business), 're-running changes nothing');
    }

    public function test_a_new_photo_booth_business_receives_the_blueprint_automatically(): void
    {
        $this->seedPhotoBoothBlueprintV2();

        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Brand New Booth', 'New WS');

        $this->assertGreaterThan(0, $this->records($business));
        $this->assertSame('photo_booth_editorial', app(BlueprintConfigReader::class)->websiteConfig($business)['template_key']);
    }

    public function test_an_agency_style_client_created_with_a_placeholder_industry_receives_it_when_the_real_industry_arrives(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Client Booth', 'Client WS');
        \Illuminate\Support\Facades\DB::table('business_blueprint_component_installations')->where('business_id', $business->id)->delete();
        Business::query()->whereKey($business->id)->update(['industry' => 'other']);

        // Something other than the industry changing triggers nothing.
        BusinessUpdated::dispatch($business->id, ['name']);
        $this->assertSame(0, $this->records($business));

        $this->changeIndustry($business, 'photo_booth_service');

        $this->assertGreaterThan(0, $this->records($business));
    }

    public function test_existing_valid_assignments_and_customised_website_data_are_unchanged(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Customised Booth', 'Custom WS');
        $this->assertGreaterThan(0, $this->records($business), 'already validly provisioned');

        $website = Website::create(['business_id' => $business->id, 'name' => 'Customised', 'template_key' => 'photo_booth_luxury']);
        $websiteBefore = $website->fresh()->toArray();
        $recordsBefore = $this->snapshot($business);

        $this->repair(['--apply' => true]);
        $this->changeIndustry($business, 'photo_booth_service');
        $report = $this->repair(['--business' => $business->uid, '--apply' => true]);

        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::ALREADY_PROVISIONED, $report);
        $this->assertSame($recordsBefore, $this->snapshot($business));
        $this->assertSame($websiteBefore, $website->fresh()->toArray());
        $this->assertSame(1, Website::query()->where('business_id', $business->id)->count());
    }

    public function test_a_provisioned_business_with_a_skipped_or_failed_record_is_never_reinstalled_by_the_repair(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Core, 'Core Booth', 'Core WS');
        $before = $this->snapshot($business);
        $this->assertNotEmpty($before);

        $this->repair(['--apply' => true]);

        $this->assertSame($before, $this->snapshot($business));
    }

    public function test_unrelated_niches_stay_untouched(): void
    {
        [, $other] = $this->tenant(WorkspacePlanTier::Growth, 'Other Co', 'Other WS');
        [, $events] = $this->tenant(WorkspacePlanTier::Growth, 'Event Co', 'Event WS');
        [, $home] = $this->tenant(WorkspacePlanTier::Growth, 'Home Co', 'Home WS');
        Business::query()->whereKey($other->id)->update(['industry' => 'other']);
        Business::query()->whereKey($events->id)->update(['industry' => 'event_services']);
        Business::query()->whereKey($home->id)->update(['industry' => 'home_services']);
        $this->seedPhotoBoothBlueprintV2();

        foreach ([$other, $events, $home] as $business) {
            $this->assertSame(PhotoBoothBlueprintProvisioner::NOT_PHOTO_BOOTH, app(PhotoBoothBlueprintProvisioner::class)->repair($business->fresh())['status']);
            $this->repair(['--business' => (string) $business->id, '--apply' => true]);
            $this->changeIndustry($business, $business->fresh()->industry->value);
            $this->assertSame(0, $this->records($business), $business->name.' must receive no Photo Booth records');
        }

        $sweep = $this->repair(['--apply' => true]);
        foreach (['Other Co', 'Event Co', 'Home Co'] as $name) {
            $this->assertStringNotContainsString($name, $sweep, 'the sweep never even lists an unrelated niche');
        }
    }

    public function test_a_photo_booth_business_in_a_workspace_with_no_plan_is_blocked_and_nothing_is_written(): void
    {
        [, $business] = $this->businessWithoutPlan('Unplanned Booth');
        $this->seedPhotoBoothBlueprintV2();

        $out = $this->repair(['--apply' => true]);

        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::BLOCKED_PLAN_UNASSIGNED, $out);
        $this->assertSame(0, $this->records($business));
    }

    public function test_the_owner_sees_the_photo_booth_questionnaire_and_designs_after_the_repair(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Owner View Booth', 'Owner WS');
        $this->seedPhotoBoothBlueprintV2();
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);
        $this->repair(['--apply' => true]);

        $workspaceUid = Workspace::query()->findOrFail($business->workspace_id)->uid;
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $business->uid]))->assertRedirect();

        $website = Website::query()->where('business_id', $business->id)->sole();
        $this->assertSame('photo_booth_editorial', $website->template_key, 'the repaired Blueprint supplies the starting design');

        $response = QuestionnaireResponse::query()->where('business_id', $business->id)->sole();
        $definition = QuestionnaireDefinition::query()->findOrFail($response->questionnaire_definition_id);
        $this->assertSame('photo_booth_service', $definition->niche_key);

        $html = $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $business->uid, 'template']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('photo_booth_editorial', $html);
        $this->assertStringContainsString('photo_booth_luxury', $html);
    }
}
