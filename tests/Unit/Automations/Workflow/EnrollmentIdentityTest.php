<?php

namespace Tests\Unit\Automations\Workflow;

use App\Enums\Automation\Workflow\EnrollmentPolicy;
use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\NodeSideEffectClass;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use PHPUnit\Framework\TestCase;

/**
 * Automations V2 V2-0 — the identity and state rules later slices depend on.
 *
 * Small, but each of these is something a runtime lane would otherwise have to
 * guess at. Getting the key composition wrong is how a yearly workflow silently
 * becomes a one-time one; getting the side-effect class wrong is how a text
 * message gets sent twice after a crash.
 */
class EnrollmentIdentityTest extends TestCase
{
    /** The trigger-aware defaults locked by owner decision D3. */
    public function test_each_trigger_carries_its_own_default_enrollment_policy(): void
    {
        $this->assertSame(
            EnrollmentPolicy::OnceEver,
            WorkflowTriggerType::ContactCreated->defaultEnrollmentPolicy(),
        );
        $this->assertSame(
            EnrollmentPolicy::OnceEver,
            WorkflowTriggerType::ManualEnrollment->defaultEnrollmentPolicy(),
        );
        $this->assertSame(
            EnrollmentPolicy::OncePerOccurrence,
            WorkflowTriggerType::ContactDateReached->defaultEnrollmentPolicy(),
            'A birthday workflow must let a contact enter again next year.',
        );
        $this->assertSame(
            EnrollmentPolicy::OncePerOccurrence,
            WorkflowTriggerType::MessageReceived->defaultEnrollmentPolicy(),
        );
    }

    /** `once_ever` ignores the occurrence, so a contact enters once and only once. */
    public function test_once_ever_produces_one_key_whatever_the_occurrence(): void
    {
        $first = EnrollmentPolicy::OnceEver->enrollmentKey(7, 42, '2026');
        $second = EnrollmentPolicy::OnceEver->enrollmentKey(7, 42, '2027');

        $this->assertSame($first, $second, 'The occurrence must not affect a once-ever key.');
        $this->assertSame('wf:7:c:42', $first);
    }

    /**
     * `once_per_occurrence` separates occurrences — the property that lets the
     * same contact have a birthday every year without a duplicate in one year.
     */
    public function test_once_per_occurrence_separates_occurrences_but_not_repeats(): void
    {
        $thisYear = EnrollmentPolicy::OncePerOccurrence->enrollmentKey(7, 42, '2026');
        $sameYearAgain = EnrollmentPolicy::OncePerOccurrence->enrollmentKey(7, 42, '2026');
        $nextYear = EnrollmentPolicy::OncePerOccurrence->enrollmentKey(7, 42, '2027');

        $this->assertSame($thisYear, $sameYearAgain, 'The same occurrence must collide, so it enrolls once.');
        $this->assertNotSame($thisYear, $nextYear, 'A later occurrence must be allowed to enroll again.');
        $this->assertSame('wf:7:c:42:o:2026', $thisYear);
    }

    /** Keys never collide across workflows or contacts. */
    public function test_keys_are_scoped_to_their_workflow_and_contact(): void
    {
        $this->assertNotSame(
            EnrollmentPolicy::OnceEver->enrollmentKey(7, 42, 'x'),
            EnrollmentPolicy::OnceEver->enrollmentKey(8, 42, 'x'),
        );
        $this->assertNotSame(
            EnrollmentPolicy::OnceEver->enrollmentKey(7, 42, 'x'),
            EnrollmentPolicy::OnceEver->enrollmentKey(7, 43, 'x'),
        );
    }

    /** Only `active` and `waiting` occupy a workflow; everything else is terminal. */
    public function test_only_active_and_waiting_are_non_terminal(): void
    {
        $this->assertFalse(EnrollmentStatus::Active->isTerminal());
        $this->assertFalse(EnrollmentStatus::Waiting->isTerminal());

        foreach ([
            EnrollmentStatus::Completed,
            EnrollmentStatus::Failed,
            EnrollmentStatus::Exited,
            EnrollmentStatus::Cancelled,
        ] as $terminal) {
            $this->assertTrue($terminal->isTerminal(), $terminal->value . ' should be terminal.');
        }

        $this->assertSame(
            [EnrollmentStatus::Active, EnrollmentStatus::Waiting],
            EnrollmentStatus::occupyingStatuses(),
            'These two are what the active-contact guard is built from.',
        );
    }

    /**
     * The recovery rule (§7.4) turns entirely on this classification: an
     * external step is never re-run after a crash, because a message may already
     * have gone out.
     */
    public function test_only_external_steps_are_unsafe_to_re_execute(): void
    {
        $this->assertSame(NodeSideEffectClass::External, WorkflowNodeType::SendSms->sideEffectClass());
        $this->assertSame(NodeSideEffectClass::External, WorkflowNodeType::InternalNotification->sideEffectClass());
        $this->assertFalse(WorkflowNodeType::SendSms->sideEffectClass()->isSafeToReExecute());

        foreach ([WorkflowNodeType::Trigger, WorkflowNodeType::Wait, WorkflowNodeType::IfElse, WorkflowNodeType::End] as $pure) {
            $this->assertSame(NodeSideEffectClass::None, $pure->sideEffectClass());
            $this->assertTrue($pure->sideEffectClass()->isSafeToReExecute());
        }

        $this->assertSame(
            NodeSideEffectClass::IdempotentDatabase,
            WorkflowNodeType::UpdateContactField->sideEffectClass(),
        );
        $this->assertTrue(WorkflowNodeType::UpdateContactField->sideEffectClass()->isSafeToReExecute());
    }

    /** Only an If/Else branches; only End terminates. */
    public function test_branching_and_terminal_types_are_exactly_what_the_compiler_expects(): void
    {
        foreach (WorkflowNodeType::cases() as $type) {
            $this->assertSame(
                $type === WorkflowNodeType::IfElse,
                $type->isBranching(),
                $type->value . ' branching flag',
            );
            $this->assertSame(
                $type === WorkflowNodeType::End,
                $type->isTerminal(),
                $type->value . ' terminal flag',
            );
        }
    }

    /** A step run is interruptible only while it is `started`. */
    public function test_only_a_started_step_run_is_interruptible(): void
    {
        $this->assertTrue(StepRunStatus::Started->isInterruptible());

        foreach ([StepRunStatus::Waiting, StepRunStatus::Succeeded, StepRunStatus::Failed, StepRunStatus::Skipped] as $status) {
            $this->assertFalse($status->isInterruptible(), $status->value . ' should not be interruptible.');
        }
    }
}
