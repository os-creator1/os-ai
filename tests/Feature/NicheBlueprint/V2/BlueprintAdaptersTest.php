<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\CustomFieldDefinition;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Feature\NicheBlueprint\V2\Support\SeedsPhotoBoothBlueprintV2;
use Tests\TestCase;

class BlueprintAdaptersTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhotoBoothBlueprintV2;

    public function test_every_v2_component_type_is_registered_with_a_surface_and_a_policy(): void
    {
        $registry = app(BlueprintComponentAdapterRegistry::class);
        $expected = [
            'crm_pipeline' => ['crm', 'copy'], 'crm_tag_set' => ['crm', 'copy'], 'crm_custom_field' => ['crm', 'copy'],
            'automation_workflow' => ['automations', 'copy'], 'form' => ['forms', 'copy'], 'booking_type' => ['calendar', 'copy'],
            'package_template' => ['packages', 'copy'], 'website_config' => ['website', 'copy'], 'seo_strategy' => ['seo', 'live'],
            'citation_recommendations' => ['citations', 'live'], 'document_template' => ['documents', 'live'],
        ];

        foreach ($expected as $type => [$surface, $policy]) {
            $adapter = $registry->adapterFor($type);
            $this->assertSame($surface, $adapter->surface(), $type);
            $this->assertSame($policy, $adapter->updatePolicy()->value, $type);
        }
    }

    public function test_a_booking_type_needs_a_location_and_installs_inactive_once_one_exists(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $record = $this->installationRecords($business)->get('photo_booth_booking_consult');
        $this->assertSame(BlueprintComponentInstallationState::Failed, $record->state, 'No Location yet: failed (retryable), never an invented Location.');
        $this->assertSame(0, BookingType::query()->count());

        $location = BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main studio', 'service_mode' => 'storefront', 'country_code' => 'US']);

        app(NicheBlueprintInstaller::class)->installForBusiness($business);
        app(NicheBlueprintInstaller::class)->installForBusiness($business);

        $record = $this->installationRecords($business)->get('photo_booth_booking_consult');
        $this->assertSame(BlueprintComponentInstallationState::Installed, $record->state);

        $type = BookingType::query()->where('business_location_id', $location->id)->get();
        $this->assertCount(1, $type, 'Retried twice, created once.');
        $this->assertFalse((bool) $type->first()->is_active);
        $this->assertSame(20, (int) $type->first()->duration_minutes);
        $this->assertSame(240, (int) $type->first()->minimum_notice_minutes);
        $this->assertSame(5, (int) $type->first()->buffer_before_minutes);
        $this->assertSame(30, (int) $type->first()->slot_interval_minutes);
    }

    public function test_the_form_installs_as_a_draft_with_custom_field_mappings_and_style(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $form = Form::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('draft', $form->lifecycle_state->value);

        $version = FormVersion::query()->where('form_id', $form->id)->firstOrFail();
        $fields = collect($version->fields)->keyBy('key');
        $eventDate = CustomFieldDefinition::query()->where('business_id', $business->id)->where('label', 'Event date')->firstOrFail();

        $this->assertSame((string) $eventDate->uid, $fields['event_date']['custom_field_uid']);
        $this->assertSame('#7c3aed', $version->design['accent']);
        $this->assertTrue((bool) $version->create_opportunity);
        $this->assertTrue((bool) $fields['full_name']['contact_name']);
    }

    public function test_automations_install_as_inert_drafts_with_names_resolved_to_business_tags(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $workflow = AutomationWorkflow::query()->where('business_id', $business->id)->where('name', 'New inquiry follow-up')->firstOrFail();
        $this->assertSame('draft', $workflow->status->value);
        $this->assertNull($workflow->published_version_id);

        $version = AutomationWorkflowVersion::query()->where('workflow_id', $workflow->id)->firstOrFail();
        $this->assertSame('draft', $version->state->value);

        $root = $version->definition['root'];
        $this->assertSame('form_submitted', $root['config']['trigger_type']);
        $this->assertSame(['add_tag', 'internal_notification', 'wait', 'send_sms', 'wait', 'send_email'], array_column($root['next'], 'type'));

        $hotLead = Tag::query()->where('business_id', $business->id)->where('name', 'Hot Lead')->firstOrFail();
        $this->assertSame((int) $hotLead->id, (int) $root['next'][0]['config']['tag_id']);

        $this->assertSame(0, DB::table('automation_workflows')->where('business_id', $business->id)->where('status', 'published')->count());
    }

    public function test_package_templates_carry_no_price_and_website_and_seo_touch_no_website_rows(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $items = CatalogItem::query()->where('business_id', $business->id)->get();
        $this->assertSame(['package', 'package', 'product', 'product'], $items->pluck('type')->map(fn ($t) => $t->value)->sort()->values()->all());
        $this->assertSame([null], $items->pluck('price_minor')->unique()->values()->all(), 'No real pricing is copied.');

        $this->assertSame(0, DB::table('websites')->where('business_id', $business->id)->count(), 'The Website renderer/tables are untouched; Website reads the config seam.');
    }

    public function test_custom_fields_adopt_an_existing_field_instead_of_duplicating_it(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business, ] = $this->tenant(WorkspacePlanTier::Growth);

        $this->assertSame(1, CustomFieldDefinition::query()->where('business_id', $business->id)->where('label', 'Event date')->count());

        // Wipe the record so the installer runs the component again: the existing field is adopted.
        DB::table('business_blueprint_component_installations')->where('business_id', $business->id)->where('component_key', 'photo_booth_cf_event_date')->delete();
        app(NicheBlueprintInstaller::class)->installForBusiness($business);

        $this->assertSame(1, CustomFieldDefinition::query()->where('business_id', $business->id)->where('label', 'Event date')->count());
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function malformedPayloads(): array
    {
        return [
            'tag set without tags' => ['crm_tag_set', ['tags' => []]],
            'custom field with an unknown type' => ['crm_custom_field', ['label' => 'X', 'type' => 'banana']],
            'select field without options' => ['crm_custom_field', ['label' => 'X', 'type' => 'select']],
            'automation with an unsupported step' => ['automation_workflow', ['name' => 'A', 'trigger_type' => 'form_submitted', 'steps' => [['type' => 'teleport']]]],
            'automation with an unsendable trigger' => ['automation_workflow', ['name' => 'A', 'trigger_type' => 'message_received', 'steps' => [['type' => 'send_sms', 'body' => 'x']]]],
            'automation sms with no body' => ['automation_workflow', ['name' => 'A', 'trigger_type' => 'form_submitted', 'steps' => [['type' => 'send_sms', 'body' => '']]]],
            'form without fields' => ['form', ['name' => 'F', 'fields' => []]],
            'form with a bad type' => ['form', ['name' => 'F', 'fields' => [['key' => 'a', 'label' => 'A', 'type' => 'telepathy']]]],
            'form with a bad colour' => ['form', ['name' => 'F', 'design' => ['accent' => 'red'], 'fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text']]]],
            'booking type without duration' => ['booking_type', ['name' => 'B']],
            'booking type with a silly buffer' => ['booking_type', ['name' => 'B', 'duration_minutes' => 30, 'buffer_after_minutes' => 99999]],
            'package with a price but no currency' => ['package_template', ['kind' => 'package', 'name' => 'P', 'suggested_price_minor' => 100]],
            'package of an unknown kind' => ['package_template', ['kind' => 'gift', 'name' => 'P']],
            'website config with an unknown template' => ['website_config', ['template_key' => 'nope', 'pages' => [['page' => 'home', 'title' => 'Home']]]],
            'seo pattern with a bad intent' => ['seo_strategy', ['keyword_patterns' => [['pattern' => 'x', 'intent' => 'vibes']]]],
            'empty seo strategy' => ['seo_strategy', []],
            'citations with an unknown directory' => ['citation_recommendations', ['recommendations' => [['directory_key' => 'ghost_directory']]]],
            'document template with an unknown uid' => ['document_template', ['template_uid' => '00000000-0000-4000-8000-000000000000']],
        ];
    }

    /** @dataProvider malformedPayloads */
    public function test_malformed_descriptors_are_refused_where_they_are_defined(string $type, array $payload): void
    {
        $this->seedPhotoBoothBlueprintV2();

        $this->expectException(InvalidArgumentException::class);

        app(BlueprintComponentAdapterRegistry::class)->adapterFor($type)->validateDescriptor($payload);
    }
}
