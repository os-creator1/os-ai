<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §9.2/§14 — two simultaneous backfill runs create
 * exactly one wallet per never-before-walleted Business; idempotent
 * full-rerun; partial-rerun safety. Deliberately does NOT use
 * RefreshDatabase, mirroring UsageWalletManagerConcurrencyTest's own
 * cross-process rationale. The runner script is generated at run time
 * into the real OS temp directory (never a repository file) and removed
 * in tearDown.
 */
class UsageWalletBackfillV1ConcurrencyTest extends TestCase
{
    private array $createdBusinessIds = [];
    private array $createdWorkspaceIds = [];
    private array $createdUserIds = [];
    private ?string $runnerPath = null;
    private ?int $createdCurrencyId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // This test class deliberately does not use RefreshDatabase (real
        // cross-process concurrency needs committed rows) — any Currency
        // row created here is real and permanent unless removed in
        // tearDown() below, tracked so exactly that row is removed.
        if (Currency::query()->where('code', 'USD')->count() === 0) {
            $this->createdCurrencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        }

        $this->runnerPath = sys_get_temp_dir().'/usage_wallet_backfill_concurrency_runner_'.uniqid().'.php';
        file_put_contents($this->runnerPath, $this->runnerScript());
    }

    protected function tearDown(): void
    {
        if ($this->runnerPath !== null && file_exists($this->runnerPath)) {
            @unlink($this->runnerPath);
        }

        if ($this->createdBusinessIds !== []) {
            DB::table('business_usage_wallets')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
        }

        if ($this->createdCurrencyId !== null) {
            DB::table('currencies')->where('id', $this->createdCurrencyId)->delete();
        }

        if ($this->createdWorkspaceIds !== []) {
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * The explicit database handoff every child process receives.
     *
     * Mirrors ConversationsConcurrencyTest::childEnvironment() exactly.
     * The parent resolves and VALIDATES the disposable database it is
     * itself connected to — TestDatabaseSafety::activeTestDatabase()
     * throws unless it is the canonical test database or a clearly
     * derived isolated sibling — and hands that exact name down under
     * both keys: DB_DATABASE so the child connects to it, and
     * EXPECTED_TEST_DATABASE so the child can prove it did.
     *
     * Relying on ambient inheritance alone would give the child the right
     * value but no proof of it; passing it explicitly means the child can
     * distinguish "the parent authorized this database" from "something
     * in my environment happened to point here".
     *
     * Symfony merges this into the inherited environment, so the child
     * still receives everything else it needs.
     *
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return [
            'DB_DATABASE' => $database,
            'EXPECTED_TEST_DATABASE' => $database,
        ];
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function runnerScript(): string
    {
        $vendorAutoload = str_replace('\\', '\\\\', base_path('vendor/autoload.php'));
        $bootstrapApp = str_replace('\\', '\\\\', base_path('bootstrap/app.php'));

        return <<<PHP
<?php
require '{$vendorAutoload}';
putenv('APP_ENV=testing');
\$_ENV['APP_ENV'] = 'testing';
\$_SERVER['APP_ENV'] = 'testing';
\$app = require '{$bootstrapApp}';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

// Fail-closed database guard, before the first database write.
//
// EXPECTED_TEST_DATABASE is MANDATORY. A missing value is not "no
// expectation" — it means the parent handoff did not happen, so this
// child cannot know which database it is authorized to write to and
// must refuse rather than silently fall back to the canonical name.
//
// Tests\Support\TestDatabaseSafety is the single authority on which
// names are permitted; it rejects empty, malformed, unsafe and
// production-looking values, and never accepts a name merely because it
// contains "test".
const WRONG_DATABASE_EXIT_CODE = 3;

\$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if (\$expectedDatabase === false || \$expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    \Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase(\$expectedDatabase);
} catch (\RuntimeException \$e) {
    fwrite(STDERR, 'Refusing to run: ' . \$e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    \$result = (new \App\Library\Usage\Migration\UsageWalletBackfillV1())->run();
    fwrite(STDOUT, "OK:{\$result->walletsCreated}\\n");
} catch (\App\Exceptions\Usage\UsageWalletBackfillIncompleteException \$e) {
    fwrite(STDOUT, "INCOMPLETE:{\$e->remainingUnwalletedCount}\\n");
}
PHP;
    }

    private function createUnwalletedBusiness(): int
    {
        $ownerId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner'.uniqid().'@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $ownerId;
        DB::table('customers')->insert(['user_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => 'Backfill Concurrency WS '.uniqid(), 'owner_user_id' => $ownerId,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdWorkspaceIds[] = $workspaceId;

        $coreCatalogId = DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');
        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $workspaceId, 'workspace_plan_catalog_id' => $coreCatalogId, 'status' => 'active',
            'is_complimentary' => true, 'additional_business_slots' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $businessId = DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $ownerId, 'workspace_id' => $workspaceId,
            'name' => 'Backfill Concurrency Biz', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'draft',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdBusinessIds[] = $businessId;

        return $businessId;
    }

    public function test_two_simultaneous_runs_create_exactly_one_wallet_per_business(): void
    {
        $businessId = $this->createUnwalletedBusiness();

        $runnerA = new Process([$this->phpBinary(), $this->runnerPath], null, $this->childEnvironment());
        $runnerB = new Process([$this->phpBinary(), $this->runnerPath], null, $this->childEnvironment());

        $runnerA->start();
        $runnerB->start();

        $runnerA->wait();
        $runnerB->wait();

        $this->assertSame(1, DB::table('business_usage_wallets')->where('business_id', $businessId)->count());

        // Between the two racing runs, exactly one wallet was created in
        // total across both outcomes combined (never zero, never two).
        $totalCreated = 0;
        foreach ([$runnerA, $runnerB] as $runner) {
            if (preg_match('/OK:(\d+)/', $runner->getOutput(), $m)) {
                $totalCreated += (int) $m[1];
            }
        }
        $this->assertSame(1, $totalCreated);
    }

    public function test_full_rerun_is_idempotent_with_zero_new_writes(): void
    {
        $businessId = $this->createUnwalletedBusiness();

        $first = new Process([$this->phpBinary(), $this->runnerPath], null, $this->childEnvironment());
        $first->run();
        $this->assertStringContainsString('OK:1', $first->getOutput());

        $countAfterFirst = DB::table('business_usage_wallets')->count();

        $second = new Process([$this->phpBinary(), $this->runnerPath], null, $this->childEnvironment());
        $second->run();
        $this->assertStringContainsString('OK:0', $second->getOutput());

        $this->assertSame($countAfterFirst, DB::table('business_usage_wallets')->count());
        $this->assertSame(1, DB::table('business_usage_wallets')->where('business_id', $businessId)->count());
    }
}
