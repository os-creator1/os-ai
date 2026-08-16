<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
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

        $runnerA = new Process([$this->phpBinary(), $this->runnerPath]);
        $runnerB = new Process([$this->phpBinary(), $this->runnerPath]);

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

        $first = new Process([$this->phpBinary(), $this->runnerPath]);
        $first->run();
        $this->assertStringContainsString('OK:1', $first->getOutput());

        $countAfterFirst = DB::table('business_usage_wallets')->count();

        $second = new Process([$this->phpBinary(), $this->runnerPath]);
        $second->run();
        $this->assertStringContainsString('OK:0', $second->getOutput());

        $this->assertSame($countAfterFirst, DB::table('business_usage_wallets')->count());
        $this->assertSame(1, DB::table('business_usage_wallets')->where('business_id', $businessId)->count());
    }
}
