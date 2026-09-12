<?php

namespace Tests\Feature\Automations\Workflow\Triggers;

use App\Enums\Automation\Workflow\ContactCreationSource;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource;
use App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-C — "I enroll someone by hand", and the queued job behind it.
 *
 * The job is background work carrying ids, so these tests are mostly about what
 * it refuses: a contact from another Business, a workflow from another Business,
 * a redelivery, a republish mid-queue. Nothing here touches HTTP — V2-E owns the
 * request, its permission and its validation.
 */
class ManualEnrollmentTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;

    private function manual(): ManualEnrollmentTriggerSource
    {
        return app(ManualEnrollmentTriggerSource::class);
    }

    private function runJob(EnrollWorkflowContact $job): void
    {
        $job->handle(app(ContactCreatedTriggerSource::class), app(ManualEnrollmentTriggerSource::class));
    }

    // -----------------------------------------------------------------
    // 17 — the happy path, through the canonical service
    // -----------------------------------------------------------------

    public function test_a_valid_manual_trigger_enrolls_through_the_enrollment_service(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        [$customer, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contactFor($business);

        $enrollment = $this->manual()->enrollByHand($workflow, $contact, 'req-' . Str::uuid());

        $this->assertNotNull($enrollment);
        $this->assertSame((int) $business->id, (int) $enrollment->business_id);
        $this->assertSame((int) $workflow->published_version_id, (int) $enrollment->version_id, 'The published version is pinned.');
        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);
    }

    public function test_the_queued_job_enrolls_the_same_way(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        [$customer, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contactFor($business);

        $this->runJob(EnrollWorkflowContact::forManualEnrollment(
            (int) $workflow->getKey(),
            (int) $contact->getKey(),
            'req-single',
        ));

        $this->assertSame(1, AutomationEnrollment::query()->count());
    }

    // -----------------------------------------------------------------
    // 18-19 — foreign contact, foreign workflow
    // -----------------------------------------------------------------

    public function test_a_contact_from_another_business_is_refused(): void
    {
        [$customerOne, $businessOne] = $this->entitledTenant();
        [$customerTwo, $businessTwo] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($businessOne, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $foreign = $this->contactFor($businessTwo);

        $this->assertNull($this->manual()->enrollByHand($workflow, $foreign, 'req-foreign-contact'));
        $this->assertSame(0, AutomationEnrollment::query()->count());

        // And through the job, where a stale id pair is the real risk.
        $this->runJob(EnrollWorkflowContact::forManualEnrollment(
            (int) $workflow->getKey(),
            (int) $foreign->getKey(),
            'req-foreign-contact-job',
        ));
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_a_workflow_from_another_business_is_refused(): void
    {
        [$customerOne, $businessOne] = $this->entitledTenant();
        [$customerTwo, $businessTwo] = $this->entitledTenant();
        [$rival] = $this->publishWorkflow($businessTwo, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $mine = $this->contactFor($businessOne);

        $this->assertNull($this->manual()->enrollByHand($rival, $mine, 'req-foreign-workflow'));

        $this->runJob(EnrollWorkflowContact::forManualEnrollment(
            (int) $rival->getKey(),
            (int) $mine->getKey(),
            'req-foreign-workflow-job',
        ));

        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_a_contact_without_a_business_is_refused(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contactFor($business);
        DB::table('contacts')->where('id', $contact->getKey())->update(['business_id' => null]);

        $this->assertNull($this->manual()->enrollByHand($workflow, $contact->fresh(), 'req-null-business'));
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    // -----------------------------------------------------------------
    // 20-21 — retries, deletions, republishing
    // -----------------------------------------------------------------

    public function test_a_duplicate_queued_job_is_idempotent(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        [$customer, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contactFor($business);

        $job = EnrollWorkflowContact::forManualEnrollment(
            (int) $workflow->getKey(),
            (int) $contact->getKey(),
            'req-delivered-twice',
        );

        // The same request, delivered twice by the queue.
        $this->runJob($job);
        $this->runJob($job);

        $this->assertSame(1, AutomationEnrollment::query()->count(), 'One request is one enrollment, however many deliveries.');
        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);
    }

    /**
     * The manual trigger's DEFAULT policy is `once_ever` (§7.5, owner decision
     * D3): "re-enrolling by hand should be a deliberate choice, not an accident
     * of clicking twice". Under that policy the key ignores the occurrence, so
     * a contact goes through this workflow once and a second request — however
     * deliberate, and even after the first journey finished — is refused.
     */
    public function test_the_default_once_ever_policy_admits_a_contact_once(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contactFor($business);

        $this->assertNotNull($this->manual()->enrollByHand($workflow, $contact, 'req-one'));

        // While the first journey runs: refused by the active-contact guard.
        $this->assertNull($this->manual()->enrollByHand($workflow, $contact, 'req-two'));

        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);

        // And after it finished: still refused, because once_ever's key carries
        // no occurrence. Entering twice is a policy choice the customer makes
        // in the builder, not something a second request can force.
        $this->assertNull($this->manual()->enrollByHand($workflow, $contact->fresh(), 'req-three'));
        $this->assertSame(1, AutomationEnrollment::query()->count());
    }

    /**
     * With the policy deliberately set to `once_per_occurrence` — allowed only
     * with `enrollment_policy_source = user` (§7.5) — each manual request is its
     * own occurrence, so a finished contact may be enrolled again.
     */
    public function test_a_user_chosen_once_per_occurrence_policy_admits_a_second_deliberate_request(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $drafts = app(\App\Library\Automation\Workflow\WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Re-enrollable', WorkflowTriggerType::ManualEnrollment);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition(WorkflowTriggerType::ManualEnrollment);
        $definition['root']['config']['enrollment_policy'] = \App\Enums\Automation\Workflow\EnrollmentPolicy::OncePerOccurrence->value;
        $definition['root']['config']['enrollment_policy_source'] = \App\Enums\Automation\Workflow\EnrollmentPolicySource::User->value;
        $definition['root']['next'] = [$this->endStep()];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        $workflow = $workflow->fresh();
        $contact = $this->contactFor($business);

        $this->assertNotNull($this->manual()->enrollByHand($workflow, $contact, 'req-one'));
        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertNotNull($this->manual()->enrollByHand($workflow, $contact->fresh(), 'req-two'));
        $this->assertSame(2, AutomationEnrollment::query()->count());

        // A redelivery of either request is still refused.
        $this->assertNull($this->manual()->enrollByHand($workflow, $contact->fresh(), 'req-two'));
        $this->assertSame(2, AutomationEnrollment::query()->count());
    }

    public function test_a_deleted_contact_fails_safely(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contactFor($business);
        $contactId = (int) $contact->getKey();

        $job = EnrollWorkflowContact::forManualEnrollment((int) $workflow->getKey(), $contactId, 'req-deleted');
        DB::table('contacts')->where('id', $contactId)->delete();

        $this->runJob($job);

        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_an_archived_workflow_fails_safely(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contactFor($business);

        $job = EnrollWorkflowContact::forManualEnrollment(
            (int) $workflow->getKey(),
            (int) $contact->getKey(),
            'req-archived',
        );

        DB::table('automation_workflows')->where('id', $workflow->getKey())->update(['status' => 'archived']);

        $this->runJob($job);

        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_republishing_does_not_move_an_existing_enrollment(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contactFor($business);

        $enrollment = $this->manual()->enrollByHand($workflow, $contact, 'req-before-republish');
        $this->assertNotNull($enrollment);
        $pinnedVersionId = (int) $enrollment->version_id;

        // A new published version arrives underneath the running journey.
        $drafts = app(\App\Library\Automation\Workflow\WorkflowDraftService::class);
        $draft = $drafts->ensureDraft($workflow->fresh());
        $definition = $draft->definition;
        $definition['root']['next'] = [$this->recordedStep('second publication'), $this->endStep()];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        $newVersion = app(WorkflowPublisher::class)->publish($workflow->fresh());

        $this->assertNotSame($pinnedVersionId, (int) $newVersion->getKey(), 'The republish produced a new version.');
        $this->assertSame(
            $pinnedVersionId,
            (int) $enrollment->fresh()->version_id,
            'A running journey stays on the version it entered.',
        );
    }

    public function test_a_job_queued_before_a_republish_pins_what_is_published_when_it_runs(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contactFor($business);

        $job = EnrollWorkflowContact::forManualEnrollment(
            (int) $workflow->getKey(),
            (int) $contact->getKey(),
            'req-queued-across-republish',
        );

        $drafts = app(\App\Library\Automation\Workflow\WorkflowDraftService::class);
        $draft = $drafts->ensureDraft($workflow->fresh());
        $definition = $draft->definition;
        $definition['root']['next'] = [$this->recordedStep('second publication'), $this->endStep()];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        $newVersion = app(WorkflowPublisher::class)->publish($workflow->fresh());

        $this->runJob($job);

        // The job carried ids, not a snapshot, so it pins the version that is
        // published at execution time — never a stale one.
        $this->assertSame((int) $newVersion->getKey(), (int) AutomationEnrollment::query()->sole()->version_id);
    }

    public function test_the_job_uses_no_actor_state(): void
    {
        // No Auth, session or request reference anywhere in the background path:
        // a worker has no logged-in user, and a job that asked would be asking
        // about whoever started the worker.
        foreach ([
            'app/Jobs/Automation/Workflow/EnrollWorkflowContact.php',
            'app/Library/Automation/Workflow/Triggers/ManualEnrollmentTriggerSource.php',
            'app/Library/Automation/Workflow/Triggers/ContactCreatedTriggerSource.php',
            'app/Library/Automation/Workflow/Triggers/DateReachedTriggerSource.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            foreach (['Auth::', 'auth(', 'session(', 'request(', '$_SESSION', 'Session::'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $path . ' must not read actor state.');
            }
        }
    }
}
