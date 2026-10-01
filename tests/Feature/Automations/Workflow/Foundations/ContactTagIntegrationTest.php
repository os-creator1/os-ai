<?php

namespace Tests\Feature\Automations\Workflow\Foundations;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Crm\ContactTagAdded;
use App\Events\Crm\ContactTagEvent;
use App\Events\Crm\ContactTagRemoved;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Executors\AddTagNodeExecutor;
use App\Library\Automation\Workflow\Executors\RemoveTagNodeExecutor;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Triggers\ContactTagTriggerSource;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Library\Crm\TagManager;
use App\Listeners\Automation\Workflow\EnrollFromContactTagEvent;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflowNode;
use App\Models\Contacts;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Automations x Contact Tags — the trigger, the two actions and the condition.
 *
 * Every enrollment here is produced the way production produces it: TagManager
 * changes a membership and emits its after-commit event, the queued listener
 * (sync in tests) hands it to the trigger source, and EnrollmentService makes the
 * one row. Every tag write an action makes goes through TagManager, so the event
 * it emits is the evidence.
 */
class ContactTagIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;
    use BuildsFoundationWorkflows;

    /** @var list<ContactTagEvent> */
    private array $tagEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Enrollment is the subject of the trigger tests; the journey is advanced
        // by hand wherever a test needs it.
        Bus::fake([AdvanceWorkflowEnrollment::class]);

        Event::listen(ContactTagAdded::class, fn (ContactTagAdded $event) => $this->tagEvents[] = $event);
        Event::listen(ContactTagRemoved::class, fn (ContactTagRemoved $event) => $this->tagEvents[] = $event);
    }

    private function manager(): TagManager
    {
        return app(TagManager::class);
    }

    private function tag(\App\Models\Business $business, string $name): Tag
    {
        return $this->manager()->createTag($business, $name);
    }

    /** A published manual-enrollment workflow with the given steps, ready to be advanced by hand. */
    private function manualJourney(\App\Models\Business $business, array $steps): array
    {
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], $steps);

        return [$workflow, AutomationWorkflowNode::query()->where('version_id', $workflow->published_version_id)->get()];
    }

    private function runManual(\App\Models\AutomationWorkflow $workflow, Contacts $contact, string $occurrence = 'manual-1'): AutomationEnrollment
    {
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $occurrence);
        $this->assertNotNull($enrollment, 'The manual enrollment must be accepted.');
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $enrollment->fresh();
    }

    // =================================================================
    // 1-3. The triggers
    // =================================================================

    public function test_tag_added_enrolls_the_contact_exactly_once_with_the_events_occurrence_key(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $tag = $this->tag($business, 'VIP');
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ContactTagAdded, ['tag_id' => $tag->id]);

        $membership = $this->manager()->attachTag($business, $contact, $tag);

        $this->assertSame(1, $this->enrollmentCount($workflow));
        $enrollment = AutomationEnrollment::query()->sole();
        $this->assertSame(WorkflowTriggerType::ContactTagAdded, $enrollment->trigger_type);
        $this->assertSame((int) $contact->id, (int) $enrollment->contact_id);
        $this->assertSame((int) $business->id, (int) $enrollment->business_id);
        $this->assertSame('contact_tag_added:' . $membership->id, $enrollment->trigger_occurrence_key);
        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);

        // A second attach of the held tag is the Tags domain's own no-op: no event, no enrollment.
        $this->assertNull($this->manager()->attachTag($business, $contact, $tag));
        $this->assertSame(1, $this->enrollmentCount($workflow));
    }

    public function test_the_tag_filter_narrows_and_a_workflow_with_no_filter_hears_every_tag(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $vip = $this->tag($business, 'VIP');
        $lead = $this->tag($business, 'Lead');

        $vipOnly = $this->triggerWorkflow($business, WorkflowTriggerType::ContactTagAdded, ['tag_id' => $vip->id]);
        $anyTag = $this->triggerWorkflow($business, WorkflowTriggerType::ContactTagAdded);

        $this->manager()->attachTag($business, $contact, $lead);

        $this->assertSame(0, $this->enrollmentCount($vipOnly), 'A different tag must not start a tag-specific workflow.');
        $this->assertSame(1, $this->enrollmentCount($anyTag));
    }

    public function test_duplicate_delivery_of_the_same_tag_added_event_does_not_duplicate_the_enrollment(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $tag = $this->tag($business, 'VIP');
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ContactTagAdded, ['tag_id' => $tag->id]);

        $this->manager()->attachTag($business, $contact, $tag);
        $event = $this->tagEvents[0];
        $this->assertInstanceOf(ContactTagAdded::class, $event);

        // Close the journey so ONLY the occurrence key can refuse the replay.
        $this->finishJourneys();

        $listener = app(EnrollFromContactTagEvent::class);
        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, $this->enrollmentCount($workflow), 'The same occurrence key loses the same unique claim.');
    }

    public function test_tag_removed_enrolls_exactly_once_and_a_re_add_then_remove_is_a_new_occurrence(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $tag = $this->tag($business, 'VIP');
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ContactTagRemoved, ['tag_id' => $tag->id]);

        $this->manager()->attachTag($business, $contact, $tag);
        $this->assertSame(0, $this->enrollmentCount($workflow), 'Adding a tag must not start a tag-REMOVED workflow.');

        $this->assertTrue($this->manager()->detachTag($business, $contact, $tag));
        $this->assertSame(1, $this->enrollmentCount($workflow));
        $first = AutomationEnrollment::query()->sole();
        $this->assertStringStartsWith('contact_tag_removed:', $first->trigger_occurrence_key);

        // Detaching an absent pair is the domain's own no-op.
        $this->assertFalse($this->manager()->detachTag($business, $contact, $tag));
        $this->assertSame(1, $this->enrollmentCount($workflow));

        $this->finishJourneys();
        $this->manager()->attachTag($business, $contact, $tag);
        $this->manager()->detachTag($business, $contact, $tag);

        $this->assertSame(2, $this->enrollmentCount($workflow), 'A re-add then remove is a genuinely new occurrence (a new membership row).');
        $this->assertNotSame(
            $first->trigger_occurrence_key,
            AutomationEnrollment::query()->orderByDesc('id')->first()->trigger_occurrence_key,
        );
    }

    // =================================================================
    // 4-5. The actions
    // =================================================================

    public function test_add_tag_action_goes_through_tag_manager_and_a_replay_is_harmless(): void
    {
        [, $business] = $this->crmTenant();
        $business = $this->activate($business);
        $contact = $this->crmContact($business);
        $tag = $this->tag($business, 'Customer');

        [$workflow, $nodes] = $this->manualJourney($business, [$this->addTagStep((int) $tag->id), $this->endStep()]);
        $enrollment = $this->runManual($workflow, $contact);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertSame(1, DB::table('contact_tags')->where('contact_id', $contact->id)->where('tag_id', $tag->id)->count());

        $stepRun = AutomationStepRun::query()->where('enrollment_id', $enrollment->id)->where('node_type', 'add_tag')->sole();
        $this->assertSame(StepRunStatus::Succeeded, $stepRun->status);

        // TagManager produced the event, and the step run rode along as its origin.
        $this->assertCount(1, $this->tagEvents);
        $this->assertInstanceOf(ContactTagAdded::class, $this->tagEvents[0]);
        $this->assertSame(ContactTagTriggerSource::originFor((int) $stepRun->id), $this->tagEvents[0]->origin);

        // The same node, executed again: the held tag is left alone, nothing is emitted.
        $node = $nodes->first(fn ($n) => $n->node_type->value === 'add_tag');
        $outcome = app(AddTagNodeExecutor::class)->execute($node, $enrollment, $business, $contact->fresh());

        $this->assertSame(StepRunStatus::Succeeded, $outcome->status);
        $this->assertStringContainsString('already had', (string) $outcome->safeResultSummary);
        $this->assertSame(1, DB::table('contact_tags')->where('contact_id', $contact->id)->where('tag_id', $tag->id)->count());
        $this->assertCount(1, $this->tagEvents, 'A duplicate attach emits nothing.');
    }

    public function test_remove_tag_action_is_replay_safe(): void
    {
        [, $business] = $this->crmTenant();
        $business = $this->activate($business);
        $contact = $this->crmContact($business);
        $tag = $this->tag($business, 'Prospect');
        $this->manager()->attachTag($business, $contact, $tag);
        $this->tagEvents = [];

        [$workflow, $nodes] = $this->manualJourney($business, [$this->removeTagStep((int) $tag->id), $this->endStep()]);
        $enrollment = $this->runManual($workflow, $contact);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertSame(0, DB::table('contact_tags')->where('contact_id', $contact->id)->count());
        $this->assertCount(1, $this->tagEvents);
        $this->assertInstanceOf(ContactTagRemoved::class, $this->tagEvents[0]);
        $this->assertStringStartsWith(ContactTagTriggerSource::ORIGIN_PREFIX, (string) $this->tagEvents[0]->origin);

        $node = $nodes->first(fn ($n) => $n->node_type->value === 'remove_tag');
        $again = app(RemoveTagNodeExecutor::class)->execute($node, $enrollment, $business, $contact->fresh());

        $this->assertSame(StepRunStatus::Succeeded, $again->status, 'Removing a tag that is not there is harmless.');
        $this->assertStringContainsString('did not have', (string) $again->safeResultSummary);
        $this->assertCount(1, $this->tagEvents, 'The absent detach emits nothing.');
    }

    public function test_the_tag_actions_never_write_the_membership_table_themselves(): void
    {
        foreach (['TagActionNodeExecutor', 'AddTagNodeExecutor', 'RemoveTagNodeExecutor'] as $class) {
            $source = file_get_contents(base_path('app/Library/Automation/Workflow/Executors/' . $class . '.php'));
            // Code only: the docblocks name the table precisely to say it is not written here.
            $source = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);

            foreach (['contact_tags', 'ContactTag::', 'ContactTagAdded::dispatch', 'ContactTagRemoved::dispatch', 'DB::table'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $class . ' must reach tags only through TagManager.');
            }

        }

        $code = fn (string $class) => file_get_contents(base_path('app/Library/Automation/Workflow/Executors/' . $class . '.php'));
        $this->assertStringContainsString('TagManager $tags', $code('TagActionNodeExecutor'));
        $this->assertStringContainsString('->attachTag(', $code('AddTagNodeExecutor'));
        $this->assertStringContainsString('->detachMembership(', $code('RemoveTagNodeExecutor'));
    }

    public function test_an_archived_tag_cannot_be_added_but_can_still_be_removed(): void
    {
        [, $business] = $this->crmTenant();
        $business = $this->activate($business);
        $contact = $this->crmContact($business);
        $tag = $this->tag($business, 'Legacy');
        $this->manager()->attachTag($business, $contact, $tag);

        // Pinned while active, archived afterwards: the version outlives the tag's state.
        [$addWorkflow, $addNodes] = $this->manualJourney($business, [$this->addTagStep((int) $tag->id), $this->endStep()]);
        [$removeWorkflow] = $this->manualJourney($business, [$this->removeTagStep((int) $tag->id), $this->endStep()]);
        $this->manager()->archiveTag($business, $tag);

        $other = $this->crmContact($business);
        $this->runManual($addWorkflow, $other);

        $this->assertSame(0, DB::table('contact_tags')->where('contact_id', $other->id)->count(), 'An archived tag is never attached to a new contact.');
        $this->assertSame(
            'tag_archived',
            AutomationStepRun::query()->where('node_type', 'add_tag')->sole()->safe_error_summary,
        );

        $this->runManual($removeWorkflow, $contact);
        $this->assertSame(0, DB::table('contact_tags')->where('contact_id', $contact->id)->count(), 'Archiving never traps a membership.');
    }

    // =================================================================
    // 6. Loop prevention
    // =================================================================

    public function test_a_workflow_never_re_triggers_off_its_own_tag_change(): void
    {
        [, $business] = $this->crmTenant();
        $business = $this->activate($business);
        $contact = $this->crmContact($business);
        $x = $this->tag($business, 'X');
        $y = $this->tag($business, 'Y');

        // Triggered by ANY tag added; adds Y. Adding Y is itself a tag-added fact.
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ContactTagAdded, [], [$this->addTagStep((int) $y->id), $this->endStep()]);

        $this->manager()->attachTag($business, $contact, $x);
        $enrollment = AutomationEnrollment::query()->sole();
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(1, DB::table('contact_tags')->where('contact_id', $contact->id)->where('tag_id', $y->id)->count());
        $this->assertSame(1, $this->enrollmentCount($workflow), 'The workflow must not enroll the contact again off the tag it just added.');
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
    }

    public function test_two_workflows_that_undo_each_other_stop_at_the_causation_depth_limit(): void
    {
        [, $business] = $this->crmTenant();
        $business = $this->activate($business);
        $contact = $this->crmContact($business);
        $x = $this->tag($business, 'Loop');

        // A: when X is added, remove X.   B: when X is removed, add X.
        $a = $this->triggerWorkflow($business, WorkflowTriggerType::ContactTagAdded, ['tag_id' => $x->id], [$this->removeTagStep((int) $x->id), $this->endStep()], 'A');
        $b = $this->triggerWorkflow($business, WorkflowTriggerType::ContactTagRemoved, ['tag_id' => $x->id], [$this->addTagStep((int) $x->id), $this->endStep()], 'B');

        // A person adds X: depth 0. From here only automations touch the tag.
        $this->manager()->attachTag($business, $contact, $x);

        $advancer = app(WorkflowAdvancer::class);

        for ($i = 0; $i < 30; $i++) {
            $next = AutomationEnrollment::query()->where('status', EnrollmentStatus::Active->value)->orderBy('id')->first();

            if ($next === null) {
                break;
            }

            $advancer->advance($next);
        }

        $this->assertNull(
            AutomationEnrollment::query()->where('status', EnrollmentStatus::Active->value)->first(),
            'The chain must come to rest rather than ping-pong forever.',
        );

        $depths = AutomationEnrollment::query()->orderBy('id')->pluck('causation_depth')->map(fn ($d) => (int) $d)->all();

        $this->assertSame([0, 1, 2, 3], $depths, 'Each link inherits its producer\'s depth plus one, and the chain stops at the limit.');
        $this->assertSame(2, $this->enrollmentCount($a));
        $this->assertSame(2, $this->enrollmentCount($b));
    }

    public function test_a_persons_own_tag_change_is_depth_zero_and_never_suppressed(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $tag = $this->tag($business, 'Repeat');
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ContactTagAdded, ['tag_id' => $tag->id]);

        for ($i = 0; $i < 3; $i++) {
            $this->manager()->attachTag($business, $contact, $tag);
            $this->finishJourneys();
            $this->manager()->detachTag($business, $contact, $tag);
        }

        $this->assertSame(3, $this->enrollmentCount($workflow), 'Independent events are not suppressed globally.');
        $this->assertSame([0, 0, 0], AutomationEnrollment::query()->orderBy('id')->pluck('causation_depth')->map(fn ($d) => (int) $d)->all());
    }

    // =================================================================
    // 7. Tenancy
    // =================================================================

    public function test_a_business_a_event_cannot_enroll_a_business_b_workflow(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');
        $contactA = $this->crmContact($businessA);
        $tagA = $this->tag($businessA, 'VIP');

        $workflowA = $this->triggerWorkflow($businessA, WorkflowTriggerType::ContactTagAdded);
        $workflowB = $this->triggerWorkflow($businessB, WorkflowTriggerType::ContactTagAdded);

        $this->manager()->attachTag($businessA, $contactA, $tagA);

        $this->assertSame(1, $this->enrollmentCount($workflowA));
        $this->assertSame(0, $this->enrollmentCount($workflowB), 'Business A\'s tag change must never reach Business B\'s workflow.');
    }

    public function test_forged_events_naming_a_foreign_tag_or_contact_enroll_nobody(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');
        $contactA = $this->crmContact($businessA);
        $contactB = $this->crmContact($businessB);
        $tagA = $this->tag($businessA, 'A tag');
        $tagB = $this->tag($businessB, 'B tag');

        $workflowA = $this->triggerWorkflow($businessA, WorkflowTriggerType::ContactTagAdded);
        $listener = app(EnrollFromContactTagEvent::class);

        // Business A's id, Business B's tag.
        $listener->handle(new ContactTagAdded((int) $businessA->id, (int) $contactA->id, (int) $tagB->id, 'B tag', null, 9001));
        // Business A's id, Business B's contact.
        $listener->handle(new ContactTagAdded((int) $businessA->id, (int) $contactB->id, (int) $tagA->id, 'A tag', null, 9002));
        // A tag that does not exist at all.
        $listener->handle(new ContactTagAdded((int) $businessA->id, (int) $contactA->id, 999999, 'Ghost', null, 9003));

        $this->assertSame(0, $this->enrollmentCount($workflowA));
    }

    public function test_a_foreign_tag_cannot_be_published_into_a_workflow_filter_or_action(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');
        $foreign = $this->tag($businessB, 'Theirs');

        foreach ([
            'trigger filter' => fn () => $this->triggerWorkflow($businessA, WorkflowTriggerType::ContactTagAdded, ['tag_id' => $foreign->id]),
            'add tag step' => fn () => $this->triggerWorkflow($businessA, WorkflowTriggerType::ManualEnrollment, [], [$this->addTagStep((int) $foreign->id), $this->endStep()]),
            'remove tag step' => fn () => $this->triggerWorkflow($businessA, WorkflowTriggerType::ManualEnrollment, [], [$this->removeTagStep((int) $foreign->id), $this->endStep()]),
        ] as $label => $publish) {
            try {
                $publish();
                $this->fail("A foreign tag in a {$label} must not publish.");
            } catch (\Throwable $exception) {
                $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $exception, $label);
            }
        }

        $this->assertSame(0, \App\Models\AutomationWorkflow::query()->where('business_id', $businessA->id)->whereNotNull('published_version_id')->count());
    }

    public function test_a_tampered_pinned_tag_id_writes_nothing_for_the_foreign_business(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        $businessA = $this->activate($businessA);
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');
        $contactA = $this->crmContact($businessA);
        $ownTag = $this->tag($businessA, 'Own');
        $foreign = $this->tag($businessB, 'Theirs');

        [$workflow] = $this->manualJourney($businessA, [$this->addTagStep((int) $ownTag->id), $this->endStep()]);

        // Repoint the compiled node at the other Business's tag.
        DB::table('automation_workflow_nodes')
            ->where('version_id', $workflow->published_version_id)->where('node_type', 'add_tag')
            ->update(['config' => json_encode(['tag_id' => (int) $foreign->id])]);

        $this->runManual($workflow, $contactA);

        $this->assertSame(0, DB::table('contact_tags')->where('tag_id', $foreign->id)->count());
        $this->assertSame(0, DB::table('contact_tags')->where('contact_id', $contactA->id)->count());
        $this->assertSame('tag_not_in_business', AutomationStepRun::query()->where('node_type', 'add_tag')->sole()->safe_error_summary);
    }

    public function test_a_contact_of_another_business_is_never_enrolled_or_tagged(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        $businessA = $this->activate($businessA);
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');
        $foreignContact = $this->crmContact($businessB);
        $tag = $this->tag($businessA, 'Own');

        [$workflow] = $this->manualJourney($businessA, [$this->addTagStep((int) $tag->id), $this->endStep()]);

        $this->assertNull(
            app(EnrollmentService::class)->enroll($workflow, $foreignContact, 'forged-contact'),
            'EnrollmentService refuses a contact outside the workflow\'s Business.',
        );
        $this->assertSame(0, DB::table('contact_tags')->count());
    }

    // =================================================================
    // The "has tag" condition
    // =================================================================

    public function test_the_has_tag_condition_reads_the_canonical_membership_and_refuses_a_foreign_tag(): void
    {
        [, $business] = $this->crmTenant('Business A', 'Workspace A');
        $business = $this->activate($business);
        [, $other] = $this->crmTenant('Business B', 'Workspace B');
        $tagged = $this->crmContact($business);
        $bare = $this->crmContact($business);
        $tag = $this->tag($business, 'VIP');
        $foreign = $this->tag($other, 'Theirs');
        $this->manager()->attachTag($business, $tagged, $tag);

        $executor = app(\App\Library\Automation\Workflow\Executors\IfElseNodeExecutor::class);
        $enrollment = new AutomationEnrollment(['business_id' => $business->id]);

        $node = fn (string $subject, string $operator) => new AutomationWorkflowNode([
            'node_type' => 'if_else',
            'config' => ['match' => 'all', 'conditions' => [['subject' => $subject, 'operator' => $operator]]],
        ]);
        $branch = fn (Contacts $contact, string $subject, string $operator) => $executor
            ->execute($node($subject, $operator), $enrollment, $business, $contact->fresh())
            ->branchTaken?->value;

        $subject = 'contact.has_tag:' . $tag->id;

        $this->assertSame('yes', $branch($tagged, $subject, 'is_true'), 'Has tag.');
        $this->assertSame('no', $branch($tagged, $subject, 'is_false'));
        $this->assertSame('no', $branch($bare, $subject, 'is_true'));
        $this->assertSame('yes', $branch($bare, $subject, 'is_false'), 'Does not have tag.');

        // A tag of another Business asserts nothing — true or false.
        $foreignSubject = 'contact.has_tag:' . $foreign->id;
        $this->assertSame('no', $branch($bare, $foreignSubject, 'is_false'));
        $this->assertSame('no', $branch($tagged, $foreignSubject, 'is_true'));
    }

    public function test_has_tag_conditions_validate_their_shape_and_their_tag(): void
    {
        [, $business] = $this->crmTenant('Business A', 'Workspace A');
        [, $other] = $this->crmTenant('Business B', 'Workspace B');
        $tag = $this->tag($business, 'VIP');
        $foreign = $this->tag($other, 'Theirs');

        $conditionStep = fn (string $subject, string $operator) => [
            'key' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'if_else',
            'config' => ['match' => 'all', 'conditions' => [['subject' => $subject, 'operator' => $operator]]],
            'yes' => [],
            'no' => [],
        ];

        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [$conditionStep('contact.has_tag:' . $tag->id, 'is_true')]);
        $this->assertNotNull($workflow->published_version_id, 'A condition on the Business\'s own tag publishes.');

        $drafts = app(WorkflowDraftService::class);
        $compiler = app(\App\Library\Automation\Workflow\WorkflowCompiler::class);

        foreach ([
            'a foreign tag' => [$conditionStep('contact.has_tag:' . $foreign->id, 'is_true'), 'does not belong to this business'],
            'a text operator' => [$conditionStep('contact.has_tag:' . $tag->id, 'equals'), 'does not apply'],
            'a malformed tag id' => [$conditionStep('contact.has_tag:abc', 'is_true'), 'cannot read'],
        ] as $label => [$step, $message]) {
            $draftWorkflow = $drafts->createWorkflowWithDraft($business, 'Bad ' . $label, WorkflowTriggerType::ManualEnrollment);
            $draft = $draftWorkflow->draftVersion();
            $definition = $drafts->starterDefinition(WorkflowTriggerType::ManualEnrollment);
            $definition['root']['next'] = [$step];
            $drafts->autosave($draft, $definition, (int) $draft->definition_revision);

            $errors = $compiler->validate($draft->fresh());
            $this->assertNotSame([], $errors, "{$label} must not validate.");
            $this->assertStringContainsString($message, json_encode($errors), $label);
        }
    }
}
