<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §8 item 7/§14 — two workers racing reserve() against
 * the same wallet for the final remaining available balance resolve to
 * exactly one winner (mirroring EntitlementManagerConcurrencyTest.php's
 * controlled lock-hold pattern); a simultaneous reserve() for a different
 * Business's wallet succeeds independently and is unaffected.
 *
 * Deliberately does NOT use RefreshDatabase for the racing assertions — a
 * genuinely separate OS process needs committed rows, which an open
 * RefreshDatabase transaction would hide entirely. The runner script is
 * generated at run time into the real OS temp directory (never a
 * repository file — the M1 contract's allowlist authorizes no Support
 * script) and removed in tearDown.
 */
class UsageWalletManagerConcurrencyTest extends TestCase
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
        // cross-process concurrency needs committed rows), so any
        // Currency row it creates here is a real, permanently committed
        // row unless explicitly removed in tearDown() below — left
        // uncleaned, it would silently leak into every later
        // RefreshDatabase-based test in the same process, turning their
        // own single-row USD lookups into a false "ambiguous" (2 matches)
        // failure. Only ever create one if none exists yet, and track it
        // so tearDown() can remove exactly that row.
        if (Currency::query()->where('code', 'USD')->count() === 0) {
            $this->createdCurrencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        }

        $this->runnerPath = sys_get_temp_dir().'/usage_wallet_concurrency_runner_'.uniqid().'.php';
        file_put_contents($this->runnerPath, $this->runnerScript());
    }

    protected function tearDown(): void
    {
        if ($this->runnerPath !== null && file_exists($this->runnerPath)) {
            @unlink($this->runnerPath);
        }

        if ($this->createdBusinessIds !== []) {
            DB::table('business_usage_ledger_entries')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_reservations')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_wallets')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
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

        // Deleted last: business_usage_rates (created by setActiveRate()
        // in createBusinessWithWallet()) and the wallets/ledger entries
        // above all restrictOnDelete() against this currency row.
        if ($this->createdCurrencyId !== null) {
            $rateIds = DB::table('business_usage_rates')->where('currency_id', $this->createdCurrencyId)->pluck('id');
            // platform_feature_usage_classifications.active_rate_id also
            // restrictOnDelete()s against these rate rows (setActiveRate()
            // sets it) — must be cleared before the rates themselves can
            // be deleted.
            DB::table('platform_feature_usage_classifications')->whereIn('active_rate_id', $rateIds)->update(['active_rate_id' => null]);
            DB::table('business_usage_rate_activations')->whereIn('rate_id', $rateIds)->delete();
            DB::table('business_usage_rates')->where('currency_id', $this->createdCurrencyId)->delete();
            DB::table('currencies')->where('id', $this->createdCurrencyId)->delete();
        }

        parent::tearDown();
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function runnerScript(): string
    {
        $vendorAutoload = base_path('vendor/autoload.php');
        $bootstrapApp = base_path('bootstrap/app.php');

        return <<<PHP
<?php
require '{$this->escapePath($vendorAutoload)}';
putenv('APP_ENV=testing');
\$_ENV['APP_ENV'] = 'testing';
\$_SERVER['APP_ENV'] = 'testing';
\$app = require '{$this->escapePath($bootstrapApp)}';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

\$mode = \$argv[1];
\$businessId = (int) \$argv[2];

function doReserve(int \$businessId, string \$idempotencyKey): void
{
    \$business = \App\Models\Business::find(\$businessId);
    \$manager = app(\App\Library\Usage\UsageWalletManager::class);
    \$result = \$manager->reserve(\$business, 'crm', \$idempotencyKey, '1');
    fwrite(STDOUT, \$result->granted ? "GRANTED\\n" : "DENIED:{\$result->denialReason}\\n");
}

if (\$mode === 'hold-then-reserve') {
    \$holdSeconds = (float) \$argv[3];
    \$idempotencyKey = \$argv[4];
    \Illuminate\Support\Facades\DB::transaction(function () use (\$businessId, \$holdSeconds, \$idempotencyKey) {
        \Illuminate\Support\Facades\DB::table('business_usage_wallets')->where('business_id', \$businessId)->lockForUpdate()->first();
        fwrite(STDOUT, "LOCKED\\n");
        fflush(STDOUT);
        usleep((int) (\$holdSeconds * 1000000));
        doReserve(\$businessId, \$idempotencyKey);
    });
} elseif (\$mode === 'reserve') {
    doReserve(\$businessId, \$argv[3]);
}
PHP;
    }

    private function escapePath(string $path): string
    {
        return str_replace('\\', '\\\\', $path);
    }

    private function createAdminUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin'.uniqid().'@example.test', 'status' => true, 'is_admin' => true,
            'is_customer' => false, 'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function createBusinessWithWallet(int $availableBalanceMicro): int
    {
        $ownerId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner'.uniqid().'@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $ownerId;
        DB::table('customers')->insert(['user_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => 'Concurrency WS '.uniqid(), 'owner_user_id' => $ownerId,
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
            'name' => 'Concurrency Biz', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'draft',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdBusinessIds[] = $businessId;

        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessId);

        $adminId = $this->createAdminUserId();
        app(UsageWalletManager::class)->setActiveRate(
            'crm', '1000000', '500000', 'per message', Currency::query()->where('code', 'USD')->value('id'), $adminId, 'Fixture.',
        );

        DB::table('business_usage_wallets')->where('business_id', $businessId)
            ->update(['available_balance_micro' => $availableBalanceMicro]);

        return $businessId;
    }

    public function test_two_workers_racing_the_final_balance_resolve_to_exactly_one_winner(): void
    {
        // Balance covers exactly one 1,000,000-micro reservation.
        $businessId = $this->createBusinessWithWallet(1_000_000);

        $holder = new Process([$this->phpBinary(), $this->runnerPath, 'hold-then-reserve', (string) $businessId, '2', 'holder-key-'.uniqid()]);
        $holder->start();

        $locked = false;
        $holder->waitUntil(function ($type, $output) use (&$locked) {
            if (str_contains($output, 'LOCKED')) {
                $locked = true;

                return true;
            }

            return false;
        });
        $this->assertTrue($locked, 'Holder process never confirmed its lock.');

        $waiter = new Process([$this->phpBinary(), $this->runnerPath, 'reserve', (string) $businessId, 'waiter-key-'.uniqid()]);
        $start = microtime(true);
        $waiter->run();
        $elapsed = microtime(true) - $start;

        $holder->wait();

        // The waiter genuinely blocked on the row lock for close to the
        // full hold duration — proof of real contention, not sequential
        // coincidence.
        $this->assertGreaterThan(1.0, $elapsed);

        $this->assertStringContainsString('GRANTED', $holder->getOutput());
        $this->assertStringContainsString('DENIED:insufficient_balance', $waiter->getOutput());

        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $businessId)->count());
    }

    public function test_concurrent_reserve_for_a_different_business_is_unaffected(): void
    {
        $businessIdA = $this->createBusinessWithWallet(1_000_000);
        $businessIdB = $this->createBusinessWithWallet(1_000_000);

        $holder = new Process([$this->phpBinary(), $this->runnerPath, 'hold-then-reserve', (string) $businessIdA, '2', 'holder-key-'.uniqid()]);
        $holder->start();

        $locked = false;
        $holder->waitUntil(function ($type, $output) use (&$locked) {
            if (str_contains($output, 'LOCKED')) {
                $locked = true;

                return true;
            }

            return false;
        });
        $this->assertTrue($locked);

        // Business B's own wallet row is never locked by A's holder —
        // this must complete quickly, unaffected by A's contention.
        $other = new Process([$this->phpBinary(), $this->runnerPath, 'reserve', (string) $businessIdB, 'other-key-'.uniqid()]);
        $start = microtime(true);
        $other->run();
        $elapsed = microtime(true) - $start;

        $holder->wait();

        $this->assertLessThan(1.0, $elapsed, 'Business B reservation was blocked by Business A\'s unrelated lock.');
        $this->assertStringContainsString('GRANTED', $other->getOutput());
        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $businessIdB)->count());
    }
}
