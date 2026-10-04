<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Exceptions\NicheBlueprint\InvalidComponentDescriptorException;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\DocumentTemplateComponentAdapter;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\DocumentTemplate;
use App\Models\NicheBlueprintComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Feature\Documents\PlatformTemplates\PlatformTemplateTestHelpers;
use Tests\TestCase;

/**
 * Implementation Contract 17B §6b / Contract 20 §10-§11 - the
 * `document_template` Blueprint component adapter: publish-time descriptor
 * validation, and an install that is REFERENCE-ONLY (it never creates or copies
 * a template) and idempotent.
 */
class DocumentTemplateComponentAdapterTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use PlatformTemplateTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->owner();
    }

    private function adapter(): DocumentTemplateComponentAdapter
    {
        return app(DocumentTemplateComponentAdapter::class);
    }

    public function test_the_adapter_is_registered_beside_the_crm_adapter_for_the_document_template_type(): void
    {
        $registry = app(BlueprintComponentAdapterRegistry::class);

        $this->assertTrue($registry->has('document_template'));
        $this->assertInstanceOf(DocumentTemplateComponentAdapter::class, $registry->adapterFor('document_template'));
        $this->assertSame('document_template', $this->adapter()->componentType());
        $this->assertSame(['crm_pipeline', 'document_template', 'crm_tag_set', 'crm_custom_field', 'automation_workflow', 'form', 'booking_type', 'package_template', 'website_config', 'seo_strategy', 'citation_recommendations'], $registry->registeredComponentTypes());
        $this->assertSame('payments_contracts', DocumentTemplateComponentAdapter::FEATURE_KEY);
        $this->assertSame(\App\Enums\Entitlement\PlatformFeature::PaymentsContracts->value, DocumentTemplateComponentAdapter::FEATURE_KEY);
    }

    public function test_a_platform_template_uid_is_a_valid_descriptor_with_or_without_a_label(): void
    {
        $template = $this->livePlatformTemplate();

        $this->adapter()->validateDescriptor(['template_uid' => $template->uid]);
        $this->adapter()->validateDescriptor(['template_uid' => $template->uid, 'label' => 'Wedding']);
        $this->addToAssertionCount(1);
    }

    public function test_a_draft_or_disabled_platform_template_is_still_a_valid_reference(): void
    {
        // Status is deliberately not part of the descriptor: disabling takes effect through the recommendation filter.
        $draft = $this->templates()->createPlatform('Draft', 'proposal', null, $this->owner());
        $this->adapter()->validateDescriptor(['template_uid' => $draft->uid]);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function malformedDescriptors(): array
    {
        return [
            'empty payload' => [[]],
            'missing uid' => [['label' => 'x']],
            'null uid' => [['template_uid' => null]],
            'integer uid' => [['template_uid' => 5]],
            'not a uuid' => [['template_uid' => 'photo-booth-proposal']],
            'unknown uuid' => [['template_uid' => '11111111-1111-4111-8111-111111111111']],
            'array uid' => [['template_uid' => ['a']]],
        ];
    }

    /**
     * @dataProvider malformedDescriptors
     */
    public function test_a_missing_malformed_or_unknown_uid_is_rejected(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->adapter()->validateDescriptor($payload);
    }

    public function test_a_business_owned_template_uid_is_rejected(): void
    {
        $tenant = $this->photoBoothTenant();
        $own = $this->plantTemplate($tenant['business'], $this->platformBlocks(), ['name' => 'Tenant private']);

        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->validateDescriptor(['template_uid' => $own->uid]);
    }

    public function test_an_over_long_or_non_string_label_is_rejected(): void
    {
        $template = $this->livePlatformTemplate();

        foreach ([str_repeat('x', 192), ['no'], 12] as $label) {
            try {
                $this->adapter()->validateDescriptor(['template_uid' => $template->uid, 'label' => $label]);
                $this->fail('A bad label must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_publisher_refuses_to_publish_a_version_referencing_a_business_template_and_accepts_a_platform_one(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $tenant = $this->photoBoothTenant();
        $own = $this->plantTemplate($tenant['business'], $this->platformBlocks(), ['name' => 'Tenant private']);
        $platform = $this->livePlatformTemplate();
        $draft = $this->publisher()->createDraftVersion($this->owner()->id, $blueprint);

        $this->publisher()->addDraftComponent($this->owner()->id, $draft, 'bad_ref', 'document_template', 'payments_contracts', ['template_uid' => $own->uid]);
        try {
            $this->publisher()->publishVersion($this->owner()->id, $draft);
            $this->fail('A Business template reference must not publish.');
        } catch (InvalidComponentDescriptorException) {
            $this->assertSame('draft', $draft->fresh()->state->value);
        }

        $component = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->firstOrFail();
        $this->publisher()->removeDraftComponent($this->owner()->id, $component);
        $this->publisher()->addDraftComponent($this->owner()->id, $draft, 'good_ref', 'document_template', 'payments_contracts', ['template_uid' => $platform->uid]);
        $published = $this->publisher()->publishVersion($this->owner()->id, $draft);

        $this->assertSame('published', $published->state->value);
    }

    public function test_install_is_reference_only_creates_no_template_and_is_idempotent(): void
    {
        $platform = $this->livePlatformTemplate();
        $before = DocumentTemplate::query()->count();
        $hash = $this->templateHash($platform);
        $tenant = $this->photoBoothTenant();

        $first = $this->adapter()->install($tenant['business'], ['template_uid' => $platform->uid], null);
        $second = $this->adapter()->install($tenant['business'], ['template_uid' => $platform->uid], null);

        $this->assertSame('document_template', $first->recordType);
        $this->assertSame((int) $platform->id, $first->recordId, 'the reference points at the PLATFORM template itself');
        $this->assertEquals($first, $second);
        $this->assertSame($before, DocumentTemplate::query()->count(), 'nothing is created or copied');
        $this->assertSame(0, DocumentTemplate::query()->where('business_id', $tenant['business']->id)->count());
        $this->assertSame($hash, $this->templateHash($platform));
    }

    public function test_installing_a_business_through_the_installer_records_the_reference_and_copies_nothing(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $platform = $this->livePlatformTemplate();
        $this->assignAndPublish($platform, $blueprint);
        $templates = DocumentTemplate::query()->count();

        // A Business created AFTER the blueprint was published is provisioned from it.
        $tenant = $this->photoBoothTenant('Fresh Booth Co');
        $installer = app(NicheBlueprintInstaller::class);
        $result = $installer->installForBusiness($tenant['business']);
        $this->assertNotNull($result);

        $record = BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $tenant['business']->id)
            ->where('component_type', 'document_template')
            ->first();
        $this->assertNotNull($record, 'the installer decided the component');
        $this->assertSame(BlueprintComponentInstallationState::Installed, $record->state);
        $this->assertSame('document_template', $record->installed_record_type);
        $this->assertSame((int) $platform->id, (int) $record->installed_record_id);
        $this->assertSame('payments_contracts', $record->required_feature_key);

        // Re-running is a no-op: same record, still nothing copied into the Business.
        $installer->installForBusiness($tenant['business']->fresh());
        $this->assertSame(1, BusinessBlueprintComponentInstallation::query()->where('business_id', $tenant['business']->id)->where('component_type', 'document_template')->count());
        $this->assertSame($templates, DocumentTemplate::query()->count());
        $this->assertSame(0, DocumentTemplate::query()->where('business_id', $tenant['business']->id)->count());
        $this->assertSame([$platform->uid], $this->recommendedUids($tenant['business']));
    }

    public function test_the_installation_row_is_provenance_only_a_recommendation_needs_none(): void
    {
        // The Business exists BEFORE the blueprint is published, so it was never provisioned from it.
        $tenant = $this->photoBoothTenant();
        $blueprint = $this->seedPhotoBoothBlueprint();
        $platform = $this->livePlatformTemplate();
        $this->assertSame(0, BusinessBlueprintComponentInstallation::query()->where('business_id', $tenant['business']->id)->count());

        $this->assignAndPublish($platform, $blueprint);

        $this->assertSame([$platform->uid], $this->recommendedUids($tenant['business']), 'an existing Business is recommended it without any installation row');
        $this->assertSame(0, DB::table('business_blueprint_component_installations')->where('business_id', $tenant['business']->id)->count());
        $this->assertInstanceOf(Business::class, $tenant['business']);
        $this->assertTrue(Str::isUuid($platform->uid));
    }
}
