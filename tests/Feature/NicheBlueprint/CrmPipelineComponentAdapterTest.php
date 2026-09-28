<?php

namespace Tests\Feature\NicheBlueprint;

use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Contract 20 §5.5/§10/§12.D — the CRM pipeline component adapter in
 * isolation: its own descriptor validation and its own translation into the
 * existing `BusinessTemplateApplier::copyPipeline()` seam.
 *
 * End-to-end publish/install behaviour (through the real publisher and
 * installer) lives in CrmPipelineBlueprintPublishAndInstallTest.
 */
class CrmPipelineComponentAdapterTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    /** Contract 20 §5.5's exact component payload. */
    private function validPayload(): array
    {
        return [
            'template_key' => 'photo_booth',
            'template_version' => 1,
            'pipeline_key' => 'sales',
            'name' => 'Sales pipeline',
            'stages' => [
                ['name' => 'New Lead', 'semantic_key' => 'new_inquiry'],
                ['name' => 'Auto Follow-Up', 'semantic_key' => 'auto_follow_up'],
                ['name' => 'In Contact', 'semantic_key' => 'in_contact'],
                ['name' => 'Proposal Sent', 'semantic_key' => 'proposal_sent'],
                ['name' => 'Invoice Sent', 'semantic_key' => 'invoice_sent'],
                ['name' => 'Questionnaire Sent', 'semantic_key' => 'questionnaire_sent'],
                ['name' => 'Questionnaire Submitted', 'semantic_key' => 'questionnaire_submitted'],
                ['name' => 'Done', 'semantic_key' => 'done'],
            ],
        ];
    }

    private function adapter(): CrmPipelineComponentAdapter
    {
        return app(CrmPipelineComponentAdapter::class);
    }

    // --------------------------------------------------------------- type

    public function test_component_type_is_crm_pipeline(): void
    {
        $this->assertSame('crm_pipeline', $this->adapter()->componentType());
    }

    public function test_the_real_adapter_is_registered_in_the_container(): void
    {
        $registry = app(BlueprintComponentAdapterRegistry::class);

        $this->assertTrue($registry->has('crm_pipeline'));
        $this->assertInstanceOf(CrmPipelineComponentAdapter::class, $registry->adapterFor('crm_pipeline'));
    }

    // ---------------------------------------------------- validateDescriptor

    public function test_validate_descriptor_accepts_the_contracted_payload(): void
    {
        $this->adapter()->validateDescriptor($this->validPayload());
        $this->addToAssertionCount(1);
    }

    public function test_validate_descriptor_rejects_a_payload_whose_first_stage_is_not_new_inquiry(): void
    {
        $payload = $this->validPayload();
        $payload['stages'][0]['semantic_key'] = 'not_new_inquiry';

        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->validateDescriptor($payload);
    }

    /**
     * @dataProvider malformedPayloadProvider
     */
    public function test_validate_descriptor_rejects_malformed_payloads(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->validateDescriptor($payload);
    }

    public static function malformedPayloadProvider(): array
    {
        $valid = [
            'template_key' => 'photo_booth',
            'template_version' => 1,
            'pipeline_key' => 'sales',
            'name' => 'Sales pipeline',
            'stages' => [['name' => 'New Lead', 'semantic_key' => 'new_inquiry']],
        ];

        return [
            'missing template_key' => [self::withoutKey($valid, 'template_key')],
            'blank template_key' => [array_merge($valid, ['template_key' => '   '])],
            'missing template_version' => [self::withoutKey($valid, 'template_version')],
            'zero template_version' => [array_merge($valid, ['template_version' => 0])],
            'missing pipeline_key' => [self::withoutKey($valid, 'pipeline_key')],
            'missing name' => [self::withoutKey($valid, 'name')],
            'missing stages' => [self::withoutKey($valid, 'stages')],
            'empty stages' => [array_merge($valid, ['stages' => []])],
            'stages not a list' => [array_merge($valid, ['stages' => ['a' => ['name' => 'X', 'semantic_key' => 'new_inquiry']]])],
            'stage missing name' => [array_merge($valid, ['stages' => [['semantic_key' => 'new_inquiry']]])],
            'stage non-string semantic_key' => [array_merge($valid, ['stages' => [['name' => 'X', 'semantic_key' => 123]]])],
        ];
    }

    private static function withoutKey(array $payload, string $key): array
    {
        unset($payload[$key]);

        return $payload;
    }

    // ------------------------------------------------------------- install

    public function test_install_creates_a_business_owned_pipeline_with_stages_in_order(): void
    {
        [, $business] = $this->tenant();

        $reference = $this->adapter()->install($business, $this->validPayload(), null);

        $pipeline = CrmPipeline::query()->findOrFail($reference->recordId);

        $this->assertSame((int) $business->id, (int) $pipeline->business_id);
        $this->assertSame('Sales pipeline', $pipeline->name);
        $this->assertSame('photo_booth', $pipeline->template_key);
        $this->assertSame(1, $pipeline->template_version);
        $this->assertSame('sales', $pipeline->template_pipeline_key);

        $stages = CrmPipelineStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->orderBy('position')
            ->get();

        $this->assertSame(
            ['new_inquiry', 'auto_follow_up', 'in_contact', 'proposal_sent', 'invoice_sent', 'questionnaire_sent', 'questionnaire_submitted', 'done'],
            $stages->pluck('semantic_key')->all()
        );
        $this->assertSame(
            ['New Lead', 'Auto Follow-Up', 'In Contact', 'Proposal Sent', 'Invoice Sent', 'Questionnaire Sent', 'Questionnaire Submitted', 'Done'],
            $stages->pluck('name')->all()
        );
        $this->assertTrue($stages->first()->isNewInquiry());
    }

    public function test_install_returns_a_reference_to_the_real_pipeline_row(): void
    {
        [, $business] = $this->tenant();

        $reference = $this->adapter()->install($business, $this->validPayload(), null);

        $this->assertSame('crm_pipeline', $reference->recordType);
        $this->assertTrue(CrmPipeline::query()->where('id', $reference->recordId)->where('business_id', $business->id)->exists());
    }

    public function test_install_stamps_the_acting_user_as_created_by(): void
    {
        [$customer, $business] = $this->tenant();

        $reference = $this->adapter()->install($business, $this->validPayload(), (int) $customer->user_id);

        $pipeline = CrmPipeline::query()->findOrFail($reference->recordId);

        $this->assertSame((int) $customer->user_id, (int) $pipeline->created_by_user_id);
    }
}
