<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\Business;
use App\Models\NicheBlueprint;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Implementation Contract 20 §7.2/§13.9, Sub-slice C — real concurrency
 * coverage for the installation engine, using genuinely independent OS
 * processes rather than sequential coincidence, following
 * NicheBlueprintPublisherConcurrencyTest's established shape for this domain.
 *
 * WHAT §13.9 ACTUALLY DEMANDS: "two simultaneous installation runs for one
 * Business produce exactly one `installed` record and one business-owned row
 * per component." The second half is the half that can genuinely break. A
 * duplicated installation RECORD is impossible by construction — the UNIQUE
 * `(business_id, blueprint_id, component_key)` key refuses it at the database
 * layer — but a duplicated BUSINESS-OWNED ROW is not: §7.2 step 5 calls the
 * adapter BEFORE writing the record, so a losing run that reached step 5 would
 * have created its Business-owned row already. These tests therefore assert on
 * `crm_pipelines` at least as hard as on the records: the mechanism that has
 * to work is the lock-and-re-read at §7.2 steps 1-2, and the UNIQUE key is
 * only the backstop behind it.
 *
 * Deliberately does NOT use RefreshDatabase: a genuinely separate process
 * needs COMMITTED rows, which an open RefreshDatabase transaction would hide
 * entirely. Fixtures are built through the ordinary domain seams (which
 * auto-commit here) and removed in tearDown().
 *
 * FIXTURE ORDERING IS LOAD-BEARING. §9.1's two triggers are live, `sync` queue
 * and all, so a tenant built AFTER the Blueprint was published would already
 * be fully installed before the first child process ever started, and every
 * race below would be a vacuous pass. Every test therefore builds the tenant
 * FIRST — both triggers fire and both abort, one for `workspace_plan_
 * unassigned` and one for `no_blueprint` — publishes the Blueprint second, and
 * asserts zero records and zero Business-owned rows before racing anything.
 */
class NicheBlueprintInstallerConcurrencyTest extends TestCase
{
    use CreatesBlueprintInstallationFixtures;

    private const RUNNER = __DIR__ . '/Support/concurrent_installer_runner.php';

    /** The Growth-tier expectation of the three-component discriminator below. */
    private const INSTALLABLE_COMPONENTS = ['everywhere', 'growth_only'];

    private const UNAVAILABLE_COMPONENTS = ['planned'];

    /** @var list<int> */
    private array $createdBusinessIds = [];

    /** @var list<int> */
    private array $createdWorkspaceIds = [];

    /** @var list<int> */
    private array $createdUserIds = [];

    /** @var list<int> */
    private array $createdBlueprintIds = [];

