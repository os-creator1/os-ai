<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\Feature\Calendar\Concerns\CreatesCalendarTestData;
use Tests\TestCase;

/**
 * Automations under Agency View As.
 *
 * View As is true impersonation of the viewed customer — bounded by everything that
 * bounds that customer — so Automations inside it operate ONLY inside the viewed
 * client Business: they never expose the Agency's own workflows, never another
 * client's, never let an Agency-side resource id into a client's workflow, and never
 * bypass the client's Business or Location authority. No new View As mechanism: this
 * is the existing tenancy chain, pinned for the new steps.
 */
class AutomationsViewAsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;
    use BuildsFoundationWorkflows;
    use CallsWorkflowRoutes;

    /** @var array<string, mixed> */
    private array $pair;

    private Business $client;

    private Business $agency;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $this->pair = $this->createAgencyManagedClient(clientBusinessName: 'Harbor Lane Studios', agencyBusinessName: 'Primary', agencyWorkspaceName: 'Northwind Agency');
        $this->assignTier($this->pair['clientWorkspace'], WorkspacePlanTier::Growth);

        foreach (['clientBusiness', 'agencyBusiness'] as $key) {
            $business = $this->pair[$key];
            $business->status = BusinessStatus::Active;
            $business->currency_code = 'USD';
            $business->save();
        }

        $this->client = $this->pair['clientBusiness']->fresh();
        $this->agency = $this->pair['agencyBusiness']->fresh();
    }

    private function viewing(): void
    {
        $this->authenticateAs($this->pair['agencyOwner']);
        $this->post(route('customer.workspaces.clients.view-as', [$this->pair['agencyWorkspace']->uid, $this->pair['clientWorkspace']->uid]))
            ->assertRedirect(route('user.home'));
    }

    private function clientUrl(string $name, ?AutomationWorkflow $workflow = null): string
    {
        return $this->routeUrl($name, $this->pair['clientWorkspace'], $this->client, $workflow);
    }

    private function catalogItem(Business $business, string $name)
    {
        return app(\App\Library\Catalog\CatalogItemManager::class)->create($business, [
            'type' => 'package', 'name' => $name, 'description' => null, 'price_minor' => 10000, 'currency_code' => 'USD',
        ]);
    }

    private function location(Business $business, string $name): BusinessLocation
    {
        return BusinessLocation::create(['business_id' => $business->id, 'name' => $name, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    public function test_the_list_inside_view_as_is_the_clients_alone(): void
    {
        $theirs = $this->triggerWorkflow($this->client, WorkflowTriggerType::ManualEnrollment, [], null, 'Client welcome');
        $agencys = $this->triggerWorkflow($this->agency, WorkflowTriggerType::ManualEnrollment, [], null, 'Agency prospecting');

        $this->viewing();
        $names = collect($this->callJson('GET', $this->clientUrl('index'))->assertOk()->json('workflows'))->pluck('name')->all();

        $this->assertSame(['Client welcome'], $names);
        $this->assertNotContains($agencys->name, $names);
        $this->assertNotNull($theirs);
    }

    public function test_the_agencys_own_workflow_is_a_404_through_the_clients_address(): void
    {
        $agencys = $this->triggerWorkflow($this->agency, WorkflowTriggerType::ManualEnrollment, [], null, 'Agency prospecting');

        $this->viewing();

        foreach (['show', 'pause', 'archive'] as $name) {
            $method = $name === 'show' ? 'GET' : 'POST';
            $this->callJson($method, $this->clientUrl($name, $agencys))->assertStatus(404);
        }

        $this->callJson('GET', $this->clientUrl('draft.show', $agencys))->assertStatus(404);
        $this->callJson('GET', $this->clientUrl('enrollments.index', $agencys))->assertStatus(404);
    }

    public function test_the_builder_offers_the_clients_resources_and_never_the_agencys(): void
    {
        $this->catalogItem($this->client, 'Client package');
        $this->catalogItem($this->agency, 'Agency retainer');
        $clientLocation = $this->location($this->client, 'Client Main');
        $agencyLocation = $this->location($this->agency, 'Agency HQ');
        $this->xBookingTypeFor($clientLocation, 'Client consultation');
        $this->xBookingTypeFor($agencyLocation, 'Agency discovery call');
        $workflow = $this->triggerWorkflow($this->client, WorkflowTriggerType::ManualEnrollment);

        $this->viewing();
        $html = $this->get($this->clientUrl('show', $workflow))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="wf-builder-data">(.*?)</script>#s', $html, $match);
        $data = json_decode(html_entity_decode($match[1]), true);

        $this->assertSame(['Client package'], array_column($data['catalogs']['catalogItems'], 'name'));
        $this->assertSame(['Client consultation'], array_column($data['catalogs']['bookingTypes'], 'name'));
        $this->assertSame(['Client Main'], array_column($data['catalogs']['locations'], 'name'));
        $this->assertStringNotContainsString('Agency retainer', $html);
        $this->assertStringNotContainsString('Agency discovery call', $html);
    }

    public function test_an_agency_side_resource_id_can_never_enter_a_clients_workflow(): void
    {
        $agencyItem = $this->catalogItem($this->agency, 'Agency retainer');
        $agencyLocation = $this->location($this->agency, 'Agency HQ');
        $agencyType = $this->xBookingTypeFor($agencyLocation, 'Agency discovery call');
        $clientLocation = $this->location($this->client, 'Client Main');
        $drafts = app(WorkflowDraftService::class);

        foreach ([
            'catalog item' => ['create_send_proposal', ['title' => 'X', 'catalog_item_id' => $agencyItem->id, 'quantity' => 1, 'payment_schedule' => 'full']],
            'booking type' => ['send_booking_link', ['booking_type_id' => $agencyType->id, 'channels' => ['email']]],
            'location' => ['trigger', ['business_location_id' => $agencyLocation->id]],
        ] as $label => [$type, $config]) {
            $workflow = $drafts->createWorkflowWithDraft($this->client, 'Forged ' . $label, WorkflowTriggerType::ManualEnrollment);
            $draft = $workflow->draftVersion();
            $definition = $drafts->starterDefinition(WorkflowTriggerType::ManualEnrollment);

            if ($type === 'trigger') {
                $definition['root']['config'] = array_merge($definition['root']['config'], $config);
                $definition['root']['next'] = [$this->endStep()];
            } else {
                $definition['root']['next'] = [['key' => (string) \Illuminate\Support\Str::uuid(), 'type' => $type, 'config' => $config], $this->endStep()];
            }

            $drafts->autosave($draft, $definition, (int) $draft->definition_revision);

            try {
                app(WorkflowPublisher::class)->publish($workflow->fresh());
                $this->fail("An Agency-side {$label} must not publish into a client's workflow.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('does not belong to this business', json_encode($exception->errors()), $label);
            }
        }

        $this->assertNotNull($clientLocation);
    }

    public function test_inside_view_as_the_actor_still_goes_through_the_clients_location_authority(): void
    {
        $uptown = $this->location($this->client, 'Uptown');
        $downtown = $this->location($this->client, 'Downtown');
        $boundToUptown = $this->triggerWorkflow($this->client, WorkflowTriggerType::ManualEnrollment, ['business_location_id' => $uptown->id], null, 'Uptown only');
        $wide = $this->triggerWorkflow($this->client, WorkflowTriggerType::ManualEnrollment, [], null, 'Whole business');

        $this->viewing();
        $names = collect($this->callJson('GET', $this->clientUrl('index'))->assertOk()->json('workflows'))->pluck('name')->all();

        // View As of an owner-level client reaches every Location of the viewed Business — and only that Business's.
        $this->assertEqualsCanonicalizing(['Uptown only', 'Whole business'], $names);
        $this->callJson('POST', $this->clientUrl('pause', $boundToUptown))->assertOk();
        $this->assertNotNull($downtown);
        $this->assertNotNull($wide);
    }

    /** A booking type, created directly (the Calendar manager needs a staff actor this test does not otherwise use). */
    private function xBookingTypeFor(BusinessLocation $location, string $name): \App\Models\BookingType
    {
        return \App\Models\BookingType::create(['business_location_id' => $location->id, 'name' => $name, 'duration_minutes' => 30, 'is_active' => true]);
    }
}
