<?php

namespace Tests\Unit\Growth;

use App\Enums\Opportunity\OpportunityActionExecutionStatus as E;
use App\Enums\Opportunity\OpportunityCompletionPolicy as P;
use App\Enums\Opportunity\OpportunityStatus as S;
use App\Library\Growth\GrowthWorkStage;
use PHPUnit\Framework\TestCase;

class GrowthWorkStageTest extends TestCase
{
    /** @return array<string, array{0: S, 1: ?E, 2: ?P, 3: ?string}> */
    public static function cases(): array
    {
        return [
            'plain recommendation' => [S::Open, null, null, 'recommended'],
            'awaiting approval' => [S::AwaitingApproval, null, null, 'awaiting_approval'],
            'approved, queued' => [S::InProgress, E::Pending, null, 'approved'],
            'approved, no execution row yet' => [S::InProgress, null, null, 'approved'],
            'running' => [S::InProgress, E::Running, null, 'executing'],
            'ran, not yet confirmed' => [S::InProgress, E::Succeeded, P::CustomerAttested, 'awaiting_verification'],
            'failed' => [S::Open, E::Failed, null, 'failed'],
            'system verified' => [S::Completed, E::Succeeded, P::SystemVerified, 'verified'],
            'attested completion' => [S::Completed, E::Succeeded, P::CustomerAttested, 'completed'],
            'completed without an execution' => [S::Completed, null, null, 'completed'],
        ];
    }

    /** @dataProvider cases */
    public function test_each_stage_comes_only_from_the_records_that_prove_it(S $status, ?E $execution, ?P $policy, string $expected): void
    {
        $this->assertSame($expected, GrowthWorkStage::for($status, $execution, $policy)['key']);
    }

    public function test_snoozed_and_dismissed_work_is_not_part_of_the_record(): void
    {
        $this->assertNull(GrowthWorkStage::for(S::Snoozed, null));
        $this->assertNull(GrowthWorkStage::for(S::Dismissed, E::Succeeded, P::SystemVerified));
    }

    public function test_a_failure_after_a_completion_never_overrides_it_and_finished_stages_carry_the_outcome_disclaimer(): void
    {
        $this->assertSame('completed', GrowthWorkStage::for(S::Completed, E::Failed, P::CustomerAttested)['key']);

        foreach ([[S::Completed, E::Succeeded, P::SystemVerified], [S::Completed, E::Succeeded, P::CustomerAttested]] as [$s, $e, $p]) {
            $this->assertStringContainsString(GrowthWorkStage::OUTCOME_NOTE, GrowthWorkStage::for($s, $e, $p)['note']);
        }
    }

    public function test_a_failure_note_uses_the_safe_reason_when_there_is_one(): void
    {
        $this->assertStringContainsString('Safe reason.', GrowthWorkStage::for(S::Open, E::Failed, null, 'Safe reason.')['note']);
        $this->assertStringContainsString('did not complete', GrowthWorkStage::for(S::Open, E::Failed)['note']);
    }
}
