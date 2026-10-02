<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Runtime\WorkflowRecoveryService;
use App\Library\Automation\Workflow\Runtime\WorkflowWakeService;
use App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Library\Crm\TagManager;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessEmailMessage;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Automations Location run-scope — what a journey does once it is enrolled.
 *
 * The scope is a property of the pinned version and the pin is a property of the
 * enrollment row; nothing here re-derives either from a Contact, and an action
 * that cannot honour the pin fails closed.
 */
class LocationRuntimeAndActionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;
    use CreatesBusinessEmailFixtures;
    use BuildsFoundationWorkflows;

    private Business $business;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        $this->bindFakeEmailProviders();

        [, $business] = $this->crmTenant();
        $this->business = $this->activate($business);
        $this->downtown = $this->makeLocation($this->business, 'Downtown');
        $this->uptown = $this->makeLocation($this->business, 'Uptown');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function moveContact(Contacts $contact, ?BusinessLocation $to): void
    {
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $to?->id]);
    }

    private function contactAt(?BusinessLocation $location): Contacts
    {
        $contact = $this->crmContact($this->business);
        $this->moveContact($contact, $location);

        return $contact->fresh();
    }

    private function manual(array $steps, ?BusinessLocation $bound = null, ?Business $business = null): AutomationWorkflow
    {
        return $this->triggerWorkflow(
            $business ?? $this->business,
            WorkflowTriggerType::ManualEnrollment,
            $bound === null ? [] : ['business_location_id' => $bound->id],
            $steps,
        );
    }

    private function enrollByHand(AutomationWorkflow $workflow, Contacts $contact, string $request = 'req-1'): ?AutomationEnrollment
    {
        return app(ManualEnrollmentTriggerSource::class)->enrollByHand($workflow, $contact->fresh(), $request);
    }

    private function advance(AutomationEnrollment $enrollment): AutomationEnrollment
    {
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        return $enrollment->fresh();
    }

    private function stepRun(AutomationEnrollment $enrollment, string $type): AutomationStepRun
    {
        return AutomationStepRun::query()->where('enrollment_id', $enrollment->id)->where('node_type', $type)->sole();
    }

    private function wake(AutomationEnrollment $enrollment): void
    {
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update(['resume_at' => Carbon::now()->subSecond()]);
        app(WorkflowWakeService::class)->wakeDue();
    }

    // =================================================================
    // 14. Enrollment persists the Location
    // =================================================================

    public function test_the_enrollment_pins_the_facts_location_and_a_bound_workflow_pins_its_own(): void
    {
        $bound = $this->manual([$this->endStep()], $this->downtown);
        $wide = $this->manual([$this->endStep()]);

        $atDowntown = $this->contactAt($this->downtown);
        $nowhere = $this->contactAt(null);

        $this->assertSame((int) $this->downtown->id, (int) $this->enrollByHand($bound, $atDowntown)->business_location_id);
        $this->assertSame((int) $this->downtown->id, (int) $this->enrollByHand($wide, $atDowntown, 'req-2')->business_location_id, 'A Business-wide workflow still pins the fact\'s Location.');
        $this->assertNull($this->enrollByHand($wide, $nowhere, 'req-3')->business_location_id, 'A fact with no Location stays unpinned: none is invented.');
    }

    // =================================================================
    // 15-17. The pin survives waits, recovery and a Contact moving
    // =================================================================

    public function test_a_wait_and_its_resume_keep_the_pinned_location_when_the_contact_moves(): void
    {
        $workflow = $this->manual([$this->waitMinutes(30), $this->endStep()], $this->downtown);
        $contact = $this->contactAt($this->downtown);

        $enrollment = $this->advance($this->enrollByHand($workflow, $contact));
        $this->assertSame(EnrollmentStatus::Waiting, $enrollment->status);
        $this->assertSame((int) $this->downtown->id, (int) $enrollment->business_location_id, 'Pinned across the wait.');

        $this->moveContact($contact, $this->uptown);
        $this->wake($enrollment);
        $enrollment = $this->advance($enrollment);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertSame((int) $this->downtown->id, (int) $enrollment->business_location_id, 'Pinned across the resume — the Contact moved, the journey did not.');
    }

    public function test_recovery_keeps_the_pinned_location(): void
    {
        $workflow = $this->manual([$this->waitMinutes(30), $this->endStep()]);
        $contact = $this->contactAt($this->downtown);
        $enrollment = $this->enrollByHand($workflow, $contact);

        $stale = Carbon::now()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES + 5);
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update(['last_advanced_at' => $stale, 'enrolled_at' => $stale]);
        $this->moveContact($contact, $this->uptown);

        $counts = app(WorkflowRecoveryService::class)->recoverStalled();

        $this->assertSame(1, $counts['redispatched']);
        $this->assertSame((int) $this->downtown->id, (int) $enrollment->fresh()->business_location_id);
    }

    public function test_a_contact_moving_after_enrollment_does_not_change_the_run_scope(): void
    {
        $workflow = $this->manual([$this->waitMinutes(30), $this->endStep()]);
        $contact = $this->contactAt($this->downtown);
        $enrollment = $this->advance($this->enrollByHand($workflow, $contact));

        $this->moveContact($contact, $this->uptown);
        $this->wake($enrollment);
        $enrollment = $this->advance($enrollment);

        $this->assertSame((int) $this->downtown->id, (int) $enrollment->business_location_id);
        $this->assertSame((int) $this->uptown->id, (int) Contacts::query()->find($contact->id)->location_id, 'The Contact really did move.');
    }

    // =================================================================
    // 19-20. Tag actions
    // =================================================================

    public function test_a_bound_workflow_does_not_tag_a_contact_who_has_left_its_location(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'VIP');
        $workflow = $this->manual([$this->waitMinutes(30), $this->addTagStep((int) $tag->id), $this->endStep()], $this->downtown);
        $contact = $this->contactAt($this->downtown);

        $enrollment = $this->advance($this->enrollByHand($workflow, $contact));
        $this->moveContact($contact, $this->uptown);
        $this->wake($enrollment);
        $enrollment = $this->advance($enrollment);

        $this->assertSame(0, DB::table('contact_tags')->where('contact_id', $contact->id)->count(), 'No tag is written under a scope the Contact has left.');
        $step = $this->stepRun($enrollment, 'add_tag');
        $this->assertSame(StepRunStatus::Skipped, $step->status);
        $this->assertSame('contact_outside_workflow_location', $step->safe_error_summary);
        $this->assertSame((int) $this->downtown->id, (int) $enrollment->business_location_id);
    }

    public function test_a_bound_workflow_tags_a_contact_who_is_still_at_its_location(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'VIP');
        $workflow = $this->manual([$this->addTagStep((int) $tag->id), $this->endStep()], $this->downtown);
        $contact = $this->contactAt($this->downtown);

        $enrollment = $this->advance($this->enrollByHand($workflow, $contact));

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertSame(1, DB::table('contact_tags')->where('contact_id', $contact->id)->where('tag_id', $tag->id)->count());
    }

    public function test_a_business_wide_tag_action_still_works_wherever_the_contact_is(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'VIP');
        $workflow = $this->manual([$this->waitMinutes(30), $this->addTagStep((int) $tag->id), $this->endStep()]);
        $contact = $this->contactAt($this->downtown);

        $enrollment = $this->advance($this->enrollByHand($workflow, $contact));
        $this->moveContact($contact, $this->uptown);
        $this->wake($enrollment);
        $enrollment = $this->advance($enrollment);

        $this->assertSame(1, DB::table('contact_tags')->where('contact_id', $contact->id)->count(), 'Tags are Business-wide: a Business-wide workflow is not blocked by Location.');
        $this->assertSame(StepRunStatus::Succeeded, $this->stepRun($enrollment, 'add_tag')->status);
        $this->assertSame((int) $this->downtown->id, (int) $enrollment->business_location_id);
    }

    public function test_the_has_tag_condition_stays_business_wide_membership_logic(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'VIP');
        $contact = $this->contactAt($this->downtown);
        app(TagManager::class)->attachTag($this->business, $contact, $tag);

        $workflow = $this->manual([[
            'key' => (string) Str::uuid(), 'type' => 'if_else',
            'config' => ['match' => 'all', 'conditions' => [['subject' => 'contact.has_tag:' . $tag->id, 'operator' => 'is_true']]],
            'yes' => [$this->endStep()], 'no' => [],
        ]], $this->downtown);

        $enrollment = $this->advance($this->enrollByHand($workflow, $contact));
        $this->moveContact($contact, $this->uptown);

        $this->assertSame('yes', $this->stepRun($enrollment, 'if_else')->branch_taken->value);
    }

    // =================================================================
    // 18. Send email cannot drift
    // =================================================================

    private function readyForEmail(): void
    {
        $this->activeAccount($this->business);
    }

    public function test_send_email_goes_out_from_the_pinned_location_even_after_the_contact_moves(): void
    {
        $this->readyForEmail();
        $workflow = $this->manual([$this->emailStep('Hello', 'There'), $this->endStep()]);
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], (int) $this->downtown->id, 'Pat');

        $enrollment = $this->enrollByHand($workflow, $contact);
        $this->moveContact($contact, $this->uptown);
        $enrollment = $this->advance($enrollment);

        $message = BusinessEmailMessage::query()->sole();
        $this->assertSame((int) $this->downtown->id, (int) $message->location_id, 'The email is attributed to the run\'s Location, not the Contact\'s current one.');
        $this->assertSame(StepRunStatus::Succeeded, $this->stepRun($enrollment, 'send_email')->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_pinned_location_is_used_even_when_the_contact_has_none(): void
    {
        $this->readyForEmail();
        $workflow = $this->manual([$this->emailStep(), $this->endStep()]);
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], null);

        // A fact that carried Downtown, for a Contact the CRM never placed.
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'fact-1', 0, (int) $this->downtown->id);
        $this->advance($enrollment);

        $this->assertSame((int) $this->downtown->id, (int) BusinessEmailMessage::query()->sole()->location_id);
    }

    public function test_an_unpinned_run_keeps_the_foundations_own_location_rules(): void
    {
        $this->readyForEmail();
        $workflow = $this->manual([$this->emailStep(), $this->endStep()]);
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], null);

        // No Location on the fact, none on the Contact, two active Locations: the
        // foundation itself refuses to guess. The automation adds no guess of its own.
        $enrollment = $this->advance($this->enrollByHand($workflow, $contact));

        $this->assertSame('email_refused: location_required', $this->stepRun($enrollment, 'send_email')->safe_error_summary);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_pinned_location_that_was_archived_fails_closed_and_never_falls_back(): void
    {
        $this->readyForEmail();
        $workflow = $this->manual([$this->emailStep(), $this->endStep()]);
        $enrollment = $this->enrollByHand($workflow, $this->contactWithEmails($this->business, ['sam@example.com'], (int) $this->downtown->id));
        DB::table('business_locations')->where('id', $this->downtown->id)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);
        $enrollment = $this->advance($enrollment);

        $this->assertSame(StepRunStatus::Failed, $this->stepRun($enrollment, 'send_email')->status);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'), 'It did not quietly send from another Location.');
    }

    // =================================================================
    // 21. Forged node configuration
    // =================================================================

    public function test_a_forged_location_in_node_config_is_ignored(): void
    {
        $this->readyForEmail();
        $email = $this->emailStep();
        $email['config'] += ['business_location_id' => (int) $this->uptown->id, 'location_id' => (int) $this->uptown->id];
        $workflow = $this->manual([$email, $this->endStep()], $this->downtown);
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], (int) $this->downtown->id);

        $this->advance($this->enrollByHand($workflow, $contact));

        $this->assertSame((int) $this->downtown->id, (int) BusinessEmailMessage::query()->sole()->location_id);
    }

    public function test_a_forged_location_in_a_tag_node_cannot_widen_a_bound_workflow(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'VIP');
        $step = $this->addTagStep((int) $tag->id);
        $step['config'] += ['business_location_id' => (int) $this->uptown->id];
        $workflow = $this->manual([$this->waitMinutes(30), $step, $this->endStep()], $this->downtown);
        $contact = $this->contactAt($this->downtown);

        $enrollment = $this->advance($this->enrollByHand($workflow, $contact));
        $this->moveContact($contact, $this->uptown);
        $this->wake($enrollment);
        $this->advance($enrollment);

        $this->assertSame(0, DB::table('contact_tags')->where('contact_id', $contact->id)->count(), 'The node\'s own Location key does not move the workflow\'s scope.');
    }

    // =================================================================
    // 22-23. Tenancy and authority
    // =================================================================

    public function test_another_businesss_location_can_never_scope_or_pin_this_businesss_run(): void
    {
        [, $other] = $this->crmTenant('Business B', 'Workspace B');
        $other = $this->activate($other);
        $theirs = $this->makeLocation($other, 'Their Location');
        $wide = $this->manual([$this->endStep()]);
        $contact = $this->contactAt($this->downtown);

        // A forged fact Location on a Business-wide workflow.
        $this->assertNull(app(EnrollmentService::class)->enroll($wide, $contact, 'forged', 0, (int) $theirs->id));

        // A version whose pinned scope was tampered to a foreign Location, and a fact claiming it.
        $bound = $this->manual([$this->endStep()], $this->downtown);
        DB::table('automation_workflow_versions')->where('id', $bound->published_version_id)->update(['business_location_id' => $theirs->id]);

        $this->assertNull(app(EnrollmentService::class)->enroll($bound->fresh(), $contact, 'forged-2', 0, (int) $theirs->id), 'Even a matching foreign Location is refused: it is not this Business\'s.');
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_runtime_authority_does_not_depend_on_who_is_signed_in_or_viewing_as(): void
    {
        // The Automations library has no actor: no Auth, no session, no View As.
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app/Library/Automation'), \FilesystemIterator::SKIP_DOTS));

        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents($file->getPathname()));

            foreach (['Auth::', 'auth()', 'ViewAs', 'Session::', 'session(', 'LocationAccessGuard', 'request()'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, $file->getFilename() . ' must not consult an actor.');
            }
        }

        // And a journey runs identically while some other user is signed in.
        $workflow = $this->manual([$this->endStep()], $this->downtown);
        $contact = $this->contactAt($this->downtown);
        $outsider = $this->createCustomer();
        $this->actingAs($outsider->user);

        $enrollment = $this->advance($this->enrollByHand($workflow, $contact));

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertSame((int) $this->downtown->id, (int) $enrollment->business_location_id);
    }
}
