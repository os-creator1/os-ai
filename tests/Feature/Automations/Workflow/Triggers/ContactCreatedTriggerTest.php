<?php

namespace Tests\Feature\Automations\Workflow\Triggers;

use App\Enums\Automation\Workflow\ContactCreationSource;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Jobs\AutomationJob;
use App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-C — "a contact is created", and its opt-in source filter.
 *
 * The filter decides whether a real customer is messaged, so the source has to
 * be a declaration by the creating path and never a guess. These tests pin both
 * halves: that the v2 trigger is additive to B4, and that only a genuine opt-in
 * satisfies an opt-in filter.
 */
class ContactCreatedTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;

    private function trigger(): ContactCreatedTriggerSource
    {
        return app(ContactCreatedTriggerSource::class);
    }

    /**
     * Publish a contact_created workflow, optionally filtered to one source.
     */
    private function publishContactCreatedWorkflow(
        \App\Models\Business $business,
        ?ContactCreationSource $filter = null,
        string $name = 'Welcome',
    ): \App\Models\AutomationWorkflow {
        $drafts = app(\App\Library\Automation\Workflow\WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, $name, WorkflowTriggerType::ContactCreated);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactCreated);

        if ($filter !== null) {
            $definition['root']['config']['source'] = $filter->value;
        }

        $definition['root']['next'] = [$this->endStep()];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        app(\App\Library\Automation\Workflow\WorkflowPublisher::class)->publish($workflow->fresh());

        return $workflow->fresh();
    }

    private function enrollmentsFor(int $workflowId): int
    {
        return AutomationEnrollment::query()->where('workflow_id', $workflowId)->count();
    }

    /**
     * Build the job the way a queue worker builds one — no constructor, payload
     * written straight onto the properties — so a payload the named
     * constructors could never produce can still be handed to handle().
     *
     * @param array{contactId: int, workflowId: ?int, creationSource: ?string, requestUid: ?string} $payload
     */
    private function jobWithRawPayload(array $payload): EnrollWorkflowContact
    {
        $class = new \ReflectionClass(EnrollWorkflowContact::class);
        $job = $class->newInstanceWithoutConstructor();

        foreach ($payload as $property => $value) {
            $class->getProperty($property)->setValue($job, $value);
        }

        return $job;
    }

    // -----------------------------------------------------------------
    // 1-2 — one enrollment, and B4 untouched
    // -----------------------------------------------------------------

    public function test_an_ordinary_created_contact_enrolls_exactly_once(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        [$customer, $business] = $this->entitledTenant();
        $workflow = $this->publishContactCreatedWorkflow($business);
        $group = $this->contactGroup($business);
        $contact = $this->contact($business, $group, '12025550101');

        $this->assertSame(1, $this->trigger()->handleContactCreated($contact, ContactCreationSource::Manual));
        $this->assertSame(1, $this->enrollmentsFor((int) $workflow->getKey()));

        // The journey is started, not left sitting.
        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);

        // Replaying the same creation (a redelivered job) enrolls nobody else:
        // the key is the contact id, so the claim is already held.
        $this->assertSame(0, $this->trigger()->handleContactCreated($contact->fresh(), ContactCreationSource::Manual));
        $this->assertSame(1, $this->enrollmentsFor((int) $workflow->getKey()));
    }

    public function test_the_v2_dispatch_is_additive_and_b4_still_fires(): void
    {
        Queue::fake();
        [$customer, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business);

        app(EloquentContactsRepository::class)->storeContact($group, [
            'phone' => '12025550102',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'FIRST_NAME' => 'Ada',
            'LAST_NAME' => 'Lovelace',
        ], ContactCreationSource::Manual);

        // B4's own dispatch is untouched — this slice adds a listener, it does
        // not replace one (§15.1 coexistence).
        Queue::assertPushed(AutomationJob::class, 1);
        Queue::assertPushed(EnrollWorkflowContact::class, 1);
    }

    public function test_both_seams_dispatch_the_v2_trigger_with_the_declared_source(): void
    {
        Queue::fake();
        [$customer, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business);
        $this->phoneField($group);

        [$validator, $subscriber] = app(EloquentContactsRepository::class)->createContactFromRequest($group, [
            'PHONE' => '12025550103',
        ], ContactCreationSource::OptInForm);

        $this->assertNotNull($subscriber, 'The opt-in seam must create the contact: ' . ($validator?->errors()->first() ?? ''));
        Queue::assertPushed(AutomationJob::class, 1);
        Queue::assertPushed(EnrollWorkflowContact::class, 1);
    }

    public function test_a_contact_matched_rather_than_created_dispatches_nothing(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business);
        $this->phoneField($group);

        app(EloquentContactsRepository::class)->createContactFromRequest($group, [
            'PHONE' => '12025550104',
        ], ContactCreationSource::OptInForm);

        // Second submission of the same phone: firstOrNew() matches, so nothing
        // was created and neither engine may fire.
        Queue::fake();
        app(EloquentContactsRepository::class)->createContactFromRequest($group, [
            'PHONE' => '12025550104',
        ], ContactCreationSource::OptInForm);

        Queue::assertNotPushed(AutomationJob::class);
        Queue::assertNotPushed(EnrollWorkflowContact::class);
    }

    // -----------------------------------------------------------------
    // 3-6 — the source filter, per real creation path
    // -----------------------------------------------------------------

    public function test_a_true_opt_in_matches_an_opt_in_filtered_workflow(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        [$customer, $business] = $this->entitledTenant();
        $workflow = $this->publishContactCreatedWorkflow($business, ContactCreationSource::OptInForm);
        $group = $this->contactGroup($business);
        $contact = $this->contact($business, $group, '12025550105');

        $this->assertSame(1, $this->trigger()->handleContactCreated($contact, ContactCreationSource::OptInForm));
        $this->assertSame(1, $this->enrollmentsFor((int) $workflow->getKey()));
    }

    public function test_the_in_app_add_contact_form_does_not_match_the_opt_in_filter(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $workflow = $this->publishContactCreatedWorkflow($business, ContactCreationSource::OptInForm);
        $group = $this->contactGroup($business);
        $contact = $this->contact($business, $group, '12025550106');

        $this->assertSame(0, $this->trigger()->handleContactCreated($contact, ContactCreationSource::Manual));
        $this->assertSame(0, $this->enrollmentsFor((int) $workflow->getKey()));
    }

    public function test_the_api_path_does_not_match_the_opt_in_filter(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $workflow = $this->publishContactCreatedWorkflow($business, ContactCreationSource::OptInForm);
        $group = $this->contactGroup($business);
        $contact = $this->contact($business, $group, '12025550107');

        $this->assertSame(0, $this->trigger()->handleContactCreated($contact, ContactCreationSource::Api));
        $this->assertSame(0, $this->enrollmentsFor((int) $workflow->getKey()));
    }

    /**
     * Bulk import writes contacts with raw SQL through ContactGroups::import()
     * and passes through NEITHER creation seam, so it fires no contact_created
     * trigger at all — B4's rule (§6.B), preserved. This is the mechanical
     * truth, which is why ContactCreationSource has no `import` case.
     */
    public function test_bulk_import_fires_no_contact_created_trigger_at_all(): void
    {
        Queue::fake();
        [$customer, $business] = $this->entitledTenant();
        $this->publishContactCreatedWorkflow($business);
        $group = $this->contactGroup($business);

        // The import path's actual write: no seam, no dispatch.
        DB::table('contacts')->insert([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => '12025550108',
            'status' => Contacts::STATUS_SUBSCRIBE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::assertNotPushed(AutomationJob::class);
        Queue::assertNotPushed(EnrollWorkflowContact::class);
        $this->assertSame(0, AutomationEnrollment::query()->count());

        // And the claim that import really does write this way: its own code
        // neither calls a creation seam nor dispatches either engine. Without
        // this, the insert above would only prove what a raw insert does.
        $import = file_get_contents(base_path('app/Models/ContactGroups.php'));

        foreach ([
            'storeContact(',
            'createContactFromRequest(',
            'AutomationJob::',
            'EnrollWorkflowContact',
        ] as $absent) {
            $this->assertStringNotContainsString(
                $absent,
                $import,
                'Bulk import bypasses both creation seams, so it triggers nothing — in v2 as in B4.',
            );
        }
    }

    public function test_an_unfiltered_workflow_fires_for_every_source(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        [$customer, $business] = $this->entitledTenant();
        $workflow = $this->publishContactCreatedWorkflow($business);
        $group = $this->contactGroup($business);

        foreach ([ContactCreationSource::Manual, ContactCreationSource::OptInForm, ContactCreationSource::Api, ContactCreationSource::Other] as $index => $source) {
            $contact = $this->contact($business, $group, '1202555020' . $index);
            $this->assertSame(1, $this->trigger()->handleContactCreated($contact, $source), $source->value . ' must match an unfiltered workflow.');
        }

        $this->assertSame(4, $this->enrollmentsFor((int) $workflow->getKey()));
    }

    // -----------------------------------------------------------------
    // 7 — the source cannot be forged
    // -----------------------------------------------------------------

    public function test_request_input_cannot_forge_the_creation_source(): void
    {
        Queue::fake();
        [$customer, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business);
        $this->phoneField($group);

        // A caller declaring Manual, with request input shouting "opt in".
        app(EloquentContactsRepository::class)->createContactFromRequest($group, [
            'PHONE' => '12025550109',
            'source' => ContactCreationSource::OptInForm->value,
            'creation_source' => ContactCreationSource::OptInForm->value,
            'opt_in' => true,
        ], ContactCreationSource::Manual);

        Queue::assertPushed(
            EnrollWorkflowContact::class,
            function (EnrollWorkflowContact $job): bool {
                // The job carries the DECLARED source, so a filtered workflow
                // later sees Manual — the request never reaches the decision.
                $reflection = new \ReflectionProperty($job, 'creationSource');

                return $reflection->getValue($job) === ContactCreationSource::Manual->value;
            },
        );
    }

    public function test_a_source_outside_the_vocabulary_enrolls_nobody(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business);
        $contact = $this->contact($business, $group, '12025550110');
        $this->publishContactCreatedWorkflow($business);

        // The job re-reads the source and refuses anything not in the enum,
        // rather than treating it as "any source".
        //
        // The named constructors cannot produce this state — that is the point
        // of a closed vocabulary — so the job is built the way the queue builds
        // one, without the constructor, and its payload is set directly. This is
        // a corrupted or hand-edited queue payload, which is exactly the input
        // `tryFrom` exists to refuse.
        $job = $this->jobWithRawPayload([
            'contactId' => (int) $contact->getKey(),
            'workflowId' => null,
            'creationSource' => 'totally-made-up',
            'requestUid' => null,
        ]);

        $job->handle(app(ContactCreatedTriggerSource::class), app(\App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource::class));

        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_an_unreadable_trigger_config_enrolls_nobody(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $workflow = $this->publishContactCreatedWorkflow($business);
        $group = $this->contactGroup($business);
        $contact = $this->contact($business, $group, '12025550111');

        // A trigger node whose config cannot be read is a workflow that does
        // not fire — never one that fires for everyone.
        //
        // `config` is a JSON column, so the corruption has to be valid JSON that
        // is not an object: a scalar. That is the realistic shape of the
        // failure — a readable column holding something this code cannot treat
        // as a configuration.
        DB::table('automation_workflow_nodes')
            ->where('version_id', $workflow->published_version_id)
            ->where('node_type', 'trigger')
            ->update(['config' => '"not-an-object"']);

        $this->assertSame(0, $this->trigger()->handleContactCreated($contact, ContactCreationSource::OptInForm));
        $this->assertSame(0, $this->enrollmentsFor((int) $workflow->getKey()));
    }

    // -----------------------------------------------------------------
    // 8 — tenancy
    // -----------------------------------------------------------------

    public function test_another_businesss_workflow_cannot_enroll_this_contact(): void
    {
        [$customerOne, $businessOne] = $this->entitledTenant();
        [$customerTwo, $businessTwo] = $this->entitledTenant();

        $rival = $this->publishContactCreatedWorkflow($businessTwo, null, 'Rival welcome');
        $group = $this->contactGroup($businessOne);
        $contact = $this->contact($businessOne, $group, '12025550112');

        $this->assertSame(0, $this->trigger()->handleContactCreated($contact, ContactCreationSource::Manual));
        $this->assertSame(0, $this->enrollmentsFor((int) $rival->getKey()));
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_a_contact_without_a_business_enrolls_nothing(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $workflow = $this->publishContactCreatedWorkflow($business);
        $group = $this->contactGroup($business);
        $contact = $this->contact($business, $group, '12025550113');

        DB::table('contacts')->where('id', $contact->getKey())->update(['business_id' => null]);

        $this->assertSame(0, $this->trigger()->handleContactCreated($contact->fresh(), ContactCreationSource::Manual));
        $this->assertSame(0, $this->enrollmentsFor((int) $workflow->getKey()));
    }

    public function test_a_paused_or_archived_workflow_enrolls_nobody(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $workflow = $this->publishContactCreatedWorkflow($business);
        $group = $this->contactGroup($business);

        DB::table('automation_workflows')->where('id', $workflow->getKey())->update(['status' => 'paused']);
        $paused = $this->contact($business, $group, '12025550114');
        $this->assertSame(0, $this->trigger()->handleContactCreated($paused, ContactCreationSource::Manual));

        DB::table('automation_workflows')->where('id', $workflow->getKey())->update(['status' => 'archived']);
        $archived = $this->contact($business, $group, '12025550115');
        $this->assertSame(0, $this->trigger()->handleContactCreated($archived, ContactCreationSource::Manual));

        $this->assertSame(0, $this->enrollmentsFor((int) $workflow->getKey()));
    }

    /** The PHONE field the request-shaped seam validates against. */
    private function phoneField(\App\Models\ContactGroups $group): \App\Models\ContactGroupFields
    {
        return \App\Models\ContactGroupFields::create([
            'contact_group_id' => $group->id,
            'label' => 'Phone',
            'type' => 'text',
            'tag' => 'PHONE',
            'visible' => true,
            'required' => true,
            'is_phone' => true,
        ]);
    }
}
