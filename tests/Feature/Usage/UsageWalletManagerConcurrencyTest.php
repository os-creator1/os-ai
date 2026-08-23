<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Repositories\Contracts\UsageMeterRepository;
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
    private ?string $signalPath = null;
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

        if ($this->signalPath !== null && file_exists($this->signalPath)) {
            @unlink($this->signalPath);
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
            $meterKeys = DB::table('usage_meters')->where('currency_id', $this->createdCurrencyId)->pluck('meter_key');
            // usage_meters.active_rate_id (meters_active_rate_foreign)
            // also restrictOnDelete()s against these rate rows
            // (setActiveRate() sets it) — must be cleared before the
            // rates themselves can be deleted.
            DB::table('usage_meters')->where('currency_id', $this->createdCurrencyId)->update(['active_rate_id' => null]);
            // usage_meter_transitions (activateMetering()'s audit row)
            // restrictOnDelete()s against both usage_meters (by
            // meter_key) and business_usage_rates (by its own
            // from/to_active_rate_id) — deleted before either.
            DB::table('usage_meter_transitions')->whereIn('meter_key', $meterKeys)->delete();
            DB::table('business_usage_rate_activations')->whereIn('rate_id', $rateIds)->delete();
            DB::table('business_usage_rates')->where('currency_id', $this->createdCurrencyId)->delete();
            DB::table('usage_meters')->where('currency_id', $this->createdCurrencyId)->delete();
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
} elseif (\$mode === 'hold-until-signal') {
    \$signalPath = \$argv[3];
    \$idempotencyKey = \$argv[4];
    \Illuminate\Support\Facades\DB::transaction(function () use (\$businessId, \$signalPath, \$idempotencyKey) {
        \Illuminate\Support\Facades\DB::table('business_usage_wallets')->where('business_id', \$businessId)->lockForUpdate()->first();
        fwrite(STDOUT, "LOCKED\\n");
        fflush(STDOUT);

        // Deadlock/safety-only ceiling: never the substantive proof. The
        // substantive proof is that this process cannot reach this point
        // until the parent has already observed Business B's reservation
        // complete (see the signal-write site in the test method).
        \$deadline = microtime(true) + 10.0;
        while (! file_exists(\$signalPath)) {
            if (microtime(true) >= \$deadline) {
                throw new \RuntimeException('Timed out waiting for the release signal.');
            }
            usleep(20000);
        }

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
        $currencyId = Currency::query()->where('code', 'USD')->value('id');

        // RFC-005 Amendment 1 Slice 2 CUTOVER §2's locked fixture
        // sequence: a genuine, disposable UsageMeter must exist before
        // setActiveRate() creates/activates a rate for it, and
        // activateMetering() must flip is_metered before reserve() will
        // accept it. Global (business_id null), since every business
        // fixture created by this helper races the same 'crm' meter.
        if (DB::table('usage_meters')->where('meter_key', 'crm')->doesntExist()) {
            app(UsageMeterRepository::class)->create([
                'meter_key' => 'crm',
                'feature_key' => 'crm',
                'business_id' => null,
                'currency_id' => $currencyId,
                'description' => 'Concurrency fixture meter.',
                'updated_by_user_id' => $adminId,
            ]);
        }

        app(UsageWalletManager::class)->setActiveRate(
            'crm', '1000000', '500000', 'per message', $currencyId, $adminId, 'Fixture.',
        );

        app(UsageWalletManager::class)->activateMetering('crm', $adminId, 'Fixture.');

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

        $this->signalPath = sys_get_temp_dir().'/usage_wallet_concurrency_signal_'.uniqid().'.flag';

        $holder = new Process([$this->phpBinary(), $this->runnerPath, 'hold-until-signal', (string) $businessIdA, $this->signalPath, 'holder-key-'.uniqid()]);
        $holder->setTimeout(12.0);

        try {
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

            // Business B's own wallet row is never locked by A's holder.
            // This process is run to completion synchronously, while A's
            // lock is still held, and only once it has genuinely returned
            // is A's release signal written below. If B were ever actually
            // serialized behind A's unrelated lock, this call could never
            // return — B would be blocked on a lock that A's own process
            // only releases after seeing a signal this parent only writes
            // after B has already returned. The deterministic proof here
            // is causal ordering, not a timing measurement: B's own
            // bounded process timeout is the only thing that can fail this
            // test, purely as a deadlock/safety net, never a fragile
            // wall-clock ceiling.
            $other = new Process([$this->phpBinary(), $this->runnerPath, 'reserve', (string) $businessIdB, 'other-key-'.uniqid()]);
            $other->setTimeout(12.0);
            $other->run();

            $this->assertTrue($other->isSuccessful(), 'Business B reservation process did not complete independently while Business A\'s unrelated lock was held: '.$other->getErrorOutput());
            $this->assertStringContainsString('GRANTED', $other->getOutput());
            $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $businessIdB)->count());

            file_put_contents($this->signalPath, '1');

            $holder->wait();
            $this->assertStringContainsString('GRANTED', $holder->getOutput());
        } finally {
            if ($holder->isRunning()) {
                $holder->stop();
            }

            if ($this->signalPath !== null && file_exists($this->signalPath)) {
                @unlink($this->signalPath);
            }
        }
    }
}
