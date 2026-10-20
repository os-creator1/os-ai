<?php

namespace Tests\Feature\Voice;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Runs the browser-calling state machine's unit tests (tests/Js/softphone-core.test.js, plain `node --test`,
 * simulated SDK events, no browser, no Telnyx) as part of the PHP suite.
 */
class VoiceSoftphoneCoreJsTest extends TestCase
{
    public function test_the_softphone_core_unit_tests_pass_with_a_positive_count(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();

        if (! $node->isSuccessful()) {
            $this->markTestSkipped('node is not installed on this machine.');
        }

        $run = new Process(['node', '--test', '--test-reporter=spec', 'tests/Js/softphone-core.test.js'], base_path(), null, null, 60);
        $run->run();
        $output = $run->getOutput() . $run->getErrorOutput();

        $this->assertTrue($run->isSuccessful(), $output);
        $this->assertMatchesRegularExpression('/^ℹ tests ([1-9]\d*)$/m', $output, 'a zero-test success is a failure');
        $this->assertMatchesRegularExpression('/^ℹ fail 0$/m', $output);
    }
}
