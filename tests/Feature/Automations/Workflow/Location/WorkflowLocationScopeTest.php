<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\Triggers\DateReachedTriggerSource;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Library\Automation\Workflow\WorkflowSimulator;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ChatBox;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\MessageReceived\Support\BuildsInboundFixtures;
use Tests\TestCase;

/**
 * Automations Location run-scope — the scope itself: what may be published, how it
 * is pinned and shown, and what the contact-based triggers offer.
 */
class WorkflowLocationScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsFoundationWorkflows;
    use BuildsInboundFixtures;
    use CallsWorkflowRoutes;

    private Business $business;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        [, $this->business] = $this->entitledTenant();
        $this->downtown = $this->location($this->business, 'Downtown');
        $this->uptown = $this->location($this->business, 'Uptown');
    }

    private function location(Business $business, string $name): BusinessLocation
    {
        return BusinessLocation::create(['business_id' => $business->id, 'name' => $name, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    private function version(AutomationWorkflow $workflow): AutomationWorkflowVersion
    {
        return AutomationWorkflowVersion::query()->findOrFail($workflow->fresh()->published_version_id);
    }

    private function contactAt(?BusinessLocation $location, string $phone): Contacts
    {
        static $group = [];
        $group[$this->business->id] ??= $this->contactGroup($this->business);
        $contact = $this->contact($this->business, $group[$this->business->id], $phone);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location?->id]);

        return $contact->fresh();
    }

    // =================================================================
    // 1-4. Publishing
    // =================================================================

    public function test_a_business_wide_workflow_publishes_with_no_scope(): void
    {
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment);

        $this->assertNull($this->version($workflow)->business_location_id);
        $this->assertNull($this->version($workflow)->scope()->singleId());
    }

    public function test_a_workflow_bound_to_an_own_active_location_publishes_and_pins_it(): void
    {
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment, ['business_location_id' => $this->downtown->id]);

        $this->assertSame((int) $this->downtown->id, $this->version($workflow)->scope()->singleId());
    }

    public function test_a_string_id_from_the_browser_is_accepted_and_stored_as_an_integer(): void
    {
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment, ['business_location_id' => (string) $this->downtown->id]);

        $this->assertSame((int) $this->downtown->id, $this->version($workflow)->scope()->singleId());
    }

    public function test_a_foreign_missing_archived_or_malformed_location_cannot_publish(): void
    {
        [, $other] = $this->entitledTenant();
        $theirs = $this->location($other, 'Their Location');
        $archived = $this->location($this->business, 'Closed');
        DB::table('business_locations')->where('id', $archived->id)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);

        foreach ([
            'foreign' => [(int) $theirs->id, 'does not belong to this business'],
            'missing' => [987654, 'does not belong to this business'],
            'archived' => [(int) $archived->id, 'archived'],
            'malformed' => ['abc', 'valid location'],
            'zero' => [0, 'valid location'],
        ] as $label => [$value, $message]) {
            try {
                $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment, ['business_location_id' => $value]);
                $this->fail("A {$label} Location must not publish.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($message, json_encode($exception->errors()), $label);
            }
        }

        $this->assertSame(0, AutomationWorkflow::query()->where('business_id', $this->business->id)->whereNotNull('published_version_id')->count());
    }

    public function test_the_shape_rule_lives_in_the_registry_and_the_tenancy_rule_in_the_compiler(): void
    {
        $base = ['trigger_type' => 'manual_enrollment', 'enrollment_policy' => 'once_ever', 'enrollment_policy_source' => 'default', 'failure_policy' => 'halt'];
        $registry = new NodeTypeRegistry();

        $this->assertSame([], $registry->validateConfig(\App\Enums\Automation\Workflow\WorkflowNodeType::Trigger, $base + ['business_location_id' => null]));
        $this->assertSame([], $registry->validateConfig(\App\Enums\Automation\Workflow\WorkflowNodeType::Trigger, $base + ['business_location_id' => 5]));
        $this->assertNotSame([], $registry->validateConfig(\App\Enums\Automation\Workflow\WorkflowNodeType::Trigger, $base + ['business_location_id' => 'x']));

        // Shape-valid but foreign: the registry cannot know; the compiler refuses against real rows.
        [, $other] = $this->entitledTenant();
        $theirs = $this->location($other, 'Their Location');
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($this->business, 'Scoped', WorkflowTriggerType::ManualEnrollment);
        $draft = $workflow->draftVersion();
        $definition = $drafts->starterDefinition(WorkflowTriggerType::ManualEnrollment);
        $definition['root']['config']['business_location_id'] = (int) $theirs->id;
        $definition['root']['next'] = [$this->endStep()];
        $drafts->autosave($draft, $definition, (int) $draft->definition_revision);

        $errors = app(WorkflowCompiler::class)->validate($draft->fresh());

        $this->assertArrayHasKey($definition['root']['key'], $errors);
    }

    // =================================================================
    // A published version's scope is history
    // =================================================================

    public function test_changing_the_scope_takes_a_new_publish_and_leaves_the_old_version_and_its_journeys_alone(): void
    {
        $drafts = app(WorkflowDraftService::class);
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment, ['business_location_id' => $this->downtown->id]);
        $first = $this->version($workflow);
        $contact = $this->contactAt($this->downtown, '12025550001');

        $enrollment = app(\App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource::class)->enrollByHand($workflow->fresh(), $contact, 'req-1');
        $this->assertNotNull($enrollment);

        // Re-scope: edit a draft cloned from the live version and publish it.
        $draft = $drafts->ensureDraft($workflow->fresh());
        $definition = $draft->definition;
        $this->assertSame((int) $this->downtown->id, (int) $definition['root']['config']['business_location_id'], 'The draft starts from the live scope.');
        $definition['root']['config']['business_location_id'] = (int) $this->uptown->id;
        $drafts->autosave($draft, $definition, (int) $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        $second = $this->version($workflow);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame((int) $this->downtown->id, (int) $first->fresh()->business_location_id, 'History is not rewritten.');
        $this->assertSame((int) $this->uptown->id, $second->scope()->singleId());
        $this->assertSame((int) $first->id, (int) $enrollment->fresh()->version_id);
        $this->assertSame((int) $this->downtown->id, (int) $enrollment->fresh()->business_location_id, 'A journey keeps the scope it started under.');

        // New facts now answer to the new scope.
        $this->assertNull(app(\App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource::class)->enrollByHand($workflow->fresh(), $this->contactAt($this->downtown, '12025550002'), 'req-2'));
        $this->assertNotNull(app(\App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource::class)->enrollByHand($workflow->fresh(), $this->contactAt($this->uptown, '12025550003'), 'req-3'));
    }

    // =================================================================
    // Contact-based triggers
    // =================================================================

    public function test_the_date_sweep_considers_only_contacts_of_a_bound_workflows_location(): void
    {
        DB::table('businesses')->where('id', $this->business->id)->update(['timezone' => 'UTC']);
        $group = $this->contactGroup($this->business, 'Birthdays');
        $field = $this->dateField($group);

        $config = ['contact_group_id' => $group->id, 'date_field_id' => $field->id, 'offset' => '0 day', 'send_at' => '09:00'];
        $forDowntown = $this->triggerWorkflow($this->business, WorkflowTriggerType::ContactDateReached, $config + ['business_location_id' => $this->downtown->id]);
        $wide = $this->triggerWorkflow($this->business, WorkflowTriggerType::ContactDateReached, $config);

        foreach ([['12025551201', $this->downtown], ['12025551202', $this->uptown], ['12025551203', null]] as [$phone, $location]) {
            $contact = $this->contact($this->business, $group, $phone, '1990-06-15', $field);
            DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location?->id]);
        }

        app(DateReachedTriggerSource::class)->sweep(\Carbon\CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100);

        $this->assertSame(1, $this->enrollmentCount($forDowntown), 'Only the Downtown contact is due for the Downtown workflow.');
        $this->assertSame((int) $this->downtown->id, (int) AutomationEnrollment::query()->where('workflow_id', $forDowntown->id)->value('business_location_id'));
        $this->assertSame(3, $this->enrollmentCount($wide), 'The Business-wide workflow still takes everyone.');
        $this->assertSame(
            [null, (int) $this->downtown->id, (int) $this->uptown->id],
            AutomationEnrollment::query()->where('workflow_id', $wide->id)->orderBy('business_location_id')->pluck('business_location_id')->map(fn ($id) => $id === null ? null : (int) $id)->all(),
            'Each pins its own contact\'s Location, or none.',
        );
    }

    public function test_manual_enrollment_into_a_bound_workflow_refuses_contacts_of_other_locations_up_front(): void
    {
        // Fake the enrollment job too, so "queued" is observable rather than run inline.
        Bus::fake([AdvanceWorkflowEnrollment::class, EnrollWorkflowContact::class]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $downtown = $this->location($business, 'Downtown');
        $uptown = $this->location($business, 'Uptown');
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, ['business_location_id' => $downtown->id]);
        $group = $this->contactGroup($business, 'Clients');
        $here = $this->contact($business, $group, '12025551301');
        $there = $this->contact($business, $group, '12025551302');
        $nowhere = $this->contact($business, $group, '12025551303');
        DB::table('contacts')->where('id', $here->id)->update(['location_id' => $downtown->id]);
        DB::table('contacts')->where('id', $there->id)->update(['location_id' => $uptown->id]);

        $this->authenticateAsCustomer($customer);
        $url = $this->routeUrl('enrollments.manual', $workspace, $business, $workflow);

        $this->callJson('POST', $url, ['contact_uids' => [$here->uid, $there->uid], 'confirmed' => true])->assertStatus(422);
        $this->callJson('POST', $url, ['contact_uids' => [$nowhere->uid], 'confirmed' => true])->assertStatus(422);
        Bus::assertNotDispatched(EnrollWorkflowContact::class);
        $this->assertSame(0, $this->enrollmentCount($workflow), 'Nothing is partly honoured.');

        $this->callJson('POST', $url, ['contact_uids' => [$here->uid], 'confirmed' => true])->assertStatus(202)->assertJsonPath('queued', 1);
        Bus::assertDispatched(EnrollWorkflowContact::class, 1);
    }

    public function test_a_message_takes_the_location_of_its_one_conversation(): void
    {
        $forDowntown = $this->triggerWorkflow($this->business, WorkflowTriggerType::MessageReceived, ['business_location_id' => $this->downtown->id]);
        $forUptown = $this->triggerWorkflow($this->business, WorkflowTriggerType::MessageReceived, ['business_location_id' => $this->uptown->id]);
        $wide = $this->triggerWorkflow($this->business, WorkflowTriggerType::MessageReceived);

        // The contact's own Location says Uptown; the CONVERSATION says Downtown, and a message belongs to its thread.
        $this->contactAt($this->uptown, '14155551401');
        $this->chat('14155551401', $this->downtown);

        $this->messageSource()->handleInboundMessage($this->legacyInbound($this->business, '14155551401'));

        $this->assertSame(1, $this->enrollmentCount($forDowntown));
        $this->assertSame(0, $this->enrollmentCount($forUptown));
        $this->assertSame((int) $this->downtown->id, (int) AutomationEnrollment::query()->where('workflow_id', $wide->id)->value('business_location_id'));
    }

    public function test_an_unplaced_or_ambiguous_conversation_enrolls_no_bound_workflow(): void
    {
        $bound = $this->triggerWorkflow($this->business, WorkflowTriggerType::MessageReceived, ['business_location_id' => $this->downtown->id]);
        $wide = $this->triggerWorkflow($this->business, WorkflowTriggerType::MessageReceived);

        // No conversation at all.
        $this->contactAt($this->downtown, '14155551501');
        $this->messageSource()->handleInboundMessage($this->legacyInbound($this->business, '14155551501'));

        // Two conversations for the number: picking one would be a guess.
        $this->contactAt($this->downtown, '14155551502');
        $this->chat('14155551502', $this->downtown, '14155550001');
        $this->chat('14155551502', $this->uptown, '14155550002');
        $this->messageSource()->handleInboundMessage($this->legacyInbound($this->business, '14155551502'));

        $this->assertSame(0, $this->enrollmentCount($bound));
        $this->assertSame(2, $this->enrollmentCount($wide));
        $this->assertNull(AutomationEnrollment::query()->where('workflow_id', $wide->id)->whereNotNull('business_location_id')->first()?->business_location_id);
    }

    private function chat(string $phone, BusinessLocation $location, string $from = '14155550100'): void
    {
        ChatBox::create([
            'user_id' => $this->business->customer_id,
            'business_id' => $this->business->id,
            'location_id' => $location->id,
            'from' => $from,
            'to' => $phone,
        ]);
    }

    // =================================================================
    // Simulator and list
    // =================================================================

    public function test_the_simulator_refuses_a_contact_a_bound_workflow_would_never_take(): void
    {
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment, ['business_location_id' => $this->downtown->id]);
        $version = $this->version($workflow);
        $simulator = app(WorkflowSimulator::class);

        $elsewhere = $simulator->simulate($version, $this->contactAt($this->uptown, '12025551601'));
        $nowhere = $simulator->simulate($version, $this->contactAt(null, '12025551602'));
        $here = $simulator->simulate($version, $this->contactAt($this->downtown, '12025551603'));

        $this->assertSame(WorkflowSimulator::REFUSED_OUTSIDE_LOCATION, $elsewhere['refused']);
        $this->assertSame(WorkflowSimulator::REFUSED_OUTSIDE_LOCATION, $nowhere['refused']);
        $this->assertNull($here['refused']);
        $this->assertSame(['trigger', 'end'], array_column($here['steps'], 'type'));
        $this->assertSame(0, AutomationEnrollment::query()->count(), 'A simulation writes no enrollment.');
    }

    public function test_the_workflow_list_shows_where_each_live_workflow_applies(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $downtown = $this->location($business, 'Downtown');
        $bound = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, ['business_location_id' => $downtown->id], null, 'Bound flow');
        $wide = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], null, 'Wide flow');

        $this->authenticateAsCustomer($customer);
        $body = $this->callJson('GET', $this->routeUrl('index', $workspace, $business))->assertOk()->json('workflows');

        $byName = collect($body)->keyBy('name');
        $this->assertSame('Downtown', $byName['Bound flow']['scope_location_name']);
        $this->assertNull($byName['Wide flow']['scope_location_name']);

        $html = $this->get($this->routeUrl('index', $workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Downtown', $html);
        $this->assertStringContainsString('Whole business', $html);
        $this->assertNotNull($bound);
        $this->assertNotNull($wide);
    }
}
