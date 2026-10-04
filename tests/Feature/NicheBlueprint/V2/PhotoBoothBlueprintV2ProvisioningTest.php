<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\AutomationWorkflow;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\CatalogItem;
use App\Models\CustomFieldDefinition;
use App\Models\Form;
use App\Models\NicheBlueprintVersion;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\NicheBlueprint\V2\Support\SeedsPhotoBoothBlueprintV2;
use Tests\TestCase;

class PhotoBoothBlueprintV2ProvisioningTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhotoBoothBlueprintV2;

    public function test_the_seed_publishes_a_new_version_as_a_copy_of_the_previous_one_with_stable_component_keys(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprintV2();

        $versions = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->orderBy('version_number')->get();

        $this->assertSame(['superseded', 'superseded', 'published'], $versions->map(fn ($v) => $v->state->value)->all());

        $v1Keys = $versions[0]->components->pluck('component_key')->all();
        $v2Keys = $versions[2]->components->pluck('component_key')->all();

        $this->assertSame(['photo_booth_default_pipeline'], $v1Keys);
        $this->assertContains('photo_booth_default_pipeline', $v2Keys, 'The v1 component keeps its identity in v2.');
        $this->assertGreaterThan(10, count($v2Keys));
    }

    public function test_the_seed_is_idempotent(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprintV2();

        $this->artisan('blueprint:seed-photo-booth-v2')->assertExitCode(0);

        $this->assertSame(3, NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->count());
    }

    public function test_a_new_photo_booth_business_receives_the_v2_configuration(): void
    {
        $this->seedPhotoBoothBlueprintV2();

        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $records = $this->installationRecords($business);
        $states = $records->map(fn ($r) => $r->state->value)->countBy()->all();

        $this->assertSame(3, (int) BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->min('installed_from_version'));
        $this->assertGreaterThan(8, $states['installed'] ?? 0, json_encode($states));

        $this->assertSame(8, Tag::query()->where('business_id', $business->id)->count());
        $this->assertSame(4, CustomFieldDefinition::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, Form::query()->where('business_id', $business->id)->count());
        $this->assertSame('draft', Form::query()->where('business_id', $business->id)->first()->lifecycle_state->value);
        $this->assertSame(4, CatalogItem::query()->where('business_id', $business->id)->count());

        $workflows = AutomationWorkflow::query()->where('business_id', $business->id)->get();
        $this->assertCount(3, $workflows);
        $this->assertSame(['draft'], $workflows->pluck('status')->map(fn ($s) => $s->value)->unique()->values()->all(), 'Automations are installed as inert drafts.');
    }

    public function test_repeated_provisioning_does_not_duplicate_anything(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $before = [
            Tag::query()->where('business_id', $business->id)->count(),
            Form::query()->where('business_id', $business->id)->count(),
            AutomationWorkflow::query()->where('business_id', $business->id)->count(),
            CatalogItem::query()->where('business_id', $business->id)->count(),
            BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->count(),
        ];

        foreach ([1, 2] as $_) {
            $result = app(NicheBlueprintInstaller::class)->installForBusiness($business);
            $this->assertSame(0, $result->installed);
        }

        $after = [
            Tag::query()->where('business_id', $business->id)->count(),
            Form::query()->where('business_id', $business->id)->count(),
            AutomationWorkflow::query()->where('business_id', $business->id)->count(),
            CatalogItem::query()->where('business_id', $business->id)->count(),
            BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_provenance_hashes_are_recorded_for_installed_components(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $record = $this->installationRecords($business)->get('photo_booth_tags');

        $this->assertSame(BlueprintComponentInstallationState::Installed, $record->state);
        $this->assertSame(64, strlen((string) $record->source_checksum));
        $this->assertSame(64, strlen((string) $record->installed_fingerprint));
    }
}
