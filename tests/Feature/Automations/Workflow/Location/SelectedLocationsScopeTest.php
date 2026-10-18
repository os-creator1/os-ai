<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Triggers\DateReachedTriggerSource;
use App\Library\Automation\Workflow\WorkflowLocationScope;
use App\Library\Automation\Workflow\WorkflowSimulator;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Automations Location run-scope — the third scope, "Selected locations".
 *
 * The contract: a workflow is Business-wide, limited to ONE Location, or limited to
 * a chosen LIST of Locations. Whatever the scope a RUN is never multi-location —
 * enrollment pins the one Location of the triggering fact — and a bound scope never
 * reads as Business-wide, however it is misshapen.
 */
class SelectedLocationsScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;
    use CreatesFormsFixtures;
    use BuildsFoundationWorkflows;
    use CallsWorkflowRoutes;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private BusinessLocation $midtown;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class, EnrollWorkflowContact::class]);

        [$this->owner, $business, $this->workspace] = $this->formsTenant();
        $this->business = $this->activate($business);
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->midtown = $this->formsLocation($this->business, 'Midtown');
    }

    private function contactGroup(Business $business, string $name = 'Clients'): \App\Models\ContactGroups
    {
        return \App\Models\ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => $name, 'status' => true]);
    }

    private function groupField(\App\Models\ContactGroups $group, string $type, string $tag): \App\Models\ContactGroupFields
    {
        return \App\Models\ContactGroupFields::create([
            'contact_group_id' => $group->id, 'label' => $tag, 'type' => $type, 'tag' => $tag,
            'visible' => true, 'required' => false, 'is_phone' => false,
        ]);
    }

    private function groupContact(Business $business, \App\Models\ContactGroups $group, string $phone, ?string $dateValue = null, ?\App\Models\ContactGroupFields $dateField = null): Contacts
    {
        $contact = Contacts::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => $phone, 'status' => Contacts::STATUS_SUBSCRIBE]);

        if ($dateValue !== null && $dateField !== null) {
            \App\Models\ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $dateField->id, 'value' => $dateValue]);
        }

        return $contact;
    }

    private function updateFieldStep(int $fieldId, string $value): array
    {
        return ['key' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'update_contact_field', 'config' => ['field_id' => $fieldId, 'value' => $value]];
    }

    private function selected(BusinessLocation ...$locations): array
    {
        return [
            'scope_mode' => 'selected',
            'business_location_ids' => array_map(fn (BusinessLocation $l): int => (int) $l->id, $locations),
        ];
    }

    private function publishSelected(BusinessLocation ...$locations): AutomationWorkflow
    {
        return $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment, $this->selected(...$locations));
    }

    private function version(AutomationWorkflow $workflow): AutomationWorkflowVersion
    {
        return AutomationWorkflowVersion::query()->findOrFail($workflow->fresh()->published_version_id);
    }

    private function contactAt(?BusinessLocation $location, string $phone): Contacts
    {
        $contact = $this->crmContact($this->business, [], $phone);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location?->id]);

        return $contact->fresh();
    }

    private function enroll(AutomationWorkflow $workflow, ?BusinessLocation $at, string $phone): ?AutomationEnrollment
    {
        $contact = $this->contactAt($at, $phone);

        return app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, 'occ-' . $phone, 0, $at?->id === null ? null : (int) $at->id);
    }

    // =================================================================
    // Publishing
    // =================================================================

    public function test_a_selected_scope_publishes_with_its_mode_and_its_locations(): void
    {
        $workflow = $this->publishSelected($this->downtown, $this->uptown);
        $version = $this->version($workflow);

        $this->assertSame('selected', $version->scope_mode);
        $this->assertNull($version->business_location_id, 'The single-Location column stays null: no legacy reader can mistake a list for one Location.');
        $this->assertEqualsCanonicalizing(
            [(int) $this->downtown->id, (int) $this->uptown->id],
            DB::table('automation_workflow_version_locations')->where('version_id', $version->id)->pluck('business_location_id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertTrue($version->scope()->isBound());
        $this->assertSame(WorkflowLocationScope::SELECTED, $version->scope()->mode());
        $this->assertNull($version->scope()->singleId());
    }

    public function test_the_three_scopes_are_stored_as_business_one_and_selected(): void
    {
        $business = $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment);
        $one = $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment, ['scope_mode' => 'one', 'business_location_id' => $this->downtown->id]);
        $legacyOne = $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment, ['business_location_id' => $this->uptown->id]);

        $this->assertSame('business', $this->version($business)->scope_mode);
        $this->assertSame('one', $this->version($one)->scope_mode);
        $this->assertSame('one', $this->version($legacyOne)->scope_mode, 'A document written before the mode existed reads as one Location.');
    }

    public function test_misshapen_scopes_cannot_publish(): void
    {
        $a = (int) $this->downtown->id;
        $b = (int) $this->uptown->id;

        foreach ([
            'one location in a list' => [['scope_mode' => 'selected', 'business_location_ids' => [$a]], 'two or more'],
            'empty list' => [['scope_mode' => 'selected', 'business_location_ids' => []], 'two or more'],
            'duplicates' => [['scope_mode' => 'selected', 'business_location_ids' => [$a, $a]], 'two or more'],
            'list plus single' => [['scope_mode' => 'selected', 'business_location_ids' => [$a, $b], 'business_location_id' => $a], 'list'],
            'business with ids' => [['scope_mode' => 'business', 'business_location_ids' => [$a, $b]], 'not limited'],
            'one without id' => [['scope_mode' => 'one'], 'one location'],
            'unknown mode' => [['scope_mode' => 'everywhere'], 'Choose how'],
            'bad member' => [['scope_mode' => 'selected', 'business_location_ids' => [$a, 'x']], 'valid locations'],
            'foreign member' => [['scope_mode' => 'selected', 'business_location_ids' => [$a, 987654]], 'does not belong'],
            'stray list without mode' => [['business_location_ids' => [$a, $b]], 'Choose how'],
        ] as $label => [$config, $message]) {
            try {
                $this->triggerWorkflow($this->business, WorkflowTriggerType::ManualEnrollment, $config);
                $this->fail("{$label} must not publish.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($message, json_encode($exception->errors()), $label);
            }
        }

        $this->assertSame(0, AutomationWorkflow::query()->where('business_id', $this->business->id)->whereNotNull('published_version_id')->count());
    }

    public function test_an_archived_member_cannot_publish(): void
    {
        DB::table('business_locations')->where('id', $this->midtown->id)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);

        try {
            $this->publishSelected($this->downtown, $this->midtown);
            $this->fail('An archived Location must not publish.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('archived', json_encode($exception->errors()));
        }
    }

    public function test_another_businesses_location_never_publishes_in_a_list(): void
    {
        [, $other] = $this->formsTenant();
        $other = $this->activate($other);
        $theirs = BusinessLocation::create(['business_id' => $other->id, 'name' => 'Theirs', 'service_mode' => 'storefront', 'country_code' => 'US']);

        try {
            $this->publishSelected($this->downtown, $theirs);
            $this->fail('A foreign Location must not publish.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('does not belong to this business', json_encode($exception->errors()));
        }
    }

    // =================================================================
    // Enrollment: each run is pinned to ONE actual Location
    // =================================================================

    public function test_a_fact_at_any_selected_location_enrolls_pinned_to_that_one_location(): void
    {
        $workflow = $this->publishSelected($this->downtown, $this->uptown);

        $atDowntown = $this->enroll($workflow, $this->downtown, '14155550101');
        $atUptown = $this->enroll($workflow, $this->uptown, '14155550102');

        $this->assertNotNull($atDowntown);
        $this->assertNotNull($atUptown);
        $this->assertSame((int) $this->downtown->id, (int) $atDowntown->business_location_id);
        $this->assertSame((int) $this->uptown->id, (int) $atUptown->business_location_id, 'A run is never multi-location: it is pinned to its own fact.');
    }

    public function test_a_fact_elsewhere_or_with_no_location_enrolls_nobody(): void
    {
        $workflow = $this->publishSelected($this->downtown, $this->uptown);

        $this->assertNull($this->enroll($workflow, $this->midtown, '14155550103'), 'A Location outside the list.');
        $this->assertNull($this->enroll($workflow, null, '14155550104'), 'A fact with no Location fails closed.');
        $this->assertSame(0, AutomationEnrollment::query()->where('workflow_id', $workflow->id)->count());
    }

    public function test_a_forged_foreign_location_id_enrolls_nobody(): void
    {
        [, $other] = $this->formsTenant();
        $other = $this->activate($other);
        $theirs = BusinessLocation::create(['business_id' => $other->id, 'name' => 'Theirs', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $workflow = $this->publishSelected($this->downtown, $this->uptown);
        $contact = $this->contactAt($this->downtown, '14155550105');

        $this->assertNull(app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, 'forged', 0, (int) $theirs->id));
    }

    public function test_a_contact_who_moves_after_enrolling_is_not_acted_on_under_the_old_pin_and_the_run_is_not_rescoped(): void
    {
        $group = $this->contactGroup($this->business, 'Clients');
        $field = $this->groupField($group, 'text', 'STATUS_NOTE');
        $workflow = $this->triggerWorkflow(
            $this->business,
            WorkflowTriggerType::ContactCreated,
            ['contact_group_id' => $group->id] + $this->selected($this->downtown, $this->uptown),
            [$this->updateFieldStep((int) $field->id, 'vip'), $this->endStep()],
        );
        $contact = $this->groupContact($this->business, $group, '14155550106');
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $this->downtown->id]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact->fresh(), 'k1', 0, (int) $this->downtown->id);
        $this->assertNotNull($enrollment);

        // The contact moves to ANOTHER selected Location before the step runs.
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $this->uptown->id]);
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $step = AutomationStepRun::query()->where('enrollment_id', $enrollment->id)->where('node_type', 'update_contact_field')->sole();
        $this->assertSame(StepRunStatus::Skipped, $step->status);
        $this->assertSame('contact_outside_workflow_location', $step->safe_error_summary);
        $this->assertSame((int) $this->downtown->id, (int) $enrollment->fresh()->business_location_id, 'The pin does not follow the contact.');
    }

    // =================================================================
    // Date trigger, Test workflow, manual enrollment
    // =================================================================

    public function test_the_date_sweep_takes_contacts_of_every_selected_location_and_no_others(): void
    {
        DB::table('businesses')->where('id', $this->business->id)->update(['timezone' => 'UTC']);
        $group = $this->contactGroup($this->business, 'Birthdays');
        $field = $this->groupField($group, \App\Models\ContactGroupFields::TYPE_DATE, 'BIRTH_DATE');

        $workflow = $this->triggerWorkflow(
            $this->business,
            WorkflowTriggerType::ContactDateReached,
            ['contact_group_id' => $group->id, 'date_field_id' => $field->id, 'offset' => '0 day', 'send_at' => '09:00'] + $this->selected($this->downtown, $this->uptown),
        );

        $ids = [];

        foreach ([['12025551401', $this->downtown], ['12025551402', $this->uptown], ['12025551403', $this->midtown], ['12025551404', null]] as [$phone, $location]) {
            $contact = $this->groupContact($this->business, $group, $phone, '1990-06-15', $field);
            DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location?->id]);
            $ids[$phone] = (int) $contact->id;
        }

        app(DateReachedTriggerSource::class)->sweep(\Carbon\CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100);

        $this->assertEqualsCanonicalizing(
            [$ids['12025551401'], $ids['12025551402']],
            AutomationEnrollment::query()->where('workflow_id', $workflow->id)->pluck('contact_id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertEqualsCanonicalizing(
            [(int) $this->downtown->id, (int) $this->uptown->id],
            AutomationEnrollment::query()->where('workflow_id', $workflow->id)->pluck('business_location_id')->map(fn ($id) => (int) $id)->all(),
            'Each run pins its own contact\'s one Location.',
        );
    }

    public function test_the_simulator_refuses_a_contact_outside_the_selected_locations(): void
    {
        $workflow = $this->publishSelected($this->downtown, $this->uptown);
        $version = $this->version($workflow);

        $inside = app(WorkflowSimulator::class)->simulate($version, $this->contactAt($this->uptown, '14155550121'));
        $outside = app(WorkflowSimulator::class)->simulate($version, $this->contactAt($this->midtown, '14155550122'));
        $nowhere = app(WorkflowSimulator::class)->simulate($version, $this->contactAt(null, '14155550123'));

        $this->assertNull($inside['refused'] ?? null);
        $this->assertSame(WorkflowSimulator::REFUSED_OUTSIDE_LOCATION, $outside['refused']);
        $this->assertSame(WorkflowSimulator::REFUSED_OUTSIDE_LOCATION, $nowhere['refused']);
    }

    public function test_manual_enrollment_refuses_contacts_outside_the_selected_locations_before_queueing_anything(): void
    {
        $workflow = $this->publishSelected($this->downtown, $this->uptown);
        $inside = $this->contactAt($this->downtown, '14155550131');
        $outside = $this->contactAt($this->midtown, '14155550132');
        $this->authenticateAs($this->owner);

        $this->callJson('POST', $this->routeUrl('enrollments.manual', $this->workspace, $this->business, $workflow), ['contact_uids' => [$inside->uid, $outside->uid], 'confirmed' => true])
            ->assertStatus(422);
        Bus::assertNotDispatched(EnrollWorkflowContact::class);

        $this->callJson('POST', $this->routeUrl('enrollments.manual', $this->workspace, $this->business, $workflow), ['contact_uids' => [$inside->uid], 'confirmed' => true])
            ->assertStatus(202);
        Bus::assertDispatched(EnrollWorkflowContact::class);
    }

    // =================================================================
    // Actor authority: reach of EVERY selected Location
    // =================================================================

    private function staffReaching(BusinessLocation ...$locations): Customer
    {
        $staff = $this->staffGrantedOnly($this->workspace, $locations[0]);

        foreach (array_slice($locations, 1) as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign(
                WorkspaceMembership::query()->where('user_id', $staff->user_id)->firstOrFail(),
                $location,
            );
        }

        return $staff;
    }

    private function ownersSelectedDraft(BusinessLocation ...$locations): AutomationWorkflow
    {
        $this->authenticateAs($this->owner);
        $created = $this->callJson('POST', $this->routeUrl('store', $this->workspace, $this->business), ['name' => 'Flow ' . uniqid(), 'trigger_type' => 'manual_enrollment'])->assertCreated();
        $workflow = AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();

        $this->saveSelected($workflow, ...$locations)->assertOk();

        return $workflow;
    }

    private function saveSelected(AutomationWorkflow $workflow, BusinessLocation ...$locations): \Illuminate\Testing\TestResponse
    {
        $draft = $this->callJson('GET', $this->routeUrl('draft.show', $this->workspace, $this->business, $workflow))->assertOk()->json();
        $definition = $draft['definition'];
        $definition['root']['config'] = array_merge($definition['root']['config'], $this->selected(...$locations));
        $definition['root']['next'] = [$this->endStep()];

        return $this->callJson('PUT', $this->routeUrl('draft.autosave', $this->workspace, $this->business, $workflow), [
            'definition' => $definition,
            'definition_revision' => $draft['revision'],
        ]);
    }

    public function test_staff_cannot_save_or_publish_a_list_naming_a_location_they_cannot_reach(): void
    {
        $workflow = $this->ownersSelectedDraft($this->downtown, $this->uptown);
        $partial = $this->staffReaching($this->downtown);

        // They cannot even open it: it names Locations they do not reach.
        $this->authenticateAs($partial);
        $this->callJson('POST', $this->routeUrl('publish', $this->workspace, $this->business, $workflow))->assertStatus(404);

        // Their own draft cannot be saved with a forged list.
        $mine = $this->callJson('POST', $this->routeUrl('store', $this->workspace, $this->business), ['name' => 'Mine', 'trigger_type' => 'manual_enrollment'])->assertCreated();
        $theirs = AutomationWorkflow::query()->where('uid', $mine->json('workflow.uid'))->firstOrFail();
        $this->saveSelected($theirs, $this->downtown, $this->uptown)->assertStatus(422);
        $this->assertNull($theirs->fresh()->draftVersion()?->definition['root']['config']['business_location_ids'] ?? null);
    }

    public function test_staff_who_reach_every_selected_location_can_save_publish_and_operate(): void
    {
        $both = $this->staffReaching($this->downtown, $this->uptown);

        $this->authenticateAs($both);
        $created = $this->callJson('POST', $this->routeUrl('store', $this->workspace, $this->business), ['name' => 'Both', 'trigger_type' => 'manual_enrollment'])->assertCreated();
        $workflow = AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();

        $this->saveSelected($workflow, $this->downtown, $this->uptown)->assertOk();
        $this->callJson('POST', $this->routeUrl('publish', $this->workspace, $this->business, $workflow))->assertOk()->assertJsonPath('status', 'published');
        $this->callJson('POST', $this->routeUrl('pause', $this->workspace, $this->business, $workflow))->assertOk();
    }

    public function test_a_published_selected_workflow_is_hidden_from_and_closed_to_staff_who_miss_any_of_its_locations(): void
    {
        $workflow = $this->publishSelected($this->downtown, $this->uptown);
        $one = $this->staffReaching($this->downtown);
        $both = $this->staffReaching($this->downtown, $this->uptown);
        $other = $this->staffReaching($this->midtown);

        $listing = function (Customer $actor) use ($workflow): bool {
            $this->authenticateAs($actor);

            return collect($this->callJson('GET', $this->routeUrl('index', $this->workspace, $this->business))->assertOk()->json('workflows'))
                ->contains(fn (array $row): bool => $row['uid'] === $workflow->uid);
        };

        $this->assertTrue($listing($this->owner));
        $this->assertTrue($listing($both));
        $this->assertFalse($listing($one), 'Reaching only one of the two Locations is not enough.');
        $this->assertFalse($listing($other));

        $this->authenticateAs($one);
        foreach (['pause', 'resume', 'archive'] as $operation) {
            $this->callJson('POST', $this->routeUrl($operation, $this->workspace, $this->business, $workflow))->assertStatus(404);
        }
        $this->callJson('GET', $this->routeUrl('show', $this->workspace, $this->business, $workflow))->assertStatus(404);

        $this->authenticateAs($both);
        $this->callJson('POST', $this->routeUrl('pause', $this->workspace, $this->business, $workflow))->assertOk();
    }

    public function test_the_list_names_the_selected_locations(): void
    {
        $this->publishSelected($this->downtown, $this->uptown);
        $this->authenticateAs($this->owner);

        $row = $this->callJson('GET', $this->routeUrl('index', $this->workspace, $this->business))->assertOk()->json('workflows.0');

        $this->assertSame('Downtown, Uptown', $row['scope_location_name']);
    }

    public function test_a_bound_scope_that_names_nothing_admits_nothing_and_is_never_business_wide(): void
    {
        $workflow = $this->publishSelected($this->downtown, $this->uptown);
        $version = $this->version($workflow);
        DB::table('automation_workflow_version_locations')->where('version_id', $version->id)->delete();

        $this->assertSame([], $version->fresh()->scope()->ids());
        $this->assertTrue($version->fresh()->scope()->isBound());
        $this->assertFalse($version->fresh()->scope()->allows((int) $this->downtown->id));
        $this->assertNull($this->enroll($workflow, $this->downtown, '14155550141'));

        // An unknown stored mode reads the same way: bound, admits nothing.
        DB::table('automation_workflow_versions')->where('id', $version->id)->update(['scope_mode' => 'mystery']);
        $this->assertTrue($version->fresh()->scope()->isBound());
        $this->assertNull($this->enroll($workflow, $this->downtown, '14155550142'));
    }
}
