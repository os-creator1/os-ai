<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The Landing Page editor's autosave / publish behaviour, exercised in a real browser against the REAL rendered editor
 * (this application's HTML and scripts) with a simulated server that can be slow, reject or drop requests.
 *
 * Needs Node + Playwright + Chromium (present in CI images and the dev container); skipped, loudly, where they are not.
 * The pure save-queue rules are also covered without a browser by `node --test tests/Js/agency-site-saver.test.cjs`,
 * which this test runs too.
 */
class AgencySiteEditorScriptTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private function node(array $args, int $timeout = 120): Process
    {
        $process = new Process(array_merge(['node'], $args), base_path(), null, null, $timeout);
        $process->run();

        return $process;
    }

    public function test_the_save_queue_rules_hold_under_slow_failed_and_overlapping_requests(): void
    {
        if ((new Process(['node', '--version']))->run() !== 0) {
            $this->markTestSkipped('Node is not available.');
        }

        $process = $this->node(['--test', 'tests/Js/agency-site-saver.test.cjs']);

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('# fail 0', $process->getOutput());
    }

    /** @return array<string, array<string, mixed>> */
    private function runEditor(): array
    {
        if ((new Process(['node', '--version']))->run() !== 0) {
            $this->markTestSkipped('Node is not available.');
        }

        [$owner, , $agency] = $this->tenant(WorkspacePlanTier::Agency, 'Editor Business', 'Editor Agency');
        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->authenticateAs($owner);

        $html = $this->get(route('customer.workspaces.agency.white-label.show', ['workspaceUid' => $agency->uid, 'tab' => 'landing']))->assertOk()->getContent();
        $this->assertStringContainsString('js/agency-site-saver.js', $html, 'the editor loads the save queue');

        $dir = sys_get_temp_dir() . '/agency-editor-' . bin2hex(random_bytes(4));
        mkdir($dir);
        // Serve the real script files and stylesheets from this checkout's public directory.
        $page = preg_replace('#https?://[^"\']+/(js/agency-site-saver\.js)#', 'file://' . public_path('$1'), $html);
        $page = preg_replace('#https?://localhost/#', 'file://' . public_path() . '/', $page);
        file_put_contents($dir . '/editor.html', $page);

        $process = $this->node(['tests/Js/editor-dom.cjs', $dir . '/editor.html', public_path()], 240);
        @unlink($dir . '/editor.html');
        @rmdir($dir);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $results = json_decode(trim($process->getOutput()), true);
        $this->assertIsArray($results, $process->getOutput());

        if (isset($results['skipped'])) {
            $this->markTestSkipped($results['skipped']);
        }

        foreach ($results as $name => $result) {
            $this->assertArrayNotHasKey('failure', $result, "{$name}: scenario did not complete: ".($result['failure'] ?? ''));
            $this->assertSame([], $result['errors'] ?? [], "{$name}: no script errors");
        }

        return $results;
    }

    public function test_rapid_edits_while_a_save_is_in_flight_keep_the_latest_value(): void
    {
        $r = $this->runEditor()['rapidEdits'];

        $this->assertSame('First plus the newer edit', $r['final'], 'the server ends with the newest edit');
        $this->assertSame('First plus the newer edit', $r['inputKept']);
        $this->assertSame(['First', 'First plus the newer edit'], $r['saves'], 'both edits were sent, in order');
    }

    public function test_publish_is_refused_when_a_save_fails_validation_or_the_network_drops(): void
    {
        $results = $this->runEditor();

        foreach (['validationFailureBlocksPublish', 'networkFailureBlocksPublish'] as $scenario) {
            $this->assertSame(0, $results[$scenario]['published'], "{$scenario}: publish must not be requested");
            $this->assertStringContainsString('Not published', $results[$scenario]['message']);
            $this->assertNotSame('', $results[$scenario]['inputKept'], "{$scenario}: the edit stays on screen");
        }

        $this->assertStringContainsString('plain text', $results['validationFailureBlocksPublish']['message']);
        $this->assertStringContainsString('Bad <b>title</b>', $results['validationFailureBlocksPublish']['inputKept']);
        $this->assertStringContainsString('Could not reach the server', $results['networkFailureBlocksPublish']['message']);
    }

    public function test_publish_saves_a_pending_edit_first_and_save_draft_only_reports_saved_after_success(): void
    {
        $results = $this->runEditor();

        $this->assertSame(1, $results['pendingEditIsSavedThenPublished']['published']);
        $this->assertSame('Typed just before publishing', $results['pendingEditIsSavedThenPublished']['atPublish'], 'the server already held the edit when publishing');

        $draft = $results['saveDraft'];
        $this->assertStringNotContainsString('Draft saved', $draft['during'], 'never "saved" while the request is still running');
        $this->assertSame('Draft saved', $draft['after']);
        $this->assertStringContainsString('Not saved', $draft['failed']);
        $this->assertSame('FAIL this one', $draft['inputKept']);
    }

    public function test_a_section_reset_wins_over_a_pending_or_in_flight_autosave_and_stays_after_the_reload(): void
    {
        $results = $this->runEditor();

        foreach (['resetBeatsPendingDebounce', 'resetBeatsInFlightSave'] as $scenario) {
            $events = $results[$scenario]['events'];

            $this->assertContains('reset', $events, "{$scenario}: the reset was sent");
            $this->assertSame('reset', end($events), "{$scenario}: nothing is saved after the reset (no leaked timer, no stale queue)");
            $this->assertFalse($results[$scenario]['finalHasOverride'], "{$scenario}: the server holds the reset, not the edit");
            $this->assertTrue($results[$scenario]['reloaded'], "{$scenario}: the page reloads with the reset content");
        }

        // With a save already in flight, the reset is applied AFTER it, in order — so it is what the server ends with.
        $inFlight = $results['resetBeatsInFlightSave']['events'];
        $this->assertStringStartsWith('save:', $inFlight[0]);
    }

    public function test_resetting_one_section_never_discards_another_sections_edits(): void
    {
        $results = $this->runEditor();

        foreach (['resetKeepsUnrelatedPendingEdit', 'resetKeepsUnrelatedInFlightEdit'] as $name) {
            $r = $results[$name];
            $this->assertEmpty($r['failure'] ?? null, $name.': '.($r['failure'] ?? ''));
            $this->assertTrue($r['otherSaved'], "{$name}: the unrelated edit reached the server before the page reloaded");
            $this->assertFalse($r['heroHasOverride'], "{$name}: the reset section stays reset");
            $this->assertTrue($r['reloaded'], "{$name}: the page reloads once everything is saved");
            $this->assertSame('reset', end($r['events']), "{$name}: the stale edit of the reset section is never saved after the reset");
            $this->assertSame(1, count(array_filter($r['events'], fn ($e) => $e === 'reset')));
            if ($name === 'resetKeepsUnrelatedPendingEdit') {
                $this->assertNotContains('save:copy.home.hero.title', $r['events'], "{$name}: the reset section's own pending edit is dropped");
            }
        }

        // The unrelated save is queued BEFORE the reset, so it is never overtaken by it.
        $events = $results['resetKeepsUnrelatedInFlightEdit']['events'];
        $this->assertStringStartsWith('save:', $events[0]);
    }

    public function test_when_unrelated_edits_cannot_be_saved_the_page_does_not_reload_over_them(): void
    {
        $r = $this->runEditor()['resetWithFailedUnrelatedSave'];

        $this->assertEmpty($r['failure'] ?? null, (string) ($r['failure'] ?? ''));
        $this->assertContains('reset', $r['events'], 'the reset itself still happened');
        $this->assertFalse($r['reloaded'], 'no reload while unsaved edits are on screen');
        $this->assertSame('Kept unrelated', $r['otherKept'], 'the edit is still in the field');
        $this->assertStringContainsString('not saved', $r['state']);
        $this->assertFalse($r['otherSaved']);
    }
}
