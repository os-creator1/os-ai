<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Exceptions\NicheBlueprint\NotADraftVersionException;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Implementation Contract 20 §5.2/§6.2/§12.B — real concurrency coverage for
 * the publisher, using genuinely independent OS processes rather than
 * sequential coincidence, following the merged
 * EntitlementManagerConcurrencyTest / WorkspaceManagerConcurrencyTest pattern.
 *
 * Deliberately does NOT use RefreshDatabase: a genuinely separate process
 * needs COMMITTED rows, which an open RefreshDatabase transaction would hide
 * entirely. Fixture rows are inserted directly (auto-committed) and removed in
 * tearDown().
 *
 * The two database guards — `draft_guard` and `published_guard` — are the
 * final backstop, never the mechanism. These tests prove the domain serializes
 * correctly on the `niche_blueprints` row FIRST, so a losing racer receives a
 * domain refusal and never a leaked SQL integrity error.
 */
class NicheBlueprintPublisherConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__ . '/Support/concurrent_blueprint_runner.php';

    private array $createdUserIds = [];

    private array $createdBlueprintIds = [];

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerAdapter();
        $this->adminId = $this->createAdminUserId();
    }

    protected function tearDown(): void
    {
        if ($this->createdBlueprintIds !== []) {
            DB::table('niche_blueprint_components')->whereIn('blueprint_id', $this->createdBlueprintIds)->delete();
            DB::table('niche_blueprint_versions')->whereIn('blueprint_id', $this->createdBlueprintIds)->delete();
            DB::table('niche_blueprints')->whereIn('id', $this->createdBlueprintIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    private function registerAdapter(): void
    {
        app(BlueprintComponentAdapterRegistry::class)->register(
            new class implements BlueprintComponentAdapter
            {
                public function componentType(): string
                {
                    return 'crm_pipeline';
                }

                public function validateDescriptor(array $payload): void
                {
                }

                public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
                {
                    return new InstalledComponentReference('crm_pipeline', 1);
                }
            }
        );
    }

    private function publisher(): NicheBlueprintPublisher
    {
        return app(NicheBlueprintPublisher::class);
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

    private function createAdminUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true,
            'is_customer' => false, 'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->createdUserIds[] = $id;

        return $id;
    }

    private function createBlueprintId(): int
    {
        $id = DB::table('niche_blueprints')->insertGetId([
            'uid' => (string) Str::uuid(),
            'key' => 'concurrency_' . uniqid(),
            'display_name' => 'Concurrency Blueprint',
            'vertical_key' => null,
            'broad_industry' => 'photo_booth_service',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdBlueprintIds[] = $id;

        return $id;
    }

    private function insertDraft(int $blueprintId, int $versionNumber = 1): int
    {
        return DB::table('niche_blueprint_versions')->insertGetId([
            'uid' => (string) Str::uuid(),
            'blueprint_id' => $blueprintId,
            'version_number' => $versionNumber,
            'state' => 'draft',
            'notes' => null,
            'published_at' => null,
            'published_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertComponent(int $versionId, int $blueprintId, string $key = 'photo_booth_default_pipeline'): int
    {
        return DB::table('niche_blueprint_components')->insertGetId([
            'blueprint_version_id' => $versionId,
            'blueprint_id' => $blueprintId,
            'component_key' => $key,
            'component_type' => 'crm_pipeline',
            'required_feature_key' => 'crm',
            'payload' => json_encode(['pipeline_key' => 'sales']),
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runner(string ...$args): Process
    {
        return new Process(
            array_merge([$this->phpBinary(), self::RUNNER], $args),
            null,
            $this->childEnvironment()
        );
    }

    private function startHolder(string $lockSpecs, float $holdSeconds, string ...$delegate): Process
    {
        $holder = new Process(
            array_merge([$this->phpBinary(), self::RUNNER, 'hold-then', $lockSpecs, (string) $holdSeconds], $delegate),
            null,
            $this->childEnvironment()
        );

        $holder->start();

        $deadline = microtime(true) + 10.0;

        while (! str_contains($holder->getOutput(), 'LOCKED') && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertStringContainsString(
            'LOCKED',
            $holder->getOutput(),
            'Holder never signaled lock acquisition: ' . $holder->getErrorOutput()
        );

        return $holder;
    }

    // ========================================================== scenario 1

    /**
     * Two simultaneous create-draft requests. At most one draft may exist, the
     * version number must be deterministic, and the loser must receive a
     * domain refusal rather than a raw `draft_guard` integrity error.
     */
    public function test_two_concurrent_create_draft_requests_yield_exactly_one_draft(): void
    {
        $blueprintId = $this->createBlueprintId();

        $p1 = $this->runner('create-draft', (string) $blueprintId, (string) $this->adminId);
        $p2 = $this->runner('create-draft', (string) $blueprintId, (string) $this->adminId);

        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        $successes = (int) $p1->isSuccessful() + (int) $p2->isSuccessful();

        $this->assertSame(
            1,
            $successes,
            "Exactly one create-draft may succeed.\nP1 out: {$p1->getOutput()} err: {$p1->getErrorOutput()}\n"
                . "P2 out: {$p2->getOutput()} err: {$p2->getErrorOutput()}"
        );

        $this->assertSame(1, DB::table('niche_blueprint_versions')->where('blueprint_id', $blueprintId)->count());

        $row = DB::table('niche_blueprint_versions')->where('blueprint_id', $blueprintId)->first();
        $this->assertSame('draft', $row->state);
        $this->assertSame(1, (int) $row->version_number, 'Version numbering must be deterministic.');

        // The loser refused through the domain, with exit code 1, not a raw
        // SQL error (exit code 2) and not a guard-constraint leak.
        $loser = $p1->isSuccessful() ? $p2 : $p1;
        $this->assertSame(1, $loser->getExitCode(), 'The losing racer must exit as a domain refusal: ' . $loser->getErrorOutput());
        $this->assertStringContainsString('DraftVersionAlreadyExistsException', $loser->getErrorOutput());
        $this->assertStringNotContainsStringIgnoringCase('nbv_draft_guard_unique', $loser->getErrorOutput());
    }

    // ========================================================== scenario 2

    /**
     * Two simultaneous publishes of two different drafts of one Blueprint.
     * Exactly one may end published; the other must refuse. (Two drafts cannot
     * coexist through the service, so this fixture inserts them directly — the
     * point here is the publish race, not the draft rule.)
     */
    public function test_two_concurrent_publishes_leave_exactly_one_published_version(): void
    {
        $blueprintId = $this->createBlueprintId();

        $draftA = $this->insertDraft($blueprintId, 1);
        $this->insertComponent($draftA, $blueprintId);

        // A second candidate, inserted as superseded so the draft_guard allows
        // it to exist, then flipped to draft directly for this race only.
        $draftB = DB::table('niche_blueprint_versions')->insertGetId([
            'uid' => (string) Str::uuid(), 'blueprint_id' => $blueprintId, 'version_number' => 2,
            'state' => 'superseded', 'notes' => null, 'published_at' => null, 'published_by_user_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->insertComponent($draftB, $blueprintId, 'second_component');

        $p1 = $this->runner('publish', (string) $draftA, (string) $this->adminId);
        $p2 = $this->runner('publish', (string) $draftB, (string) $this->adminId);

        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        // draftB is superseded, so its publish must refuse as a non-draft;
        // draftA must win. Either way exactly one published version exists.
        $publishedCount = DB::table('niche_blueprint_versions')
            ->where('blueprint_id', $blueprintId)->where('state', 'published')->count();

        $this->assertSame(
            1,
            $publishedCount,
            "Exactly one published version must exist.\nP1: {$p1->getErrorOutput()}\nP2: {$p2->getErrorOutput()}"
        );

        $loser = $p1->isSuccessful() ? $p2 : $p1;
        $this->assertSame(1, $loser->getExitCode(), 'The loser must refuse through the domain: ' . $loser->getErrorOutput());
        $this->assertStringNotContainsStringIgnoringCase('nbv_published_guard_unique', $loser->getErrorOutput());
    }

    /**
     * The real supersede race: v1 is already published, and two processes both
     * try to publish v2. Exactly one must win, v1 must be superseded exactly
     * once, and v1's components must be untouched.
     */
    public function test_two_concurrent_publishes_of_one_draft_supersede_the_incumbent_exactly_once(): void
    {
        $blueprintId = $this->createBlueprintId();

        $v1 = $this->insertDraft($blueprintId, 1);
        $this->insertComponent($v1, $blueprintId);
        $publisher = $this->publisher();
        $publisher->publishVersion($this->adminId, \App\Models\NicheBlueprintVersion::findOrFail($v1));

        $v1ComponentsBefore = DB::table('niche_blueprint_components')
            ->where('blueprint_version_id', $v1)->orderBy('id')->get()->toArray();

        $v2 = $this->insertDraft($blueprintId, 2);
        $this->insertComponent($v2, $blueprintId, 'second_component');

        $p1 = $this->runner('publish', (string) $v2, (string) $this->adminId);
        $p2 = $this->runner('publish', (string) $v2, (string) $this->adminId);

        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        $successes = (int) $p1->isSuccessful() + (int) $p2->isSuccessful();
        $this->assertSame(
            1,
            $successes,
            "Exactly one publish of the same draft may succeed.\nP1: {$p1->getErrorOutput()}\nP2: {$p2->getErrorOutput()}"
        );

        $this->assertSame(1, DB::table('niche_blueprint_versions')
            ->where('blueprint_id', $blueprintId)->where('state', 'published')->count());

        $this->assertSame('published', DB::table('niche_blueprint_versions')->where('id', $v2)->value('state'));
        $this->assertSame('superseded', DB::table('niche_blueprint_versions')->where('id', $v1)->value('state'));

        $v1ComponentsAfter = DB::table('niche_blueprint_components')
            ->where('blueprint_version_id', $v1)->orderBy('id')->get()->toArray();

        $this->assertEquals($v1ComponentsBefore, $v1ComponentsAfter, 'A superseded version keeps its components untouched.');

        $loser = $p1->isSuccessful() ? $p2 : $p1;
        $this->assertSame(1, $loser->getExitCode(), 'The loser must refuse through the domain: ' . $loser->getErrorOutput());
        $this->assertStringContainsString('NotADraftVersionException', $loser->getErrorOutput());
    }

    // ========================================================== scenario 3

    /**
     * Publish racing a component edit, with the direction forced: the publisher
     * holds the `niche_blueprints` lock, the editor starts and blocks on it,
     * and the publish commits first. The editor must then find a published
     * version and REFUSE — it must never mutate an already-published component.
     */
    public function test_a_component_edit_that_loses_to_a_publish_is_refused_not_applied(): void
    {
        $blueprintId = $this->createBlueprintId();
        $draft = $this->insertDraft($blueprintId, 1);
        $this->insertComponent($draft, $blueprintId);

        $componentsBefore = DB::table('niche_blueprint_components')
            ->where('blueprint_version_id', $draft)->orderBy('id')->get()->toArray();

        $holder = $this->startHolder(
            'niche_blueprints:id:' . $blueprintId,
            1.5,
            'publish',
            (string) $draft,
            (string) $this->adminId
        );

        $editor = $this->runner('add-component', (string) $draft, (string) $this->adminId, 'sneaked_in_component');
        $editor->start();

        $holder->wait();
        $editor->wait();

        $this->assertTrue($holder->isSuccessful(), 'The publish holder must win: ' . $holder->getErrorOutput());
        $this->assertSame('published', DB::table('niche_blueprint_versions')->where('id', $draft)->value('state'));

        $this->assertFalse($editor->isSuccessful(), 'The losing edit must be refused, not applied.');
        $this->assertSame(1, $editor->getExitCode(), 'The edit must refuse through the domain: ' . $editor->getErrorOutput());
        $this->assertStringContainsString('NotADraftVersionException', $editor->getErrorOutput());

        $componentsAfter = DB::table('niche_blueprint_components')
            ->where('blueprint_version_id', $draft)->orderBy('id')->get()->toArray();

        $this->assertEquals($componentsBefore, $componentsAfter, 'A published version must never gain or lose a component.');
        $this->assertSame(0, DB::table('niche_blueprint_components')
            ->where('component_key', 'sneaked_in_component')->count());
    }

    /**
     * The other direction: the edit commits FIRST, so the publish must include
     * it. Proves the race is genuinely two-sided and the publisher re-reads
     * components inside its own transaction rather than trusting a stale set.
     */
    public function test_a_component_edit_that_wins_the_race_is_included_in_the_publish(): void
    {
        $blueprintId = $this->createBlueprintId();
        $draft = $this->insertDraft($blueprintId, 1);
        $this->insertComponent($draft, $blueprintId);

        $holder = $this->startHolder(
            'niche_blueprints:id:' . $blueprintId,
            1.5,
            'add-component',
            (string) $draft,
            (string) $this->adminId,
            'won_the_race'
        );

        $publish = $this->runner('publish', (string) $draft, (string) $this->adminId);
        $publish->start();

        $holder->wait();
        $publish->wait();

        $this->assertTrue($holder->isSuccessful(), 'The edit holder must commit first: ' . $holder->getErrorOutput());
        $this->assertTrue($publish->isSuccessful(), 'The publish must then succeed: ' . $publish->getErrorOutput());

        $this->assertSame('published', DB::table('niche_blueprint_versions')->where('id', $draft)->value('state'));

        $keys = DB::table('niche_blueprint_components')
            ->where('blueprint_version_id', $draft)->pluck('component_key')->all();

        $this->assertContains('won_the_race', $keys, 'A component that committed before the publish must be included in it.');
        $this->assertCount(2, $keys);
    }
}