    /** @var list<string> */
    private array $barrierFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerBlueprintTestAdapters();
    }

    /**
     * Without RefreshDatabase nothing is rolled back, so every row these tests
     * commit is removed by hand.
     *
     * The Blueprint graph is the one that would actively CONTAMINATE a later
     * test rather than merely linger: §7.1's broad-industry fallback FAILS
     * CLOSED when more than one active Blueprint matches an industry, so a
     * leaked Blueprint would abort the next test's resolution instead of
     * installing anything, and the suite would go green for the wrong reason.
     *
     * Order is dictated by the foreign keys, which are RESTRICT far more often
     * than CASCADE here: installation records before Blueprints
     * (`bbci_blueprint_foreign`), Businesses before Workspaces
     * (`businesses_workspace_id_foreign`), and every Workspace child and
     * Blueprint version before the users they name as actors.
     */
    protected function tearDown(): void
    {
        if ($this->createdBusinessIds !== []) {
            DB::table('business_blueprint_component_installations')
                ->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('crm_pipelines')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
        }

        if ($this->createdBlueprintIds !== []) {
            DB::table('niche_blueprint_components')->whereIn('blueprint_id', $this->createdBlueprintIds)->delete();
            DB::table('niche_blueprint_versions')->whereIn('blueprint_id', $this->createdBlueprintIds)->delete();
            DB::table('niche_blueprints')->whereIn('id', $this->createdBlueprintIds)->delete();
        }

        if ($this->createdWorkspaceIds !== []) {
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_memberships')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        foreach ($this->barrierFiles as $barrierFile) {
            if (file_exists($barrierFile)) {
                unlink($barrierFile);
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    /**
     * A Growth tenant with NO Blueprint yet, and the published three-component
     * Blueprint that will resolve for it — in that order, for the reason the
     * class docblock gives.
     *
     * @return array{0: Business, 1: Workspace, 2: NicheBlueprint}
     */
    private function tenantAwaitingInstallation(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Race Studios', 'Race Lane');

        $this->createdBusinessIds[] = (int) $business->id;
        $this->createdWorkspaceIds[] = (int) $workspace->id;
        $this->createdUserIds[] = (int) $customer->user_id;
        $this->createdUserIds[] = $this->platformAdminId();

        $blueprint = $this->publishBlueprint(
            [
                ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
                ['key' => 'growth_only', 'feature' => self::FEATURE_GROWTH_ONLY],
                ['key' => 'planned', 'feature' => self::FEATURE_PLANNED],
            ],
            key: 'race_' . uniqid(),
        );

        $this->createdBlueprintIds[] = (int) $blueprint->id;

        // The §9.1 triggers already fired during tenant construction and found
        // no Blueprint to resolve. If either had installed anything, every
        // race below would be measuring an already-finished installation.
        $this->assertSame(0, $this->installationRecordCount($business), 'The fixture must not be pre-installed.');
        $this->assertSame(0, $this->businessOwnedRowCount($business), 'The fixture must own no component rows yet.');

        return [$business, $workspace, $blueprint];
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database];
    }

    private function runner(string ...$args): Process
    {
        return new Process(
            array_merge([$this->phpBinary(), self::RUNNER], $args),
            null,
            $this->childEnvironment(),
            null,
            120.0
        );
    }

    /**
     * A barrier path handed to children so they can bootstrap Laravel — the
     * slow, variable part — BEFORE the race starts, and then enter
     * `installForBusiness()` together on the parent's signal.
     */
    private function newBarrierFile(): string
    {
        $path = sys_get_temp_dir() . '/blueprint_install_barrier_' . uniqid('', true);

        $this->barrierFiles[] = $path;

        return $path;
    }

    private function releaseBarrier(string $barrierFile): void
    {
        file_put_contents($barrierFile, 'go');
    }

    private function awaitOutput(Process $process, string $needle, string $what): void
    {
        $deadline = microtime(true) + 60.0;

        while (! str_contains($process->getOutput(), $needle) && microtime(true) < $deadline) {
            if (! $process->isRunning() && ! str_contains($process->getOutput(), $needle)) {
                $this->fail("{$what} exited before signalling [{$needle}]: " . $process->getErrorOutput());
            }

            usleep(20_000);
        }

        $this->assertStringContainsString(
            $needle,
            $process->getOutput(),
            "{$what} never signalled [{$needle}]: " . $process->getErrorOutput()
        );
    }

    /**
     * The child's own report of what its run decided.
     *
     * @return array{abort: string, installed: int, unentitled: int, unavailable: int, failed: int, already: int}
     */
    private function resultOf(Process $process): array
    {
        $this->assertSame(
            0,
            $process->getExitCode(),
            "A concurrent installation run must exit successfully — losing a race is a no-op, not an error.\n"
                . "out: {$process->getOutput()}\nerr: {$process->getErrorOutput()}"
        );

        $matched = preg_match(
            '/RESULT abort=(\S+) installed=(\d+) unentitled=(\d+) unavailable=(\d+) failed=(\d+) already=(\d+)/',
            $process->getOutput(),
            $matches
        );

        $this->assertSame(1, $matched, 'The child must report a RESULT line: ' . $process->getOutput());

        return [
            'abort' => $matches[1],
            'installed' => (int) $matches[2],
            'unentitled' => (int) $matches[3],
            'unavailable' => (int) $matches[4],
            'failed' => (int) $matches[5],
            'already' => (int) $matches[6],
        ];
    }

    private function timestampOf(Process $process, string $marker): float
    {
        $matched = preg_match('/' . $marker . ' ([0-9.]+)/', $process->getOutput(), $matches);

        $this->assertSame(1, $matched, "The child must report a {$marker} timestamp: " . $process->getOutput());

        return (float) $matches[1];
    }

    /**
     * The whole point of §13.9's second half: one Business-owned row per
     * installed component, identified by the name the adapter wrote from the
     * component's payload, so a duplicate is visible per component rather than
     * only in a total.
     */
    private function assertExactlyOneOwnedRowPerInstalledComponent(Business $business): void
    {
        foreach (self::INSTALLABLE_COMPONENTS as $componentKey) {
            $this->assertSame(
                1,
                DB::table('crm_pipelines')->where('business_id', $business->id)->where('name', $componentKey)->count(),
                "Component [{$componentKey}] must own exactly one Business row after concurrent runs."
            );
        }

        foreach (self::UNAVAILABLE_COMPONENTS as $componentKey) {
            $this->assertSame(
                0,
                DB::table('crm_pipelines')->where('business_id', $business->id)->where('name', $componentKey)->count(),
                "Component [{$componentKey}] is Planned and must own no Business row at all."
            );
        }

        $this->assertSame(
            count(self::INSTALLABLE_COMPONENTS),
            $this->businessOwnedRowCount($business),
            'No Business-owned row may exist beyond one per installed component.'
        );
    }

    private function assertExactlyOneRecordPerComponent(Business $business): void
    {
        $records = $this->installationRecords($business);

        $this->assertCount(3, $records, 'Exactly one installation record per component, never a duplicate.');

        foreach (self::INSTALLABLE_COMPONENTS as $componentKey) {
            $this->assertSame(
                BlueprintComponentInstallationState::Installed,
                $records[$componentKey]->state,
                "Component [{$componentKey}] must end `installed`."
            );
            $this->assertSame('crm_pipeline', $records[$componentKey]->installed_record_type);
            $this->assertNotNull($records[$componentKey]->installed_record_id);
        }

        foreach (self::UNAVAILABLE_COMPONENTS as $componentKey) {
            $this->assertSame(
                BlueprintComponentInstallationState::SkippedUnavailable,
                $records[$componentKey]->state,
                "Component [{$componentKey}] must end `skipped_unavailable`."
            );
        }
    }

    /**
     * Every installation record as a plain array, so a re-run can be proved
     * byte-identical rather than merely "still installed".
     *
     * @return array<string, object>
     */
    private function recordSnapshot(Business $business): array
    {
        return DB::table('business_blueprint_component_installations')
            ->where('business_id', $business->id)
            ->orderBy('component_key')
            ->get()
            ->keyBy('component_key')
            ->all();
    }

    // ========================================================== scenario 1

    /**
     * §13.9 proper: two processes run a FULL installation for one Business at
     * the same time, released from a barrier once both have finished booting
     * so the collision is tight rather than hopeful.
     *
     * The two strongest assertions are the global ones: summed across both
     * processes, exactly two components were installed and exactly one was
     * skipped-unavailable. That is "every component was decided exactly once
     * in the whole system", which no single process's own report can show.
     */
    public function test_two_simultaneous_installation_runs_install_each_component_exactly_once(): void
    {
        [$business] = $this->tenantAwaitingInstallation();

        $barrier = $this->newBarrierFile();

        $first = $this->runner('install', (string) $business->id, $barrier);
        $second = $this->runner('install', (string) $business->id, $barrier);

        $first->start();
        $second->start();

        $this->awaitOutput($first, 'READY', 'The first run');
        $this->awaitOutput($second, 'READY', 'The second run');

        $this->releaseBarrier($barrier);

        $first->wait();
        $second->wait();

        $firstResult = $this->resultOf($first);
        $secondResult = $this->resultOf($second);

        $this->assertSame('-', $firstResult['abort'], 'Neither run may abort before its component loop.');
        $this->assertSame('-', $secondResult['abort'], 'Neither run may abort before its component loop.');

        // Both really were in flight together: the barrier releases them into
        // installForBusiness() within a fraction of a millisecond.
        $this->assertLessThan(
            0.5,
            abs($this->timestampOf($first, 'BEGIN') - $this->timestampOf($second, 'BEGIN')),
            'The two runs must genuinely overlap, not follow one another.'
        );

        // Decided exactly once each, across the whole system.
        $this->assertSame(
            2,
            $firstResult['installed'] + $secondResult['installed'],
            'Each entitled component must be installed exactly once across BOTH processes, never twice.'
        );
        $this->assertSame(
            1,
            $firstResult['unavailable'] + $secondResult['unavailable'],
            'The Planned component must be decided exactly once across BOTH processes.'
        );
        $this->assertSame(
            0,
            $firstResult['unentitled'] + $secondResult['unentitled'],
            'A Growth Workspace entitles every Available component in this Blueprint.'
        );
        $this->assertSame(
            0,
            $firstResult['failed'] + $secondResult['failed'],
            'Losing a race is a skip, never a recorded failure.'
        );

        // Neither run quietly dropped a component: whatever it did not decide
        // itself, it reported as already decided by the other.
        foreach (['first' => $firstResult, 'second' => $secondResult] as $label => $result) {
            $this->assertSame(
                3,
                $result['installed'] + $result['unentitled'] + $result['unavailable']
                    + $result['failed'] + $result['already'],
                "The {$label} run must account for all three components, decided by it or by the other run."
            );
        }

        $this->assertExactlyOneRecordPerComponent($business);
        $this->assertExactlyOneOwnedRowPerInstalledComponent($business);
    }

    // ========================================================== scenario 2

    /**
     * The deterministic version of scenario 1, with the direction forced.
     *
     * Process B is started and bootstrapped first but held at the barrier.
     * Process A then takes the `businesses` row lock — §7.2 step 1's exact
     * row — and announces it. Only then is B released, so B enters
     * `installForBusiness()` while A demonstrably holds the lock, and B's
     * first statement must BLOCK. A commits its whole installation; B wakes,
     * re-reads the record inside its own lock (§7.2 step 2), finds A's
     * committed decision and skips every component.
     *
     * "B genuinely waited" is proved from the timestamps rather than asserted
     * by hand: B began before A's transaction committed, and finished after
     * it. A run that had not blocked could not satisfy both.
     */
    public function test_a_run_that_loses_the_business_lock_observes_the_committed_record_and_skips(): void
    {
        [$business] = $this->tenantAwaitingInstallation();

        $barrier = $this->newBarrierFile();

        $loser = $this->runner('install', (string) $business->id, $barrier);
        $loser->start();
        $this->awaitOutput($loser, 'READY', 'The losing run');

        $winner = $this->runner(
            'hold-then',
            'businesses:id:' . $business->id,
            '1.5',
            'install',
            (string) $business->id
        );
        $winner->start();
        $this->awaitOutput($winner, 'LOCKED', 'The holding run');

        // B is fully booted and A provably holds the Business row lock.
        $this->releaseBarrier($barrier);

        $winner->wait();
        $loser->wait();

        $winnerResult = $this->resultOf($winner);
        $loserResult = $this->resultOf($loser);

        $this->assertSame('-', $winnerResult['abort']);
        $this->assertSame('-', $loserResult['abort']);

        $this->assertSame(2, $winnerResult['installed'], 'The lock holder installs both entitled components.');
        $this->assertSame(1, $winnerResult['unavailable'], 'The lock holder records the Planned skip.');
        $this->assertSame(0, $winnerResult['already'], 'The lock holder found nothing already decided.');

        $this->assertSame(0, $loserResult['installed'], 'The losing run must install nothing at all.');
        $this->assertSame(0, $loserResult['unavailable'], 'The losing run must not rewrite the Planned skip either.');
        $this->assertSame(0, $loserResult['failed'], 'The losing run must not record a failure.');
        $this->assertSame(
            3,
            $loserResult['already'],
            'The losing run must observe all three of the winner\'s committed records under its own lock.'
        );

        $committedAt = $this->timestampOf($winner, 'COMMITTED');

        $this->assertLessThan(
            $committedAt,
            $this->timestampOf($loser, 'BEGIN'),
            'The losing run must have entered installForBusiness() while the winner still held the lock.'
        );
        $this->assertGreaterThan(
            $committedAt,
            $this->timestampOf($loser, 'END'),
            'The losing run must only have finished after the winner committed — that is the block.'
        );

        $this->assertExactlyOneRecordPerComponent($business);
        $this->assertExactlyOneOwnedRowPerInstalledComponent($business);
    }

    // ========================================================== scenario 3

    /**
     * §13.7 under concurrency: once a Business is fully installed, two more
     * simultaneous runs must change literally nothing — not a state, not an
     * `installed_record_id`, not an `updated_at`, and above all not a second
     * `crm_pipelines` row.
     *
     * The whole record rows are compared, not just their states, because the
     * failure this guards against is a re-run that calls the adapter again and
     * repoints `installed_record_id` at a fresh duplicate row while leaving
     * the state reading `installed` exactly as before.
     */
    public function test_simultaneous_reruns_after_a_completed_install_change_nothing(): void
    {
        [$business] = $this->tenantAwaitingInstallation();

        $result = app(NicheBlueprintInstaller::class)->installForBusiness($business);

        $this->assertFalse($result->wasAborted(), 'The baseline installation must actually run.');
        $this->assertSame(2, $result->installed);
        $this->assertExactlyOneRecordPerComponent($business);
        $this->assertExactlyOneOwnedRowPerInstalledComponent($business);

        $recordsBefore = $this->recordSnapshot($business);
        $pipelineIdsBefore = DB::table('crm_pipelines')
            ->where('business_id', $business->id)->orderBy('id')->pluck('id')->all();

        // Second-granularity timestamps cannot show a rewrite that lands
        // within the same second, so the re-runs are pushed into a later one.
        sleep(1);

        $barrier = $this->newBarrierFile();

        $first = $this->runner('install', (string) $business->id, $barrier);
        $second = $this->runner('install', (string) $business->id, $barrier);

        $first->start();
        $second->start();

        $this->awaitOutput($first, 'READY', 'The first re-run');
        $this->awaitOutput($second, 'READY', 'The second re-run');

        $this->releaseBarrier($barrier);

        $first->wait();
        $second->wait();

        foreach ([$this->resultOf($first), $this->resultOf($second)] as $index => $rerun) {
            $this->assertSame('-', $rerun['abort'], "Re-run {$index} must reach its component loop.");
            $this->assertSame(0, $rerun['installed'], "Re-run {$index} must install nothing.");
            $this->assertSame(0, $rerun['unentitled'], "Re-run {$index} must write no skip.");
            $this->assertSame(0, $rerun['unavailable'], "Re-run {$index} must not rewrite the Planned skip.");
            $this->assertSame(0, $rerun['failed'], "Re-run {$index} must record no failure.");
            $this->assertSame(3, $rerun['already'], "Re-run {$index} must find all three components already decided.");
        }

        $this->assertEquals(
            $recordsBefore,
            $this->recordSnapshot($business),
            'Two concurrent re-runs must leave every installation record byte-identical, updated_at included.'
        );

        $this->assertSame(
            $pipelineIdsBefore,
            DB::table('crm_pipelines')->where('business_id', $business->id)->orderBy('id')->pluck('id')->all(),
            'Two concurrent re-runs must create no new Business-owned row and replace none.'
        );

        $this->assertExactlyOneRecordPerComponent($business);
        $this->assertExactlyOneOwnedRowPerInstalledComponent($business);
    }
}
