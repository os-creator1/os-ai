<?php

namespace Tests\Feature\Crm;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Runs the board's move-coordination unit tests (tests/Js/crm-board-moves.test.js, plain
 * `node --test`, no framework) as part of the PHP suite: stale responses ignored, rapid
 * A->B->C ends in C, an old failure never rolls back a newer move, bounded retries.
 */
class CrmBoardMovesJsTest extends TestCase
{
    public function test_the_move_coordination_unit_tests_pass_with_a_positive_count(): void
    {
        $node = (new Process(['node', '--version']));
        $node->run();

        if (! $node->isSuccessful()) {
            $this->markTestSkipped('node is not installed on this machine.');
        }

        $run = new Process(['node', '--test', 'tests/Js/crm-board-moves.test.js'], base_path(), null, null, 60);
        $run->run();
        $output = $run->getOutput() . $run->getErrorOutput();

        $this->assertTrue($run->isSuccessful(), $output);
        $this->assertMatchesRegularExpression('/^ℹ tests ([1-9]\d*)$/m', $output, 'a zero-test success is a failure');
        $this->assertMatchesRegularExpression('/^ℹ fail 0$/m', $output);
    }
}
