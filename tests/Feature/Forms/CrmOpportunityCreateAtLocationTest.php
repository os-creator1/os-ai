<?php

namespace Tests\Feature\Forms;

use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Forms V1 — CrmOpportunityService::createAtLocation(): the ids-only extension
 * that re-derives every Contact, Location, Pipeline and Stage from persistence.
 * `create()` itself is unchanged and is covered by the existing CRM suites.
 */
class CrmOpportunityCreateAtLocationTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private Business $business;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private CrmPipeline $pipeline;

    private CrmOpportunityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->pipeline = $this->formsPipeline($this->business);
        $this->service = app(CrmOpportunityService::class);
    }

    private function contactAt(?BusinessLocation $location, ?Business $business = null, string $phone = '14155550100'): Contacts
    {
        $business ??= $this->business;
        $group = ContactGroups::query()->where('business_id', $business->id)->first()
            ?? ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Contacts', 'status' => true]);

        return Contacts::create([
            'customer_id' => $group->customer_id,
            'business_id' => $business->id,
            'location_id' => $location?->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
    }

    private function create(int $locationId, int $pipelineId, int $contactId, ?int $stageId = null, ?int $businessId = null): CrmOpportunity
    {
        return $this->service->createAtLocation(
            $businessId ?? (int) $this->business->id, $locationId, $pipelineId, $contactId, 'A deal', null, $stageId, null, CrmOpportunity::SOURCE_FORM
        );
    }

    private function assertRefused(callable $attempt): void
    {
        $before = CrmOpportunity::count();

        try {
            $attempt();
            $this->fail('expected a CrmRuleException');
        } catch (CrmRuleException) {
            $this->assertSame($before, CrmOpportunity::count(), 'a refusal creates no deal');
        }
    }

    public function test_the_signature_takes_ids_only_so_no_caller_supplied_model_can_be_trusted(): void
    {
        foreach ((new ReflectionMethod(CrmOpportunityService::class, 'createAtLocation'))->getParameters() as $parameter) {
            $this->assertContains((string) $parameter->getType(), ['int', '?int', 'string'], $parameter->getName());
        }
    }

    public function test_it_creates_one_deal_at_the_authoritative_location(): void
    {
        $contact = $this->contactAt($this->downtown);

        $opportunity = $this->create($this->downtown->id, $this->pipeline->id, $contact->id);

        $this->assertSame((int) $this->business->id, (int) $opportunity->business_id);
        $this->assertSame((int) $this->downtown->id, (int) $opportunity->location_id);
        $this->assertSame((int) $contact->id, (int) $opportunity->contact_id);
        $this->assertSame(CrmOpportunity::SOURCE_FORM, $opportunity->source);
        $this->assertSame(1, CrmOpportunity::count());
    }

    public function test_a_named_stage_of_the_pipeline_is_used_and_a_foreign_stage_is_refused(): void
    {
        $contact = $this->contactAt($this->downtown);
        $stage = CrmPipelineStage::where('pipeline_id', $this->pipeline->id)->orderByDesc('position')->firstOrFail();

        $this->assertSame((int) $stage->id, (int) $this->create($this->downtown->id, $this->pipeline->id, $contact->id, $stage->id)->stage_id);

        $otherPipeline = app(\App\Library\Crm\CrmPipelineService::class)->createPipeline($this->business, 'Second pipeline');
        $strangerStage = CrmPipelineStage::where('pipeline_id', $otherPipeline->id)->firstOrFail();
        $this->assertRefused(fn () => $this->create($this->downtown->id, $this->pipeline->id, $contact->id, $strangerStage->id));

        $this->assertRefused(fn () => $this->create($this->downtown->id, $this->pipeline->id, $contact->id, 99999999));
    }

    public function test_a_location_of_another_business_is_refused_even_when_it_is_named_directly(): void
    {
        [, $other] = $this->formsTenant(name: 'Other Studio');
        $foreignLocation = $this->formsLocation($other, 'Elsewhere');
        $contact = $this->contactAt($this->downtown);

        $this->assertRefused(fn () => $this->create($foreignLocation->id, $this->pipeline->id, $contact->id));
        $this->assertRefused(fn () => $this->create(99999999, $this->pipeline->id, $contact->id));
    }

    public function test_a_contact_must_sit_at_the_named_location(): void
    {
        $atUptown = $this->contactAt($this->uptown);
        $locationless = $this->contactAt(null, null, '14155550101');

        $this->assertRefused(fn () => $this->create($this->downtown->id, $this->pipeline->id, $atUptown->id));
        $this->assertRefused(fn () => $this->create($this->downtown->id, $this->pipeline->id, $locationless->id));
        $this->assertSame(0, CrmOpportunity::count(), 'nothing was created');
    }

    public function test_a_contact_or_pipeline_of_another_business_is_refused(): void
    {
        [, $other] = $this->formsTenant(name: 'Other Studio');
        $theirLocation = $this->formsLocation($other, 'Elsewhere');
        $theirPipeline = $this->formsPipeline($other);
        $theirContact = $this->contactAt($theirLocation, $other);
        $mine = $this->contactAt($this->downtown);

        $this->assertRefused(fn () => $this->create($this->downtown->id, $this->pipeline->id, $theirContact->id));
        $this->assertRefused(fn () => $this->create($this->downtown->id, $theirPipeline->id, $mine->id));
        // Re-pointing the Business id at the OTHER Business cannot launder a foreign Location.
        $this->assertRefused(fn () => $this->create($this->downtown->id, $this->pipeline->id, $mine->id, null, (int) $other->id));
        $this->assertRefused(fn () => $this->create($this->downtown->id, $this->pipeline->id, $mine->id, null, 99999999));
    }

    public function test_an_archived_pipeline_is_refused(): void
    {
        $contact = $this->contactAt($this->downtown);
        DB::table('crm_pipelines')->where('id', $this->pipeline->id)->update(['archived_at' => now()]);

        $this->assertRefused(fn () => $this->create($this->downtown->id, $this->pipeline->id, $contact->id));
    }

    public function test_the_existing_create_path_still_uses_the_single_active_location_rule(): void
    {
        // One Active Location only: archive Uptown so the rule has one answer.
        DB::table('business_locations')->where('id', $this->uptown->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);
        $contact = $this->contactAt($this->downtown);

        $opportunity = $this->service->create($this->business, $this->pipeline, $contact, 'Legacy path');

        $this->assertSame((int) $this->downtown->id, (int) $opportunity->location_id);
        $this->assertSame(CrmOpportunity::SOURCE_MANUAL, $opportunity->source);
    }
}
