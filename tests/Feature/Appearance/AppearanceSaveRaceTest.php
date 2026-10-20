<?php

namespace Tests\Feature\Appearance;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Rapid Appearance changes must converge on the LAST selection. Server side, saves
 * are applied in order; client side, the Settings script serializes requests. The
 * script that the hub really renders is executed in Node against a controllable
 * fake fetch, so overlapping, out-of-order and failing saves are exercised for real.
 */
class AppearanceSaveRaceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const HARNESS = <<<'JS'
const code = require('fs').readFileSync(0, 'utf8');
const scenario = process.argv[2];
const inbox = [];            // requests the page has sent, each with resolve/reject controls
const log = { shown: [], status: [] };
const radios = {};
// Radios in one group: checking one unchecks the others, as in a browser.
let current = 'light';
const mk = (v) => (radios[v] = { value: v, get checked() { return current === v; }, set checked(on) { if (on) { current = v; } } });
['light', 'dark', 'system'].forEach(mk);
let onChange;
const group = {
  getAttribute: () => '/account/appearance',
  addEventListener: (e, fn) => { onChange = fn; },
  querySelector: (q) => q === 'input:checked' ? Object.values(radios).find(r => r.checked) : radios[(q.match(/value="(\w+)"/) || [])[1]],
};
const status = { textContent: '', classList: { toggle() {} } };
const document = { querySelector: (q) => q === '.mg-appearance-seg' ? group : q.indexOf('appearance-status') > -1 ? status : null };
const window = { MGAppearance: { set: (v) => log.shown.push(v) } };
const fetch = (url, opts) => new Promise((resolve, reject) => inbox.push({ value: JSON.parse(opts.body).appearance, ok: () => resolve({ ok: true }), bad: () => resolve({ ok: false }), drop: () => reject(new Error('net')) }));
new Function('document', 'window', 'fetch', code)(document, window, fetch);
const pick = (v) => { radios[v].checked = true; onChange({ target: { value: v } }); };
const tick = () => new Promise(r => setTimeout(r, 0));
(async () => {
  const out = {};
  if (scenario === 'rapid') {
    pick('dark'); pick('system'); pick('light');
    out.sentWhileFirstPending = inbox.map(r => r.value);
    inbox[0].ok(); await tick();
    out.afterFirst = inbox.map(r => r.value);
    inbox[1].ok(); await tick();
    out.final = inbox.map(r => r.value);
    out.lastShown = log.shown[log.shown.length - 1];
  }
  if (scenario === 'older-fails') {
    pick('dark'); pick('system');
    inbox[0].drop(); await tick();
    out.sent = inbox.map(r => r.value);
    inbox[1].ok(); await tick();
    out.shown = log.shown;
    out.checked = Object.values(radios).filter(r => r.checked).map(r => r.value);
  }
  if (scenario === 'latest-fails') {
    pick('dark');
    inbox[0].bad(); await tick();
    out.shown = log.shown;
    out.checked = Object.values(radios).filter(r => r.checked).map(r => r.value);
    out.status = status.textContent;
  }
  if (scenario === 'retry-after-failure') {
    pick('dark'); inbox[0].drop(); await tick();   // server value is now uncertain, screen fell back to light
    pick('light'); out.resent = inbox.map(r => r.value);   // same as "confirmed", but must still be re-sent
  }
  console.log(JSON.stringify(out));
})();
JS;

    private function hubScript(): string
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $html = $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        preg_match_all('#<script>(.*?)</script>#s', $html, $m);
        foreach ($m[1] as $script) {
            if (str_contains($script, 'mg-appearance-seg')) {
                return $script;
            }
        }
        $this->fail('The Appearance script is not on the Settings hub.');
    }

    private function runNode(string $code, string $scenario): array
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to execute the page script.');
        }

        $harness = tempnam(sys_get_temp_dir(), 'mgapp') . '.js';
        file_put_contents($harness, self::HARNESS);
        $proc = proc_open([$node, $harness, $scenario], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($proc);
        @unlink($harness);

        $this->assertSame('', trim($stderr), $stderr);

        return json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_rapid_changes_send_one_request_at_a_time_and_end_on_the_last_selection(): void
    {
        $r = $this->runNode($this->hubScript(), 'rapid');

        $this->assertSame(['dark'], $r['sentWhileFirstPending'], 'no overlapping request while one is in flight');
        $this->assertSame(['dark', 'light'], $r['afterFirst'], 'the follow-up carries only the latest choice, skipping the intermediate one');
        $this->assertSame(['dark', 'light'], $r['final'], 'nothing further is sent once the latest choice is saved');
        $this->assertSame('light', $r['lastShown']);
    }

    public function test_an_older_failure_does_not_revert_a_newer_selection_and_the_newer_one_is_saved(): void
    {
        $r = $this->runNode($this->hubScript(), 'older-fails');

        $this->assertSame(['dark', 'system'], $r['sent']);
        $this->assertSame(['system'], $r['checked']);
        $this->assertNotContains('light', $r['shown'], 'a stale failure never flips the screen back');
    }

    public function test_a_failed_latest_selection_falls_back_to_the_saved_value_consistently(): void
    {
        $r = $this->runNode($this->hubScript(), 'latest-fails');

        $this->assertSame(['light'], $r['checked']);
        $this->assertSame('light', $r['shown'][array_key_last($r['shown'])]);
        $this->assertStringContainsString('Could not save', $r['status']);
    }

    public function test_after_a_failure_the_same_value_is_still_resent_because_the_server_state_is_unknown(): void
    {
        $r = $this->runNode($this->hubScript(), 'retry-after-failure');

        $this->assertSame(['dark', 'light'], $r['resent']);
    }

    public function test_saves_are_applied_in_order_so_the_last_one_stored_is_the_last_selected(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        foreach (['dark', 'system', 'light', 'dark', 'system'] as $value) {
            $this->postJson(route('user.account.appearance'), ['appearance' => $value])->assertOk();
        }
        $this->assertSame('system', $customer->user->fresh()->appearance);

        // A rejected save in the middle of a burst leaves the previous stored value intact.
        $this->postJson(route('user.account.appearance'), ['appearance' => 'dark'])->assertOk();
        $this->postJson(route('user.account.appearance'), ['appearance' => 'bogus'])->assertStatus(422);
        $this->assertSame('dark', $customer->user->fresh()->appearance);
    }
}
