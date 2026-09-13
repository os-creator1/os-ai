<?php

namespace Tests\Feature\Automations\Workflow\MessageReceived;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactRepliedSinceEnrollmentSubject;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Executors\IfElseNodeExecutor;
use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\WorkflowSimulator;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
use Tests\Feature\Automations\Workflow\MessageReceived\Support\BuildsInboundFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-F §11 — `contact.replied_since_enrollment`.
 *
 * A boolean read of the Business's conversation history, strictly after the
 * enrollment, inside the enrollment's Business only.
 */
class RepliedSinceEnrollmentSubjectTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsLogicWorkflows;
    use BuildsInboundFixtures;

    private function subject(): ContactRepliedSinceEnrollmentSubject
    {
        return new ContactRepliedSinceEnrollmentSubject();
    }

    /** @return array{0: Business, 1: Contacts, 2: AutomationEnrollment} */
    private function enrolledContact(string $phone): array
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contact($business, $this->contactGroup($business), $phone);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'manual:' . $phone);
        $this->assertNotNull($enrollment);

        // A fixed boundary, well in the past, so "before" and "after" are
        // unambiguous regardless of how fast the test runs.
        $this->enrolledAt($enrollment, Carbon::now()->subHours(2));

        return [$business, $contact->fresh(), $enrollment->fresh()];
    }

    // =================================================================
    // Registration — boolean only, known to every checkpoint
    // =================================================================

    public function test_it_is_a_registered_boolean_subject_with_only_is_true_and_is_false(): void
    {
        $key = 'contact.replied_since_enrollment';
        $registry = app(ConditionSubjectRegistry::class);

        $this->assertInstanceOf(ContactRepliedSinceEnrollmentSubject::class, $registry->find($key));
        $this->assertTrue(ConditionSubjectRegistry::isKnownSubjectKey($key));
        $this->assertContains($key, $registry->staticKeys());
        $this->assertSame('boolean', $this->subject()->valueType());

        $this->assertSame(
            [ConditionOperator::IsTrue, ConditionOperator::IsFalse],
            ConditionSubjectRegistry::staticOperatorsFor($key),
        );

        foreach ([ConditionOperator::Equals, ConditionOperator::Contains, ConditionOperator::IsEmpty, ConditionOperator::Before] as $operator) {
            $this->assertFalse($registry->allows($key, $operator), $operator->value . ' is not a boolean operator.');
        }
    }

    public function test_the_validator_accepts_it_with_a_boolean_operator_and_refuses_anything_else(): void
    {
        $registry = app(NodeTypeRegistry::class);

        $valid = $registry->validateConfig(\App\Enums\Automation\Workflow\WorkflowNodeType::IfElse, [
            'match' => 'all',
            'conditions' => [['subject' => 'contact.replied_since_enrollment', 'operator' => 'is_false']],
        ]);
        $this->assertSame([], $valid);

        $invalid = $registry->validateConfig(\App\Enums\Automation\Workflow\WorkflowNodeType::IfElse, [
            'match' => 'all',
            'conditions' => [['subject' => 'contact.replied_since_enrollment', 'operator' => 'equals', 'operand' => 'yes']],
        ]);
        $this->assertNotSame([], $invalid, 'A boolean subject cannot be compared as text.');
    }

    // =================================================================
    // The boundary
    // =================================================================

    public function test_it_is_false_before_any_inbound_message(): void
    {
        [, $contact, $enrollment] = $this->enrolledContact('14155552001');

        $this->assertFalse($this->subject()->valueFor($contact, $enrollment));
    }

    public function test_it_is_true_after_a_qualifying_inbound_message(): void
    {
        [$business, $contact, $enrollment] = $this->enrolledContact('14155552002');

        $this->conversationMessage($business, '14155552002', 'incoming', Carbon::now()->subHour());

        $this->assertTrue($this->subject()->valueFor($contact, $enrollment));
    }

    public function test_an_inbound_message_before_enrollment_does_not_count(): void
    {
        [$business, $contact, $enrollment] = $this->enrolledContact('14155552003');

        // The contact wrote three hours ago; the enrollment was two hours ago.
        $this->conversationMessage($business, '14155552003', 'incoming', Carbon::now()->subHours(3));

        $this->assertFalse($this->subject()->valueFor($contact, $enrollment));
    }

    public function test_a_message_at_the_exact_enrollment_instant_does_not_count(): void
    {
        [$business, $contact, $enrollment] = $this->enrolledContact('14155552004');

        // Strictly after: the very message that enrolled a contact through the
        // message-received trigger is not a reply "since" that enrollment.
        $this->conversationMessage($business, '14155552004', 'incoming', Carbon::parse($enrollment->enrolled_at));

        $this->assertFalse($this->subject()->valueFor($contact, $enrollment));
    }

    public function test_an_outgoing_message_is_not_a_reply(): void
    {
        [$business, $contact, $enrollment] = $this->enrolledContact('14155552005');

        $this->conversationMessage($business, '14155552005', 'outgoing', Carbon::now()->subHour());

        $this->assertFalse($this->subject()->valueFor($contact, $enrollment), 'The Business writing is not the contact replying.');
    }

    // =================================================================
    // Tenant isolation
    // =================================================================

    public function test_a_reply_in_another_businesss_conversation_does_not_count(): void
    {
        [, $contact, $enrollment] = $this->enrolledContact('14155552006');
        [, $otherBusiness] = $this->entitledTenant();

        // Same number, writing to a DIFFERENT Business.
        $this->conversationMessage($otherBusiness, '14155552006', 'incoming', Carbon::now()->subHour());

        $this->assertFalse($this->subject()->valueFor($contact, $enrollment));
    }

    public function test_a_reply_from_a_different_number_in_the_same_business_does_not_count(): void
    {
        [$business, $contact, $enrollment] = $this->enrolledContact('14155552007');

        $this->conversationMessage($business, '14155552999', 'incoming', Carbon::now()->subHour());

        $this->assertFalse($this->subject()->valueFor($contact, $enrollment));
    }

    public function test_a_contact_moved_out_of_the_enrollments_business_reads_false(): void
    {
        [$business, $contact, $enrollment] = $this->enrolledContact('14155552008');
        [, $otherBusiness] = $this->entitledTenant();

        $this->conversationMessage($business, '14155552008', 'incoming', Carbon::now()->subHour());
        DB::table('contacts')->where('id', $contact->id)->update(['business_id' => $otherBusiness->id]);

        $this->assertFalse($this->subject()->valueFor($contact->fresh(), $enrollment));
    }

    public function test_an_unfiled_legacy_conversation_is_not_a_reply(): void
    {
        [$business, $contact, $enrollment] = $this->enrolledContact('14155552009');

        // A conversation with no Business (the legacy path files an
        // unattributable message this way) is visible from nowhere, and so is
        // not evidence of a reply to this Business.
        $boxId = DB::table('chat_boxes')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $business->customer_id,
            'business_id' => null,
            'from' => self::BUSINESS_NUMBER,
            'to' => '14155552009',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('chat_box_messages')->insert([
            'box_id' => $boxId, 'message' => 'x', 'sms_type' => 'plain', 'direction' => 'incoming',
            'created_at' => Carbon::now()->subHour(), 'updated_at' => Carbon::now()->subHour(),
        ]);

        $this->assertFalse($this->subject()->valueFor($contact, $enrollment));
    }

    // =================================================================
    // Through the real If/Else executor, and the simulator
    // =================================================================

    public function test_the_real_if_else_executor_branches_on_it(): void
    {
        [$business, $contact, $enrollment] = $this->enrolledContact('14155552010');

        $node = new AutomationWorkflowNode([
            'node_type' => 'if_else',
            'config' => [
                'match' => 'all',
                'conditions' => [$this->condition('contact.replied_since_enrollment', 'is_true')],
            ],
        ]);

        $executor = app(IfElseNodeExecutor::class);

        $this->assertSame('no', $executor->execute($node, $enrollment, $business, $contact)->branchTaken?->value);

        $this->conversationMessage($business, '14155552010', 'incoming', Carbon::now()->subHour());

        $this->assertSame('yes', $executor->execute($node, $enrollment, $business, $contact)->branchTaken?->value);
    }

    public function test_the_simulator_reads_it_as_false_and_writes_nothing(): void
    {
        [$business, $contact] = $this->enrolledContact('14155552011');

        // A reply exists in history — but a simulated journey starts now, so
        // there is no boundary it could be "since".
        $this->conversationMessage($business, '14155552011', 'incoming', Carbon::now()->subMinutes(5));

        [, $version] = $this->publishWorkflow($business, [
            $this->ifElseStep(
                [$this->condition('contact.replied_since_enrollment', 'is_true')],
                [$this->recordedStep('replied'), $this->endStep()],
                [$this->recordedStep('no reply'), $this->endStep()],
            ),
        ], WorkflowTriggerType::MessageReceived);

        $before = DB::table('automation_enrollments')->count();

        $result = app(WorkflowSimulator::class)->simulate($version, $contact);

        $branch = collect($result['steps'])->firstWhere('type', 'if_else');
        $this->assertSame('no', $branch['branch']);
        $this->assertSame($before, DB::table('automation_enrollments')->count());
    }
}
