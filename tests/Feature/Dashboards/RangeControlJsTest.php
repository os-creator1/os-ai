<?php

namespace Tests\Feature\Dashboards;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Runs the range control's unit tests (tests/Js/range-control.test.js, plain `node --test`) as part of the PHP suite: a failed preset
 * update keeps Custom as it was, a successful one closes it, presets carry no custom dates, empty Custom stays blocked.
 */
class RangeControlJsTest extends TestCase
{
    public function test_the_range_control_unit_tests_pass_with_a_positive_count(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();

        if (! $node->isSuccessful()) {
            $this->markTestSkipped('node is not installed on this machine.');
        }

        $run = new Process(['node', '--test', 'tests/Js/range-control.test.js'], base_path(), null, null, 60);
        $run->run();
        $output = $run->getOutput() . $run->getErrorOutput();

        $this->assertTrue($run->isSuccessful(), $output);
        $this->assertMatchesRegularExpression('/^ℹ tests ([1-9]\d*)$/m', $output, 'a zero-test success is a failure');
        $this->assertMatchesRegularExpression('/^ℹ fail 0$/m', $output);
    }

    public function test_the_control_script_is_loaded_from_the_shared_file_and_never_edits_state_on_a_preset_press(): void
    {
        $script = (string) file_get_contents(public_path('js/analytics/range-control.js'));

        $this->assertStringContainsString('excludeCustomForSubmit(owner)', $script);
        $this->assertStringContainsString('win.setTimeout(restore, 0)', $script);
        // The preset-press branch must not flip data-custom (that was the defect: a failed request left Custom collapsed).
        $pressBranch = substr($script, (int) strpos($script, "if (press && press.value !== 'custom')"), 500);
        $this->assertStringNotContainsString("setAttribute('data-custom'", $pressBranch);
    }
}
