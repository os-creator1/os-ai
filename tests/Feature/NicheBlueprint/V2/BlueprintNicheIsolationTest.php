<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\NicheBlueprint\Workspace\BlueprintUpdateDetector;
use App\Library\NicheBlueprint\Workspace\BlueprintWorkspaceService;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\NicheBlueprint\V2\Support\SeedsPhotoBoothBlueprintV2;
use Tests\TestCase;

class BlueprintNicheIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhotoBoothBlueprintV2;

    /** A second, differently-configured niche for wedding vendors. */
    private function publishWeddingVendorBlueprint(): void
    {
        $admin = $this->platformAdminId();
        $workspace = app(BlueprintWorkspaceService::class);
        $publisher = app(NicheBlueprintPublisher::class);

        $blueprint = $publisher->createBlueprint($admin, 'wedding_vendor_niche', 'Wedding Vendor', null, 'wedding_vendor');
        $draft = $workspace->draftFor($blueprint, $admin);
        $workspace->saveComponent($admin, $draft, 'crm_tag_set', null, ['tags' => "Engaged\nVendor Referral"]);
        $workspace->saveComponent($admin, $draft, 'crm_custom_field', null, ['label' => 'Ceremony venue', 'type' => 'text']);
        $publisher->publishVersion($admin, $draft);
    }

    /** A Business in its own Workspace, moved to $industry BEFORE its first plan so the niche resolves from it. */
    private function businessInIndustry(string $industry, string $name): Business
    {
        [, $business, $workspace] = $this->businessWithoutPlan($name);
        DB::table('businesses')->where('id', $business->id)->update(['industry' => $industry]);
        $this->assignTier($workspace, WorkspacePlanTier::Growth);

        return $business->fresh();
    }

    public function test_two_niches_provision_different_configuration(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        $this->publishWeddingVendorBlueprint();

        $booth = $this->businessInIndustry('photo_booth_service', 'Booth Co');
        $vendor = $this->businessInIndustry('wedding_vendor', 'Vendor Co');

        $boothTags = Tag::query()->where('business_id', $booth->id)->pluck('name')->all();
        $vendorTags = Tag::query()->where('business_id', $vendor->id)->pluck('name')->all();

        $this->assertContains('Hot Lead', $boothTags);
        $this->assertNotContains('Engaged', $boothTags);
        $this->assertSame(['Engaged', 'Vendor Referral'], collect($vendorTags)->sort()->values()->all());

        $this->assertSame(3, AutomationWorkflow::query()->where('business_id', $booth->id)->count());
        $this->assertSame(0, AutomationWorkflow::query()->where('business_id', $vendor->id)->count());

        $this->assertNotEquals(
            BusinessBlueprintComponentInstallation::query()->where('business_id', $booth->id)->pluck('blueprint_id')->unique()->all(),
            BusinessBlueprintComponentInstallation::query()->where('business_id', $vendor->id)->pluck('blueprint_id')->unique()->all(),
        );
    }

    public function test_tenant_data_never_crosses_businesses(): void
    {
        $this->seedPhotoBoothBlueprintV2();

        [, $a] = $this->tenant(WorkspacePlanTier::Growth, 'Booth A', 'WS A');
        [, $b] = $this->tenant(WorkspacePlanTier::Growth, 'Booth B', 'WS B');

        foreach ([$a, $b] as $business) {
            $this->assertSame(8, Tag::query()->where('business_id', $business->id)->whereIn('name', ['Wedding', 'Birthday', 'Corporate', 'School Event', 'Hot Lead', 'Deposit Paid', 'Past Client', 'Review Requested'])->count());
        }

        $aRecordIds = BusinessBlueprintComponentInstallation::query()->where('business_id', $a->id)->pluck('installed_record_id', 'component_key');
        $bRecordIds = BusinessBlueprintComponentInstallation::query()->where('business_id', $b->id)->pluck('installed_record_id', 'component_key');

        // The copies are distinct rows: a Business's installed form/workflow/catalog rows are never the other's.
        foreach (['photo_booth_form_availability', 'photo_booth_auto_new_inquiry', 'photo_booth_package_classic'] as $key) {
            $this->assertNotSame($aRecordIds[$key], $bRecordIds[$key], $key);
        }

        $this->assertSame(0, Tag::query()->where('business_id', $a->id)->whereIn('id', Tag::query()->where('business_id', $b->id)->pluck('id'))->count());
    }

    public function test_one_business_update_state_and_config_reads_are_not_affected_by_another(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $a] = $this->tenant(WorkspacePlanTier::Growth, 'Booth A', 'WS A');
        [, $b] = $this->tenant(WorkspacePlanTier::Growth, 'Booth B', 'WS B');

        $reader = app(BlueprintConfigReader::class);
        $detector = app(BlueprintUpdateDetector::class);

        $this->assertSame($reader->websiteConfig($a), $reader->websiteConfig($b));
        $this->assertSame(0, $detector->forBusiness($a)['updates_available']);

        // Owner A removes their copy of the Hot Lead tag; B is untouched.
        Tag::query()->where('business_id', $a->id)->where('name', 'Wedding')->update(['archived_at' => now()]);

        $reportA = collect($detector->forBusiness($a)['components'])->firstWhere('component_key', 'photo_booth_tags');
        $reportB = collect($detector->forBusiness($b)['components'])->firstWhere('component_key', 'photo_booth_tags');

        $this->assertTrue($reportA['owner_modified']);
        $this->assertFalse($reportB['owner_modified']);
    }

    public function test_a_business_outside_any_niche_gets_nothing_and_a_missing_niche_does_not_break_reads(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        $other = $this->businessInIndustry('home_services', 'Plumbing Co');

        $this->assertSame(0, BusinessBlueprintComponentInstallation::query()->where('business_id', $other->id)->count());
        $this->assertNull(app(BlueprintConfigReader::class)->websiteConfig($other));
        $this->assertSame([], app(BlueprintUpdateDetector::class)->forBusiness($other)['components']);
    }
}
