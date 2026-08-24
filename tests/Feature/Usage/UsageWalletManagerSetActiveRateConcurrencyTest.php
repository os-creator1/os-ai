<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * RFC-005 Amendment 1 Slice 2 CUTOVER §8, proofs 1-4 and 9 —
 * setActiveRate()'s bounded 3-attempt retry under genuine cross-process
 * contention, using the codebase's own established genuine-OS-process
 * concurrency technique (real, separate PHP processes booting the full
 * Laravel application, each holding a real, uncommitted transaction —
 * never simulated/mocked concurrency), mirroring
 * UsageWalletManagerConcurrencyTest.php's own pattern.
 *
 * Deliberately does NOT use RefreshDatabase — a genuinely separate OS
 * process needs committed rows, which an open RefreshDatabase transaction
 * would hide entirely. The runner script is generated at run time into
 * the real OS temp directory (never a repository file) and removed in
 * tearDown.
 *
 * Every "holder" process in this file plays the role of a concurrent
 * writer whose transaction is deliberately kept open (uncommitted) for a
 * controlled duration, to force a genuine InnoDB row/insert-intention
 * lock wait in the real production code under test (called directly, in
 * this same PHPUnit process, as the "waiter"). A plain, non-locking
 * `SELECT MAX(version)` never blocks on another transaction's uncommitted
 * insert (MVCC), so holding a competing insert open is the only way to
 * genuinely force setActiveRate()'s retry path — not a substitute for
 * real contention, but the real InnoDB mechanism ("duplicate-key errors
 * due to concurrent inserts") that a true production race would also hit.
 */
class UsageWalletManagerSetActiveRateConcurrencyTest extends TestCase
{
    private array $createdUserIds = [];
    private ?string $runnerPath = null;
    private ?int $createdCurrencyId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (Currency::query()->where('code', 'USD')->count() === 0) {
            $this->createdCurrencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        }

        $this->runnerPath = sys_get_temp_dir().'/usage_wallet_set_active_rate_concurrency_runner_'.uniqid().'.php';
        file_put_contents($this->runnerPath, $this->runnerScript());
    }

    protected function tearDown(): void
    {
        if ($this->runnerPath !== null && file_exists($this->runnerPath)) {
            @unlink($this->runnerPath);
        }

        if ($this->createdCurrencyId !== null) {
            $rateIds = DB::table('business_usage_rates')->where('currency_id', $this->createdCurrencyId)->pluck('id');
            $meterKeys = DB::table('usage_meters')->where('currency_id', $this->createdCurrencyId)->pluck('meter_key');

            // usage_meters.active_rate_id (meters_active_rate_foreign) and
            // usage_meter_transitions (umt_meter_key_foreign /
            // umt_from_rate_same_meter_foreign / umt_to_rate_same_meter_foreign)
            // both restrictOnDelete() against business_usage_rates and/or
            // usage_meters — cleared/deleted before either can be removed.
            DB::table('usage_meters')->where('currency_id', $this->createdCurrencyId)->update(['active_rate_id' => null]);
            DB::table('usage_meter_transitions')->whereIn('meter_key', $meterKeys)->delete();
            DB::table('business_usage_rate_activations')->whereIn('rate_id', $rateIds)->delete();
            DB::table('business_usage_rates')->where('currency_id', $this->createdCurrencyId)->delete();
            DB::table('usage_meters')->where('currency_id', $this->createdCurrencyId)->delete();
            DB::table('currencies')->where('id', $this->createdCurrencyId)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function escapePath(string $path): string
    {
        return str_replace('\\', '\\\\', $path);
    }

    private function createActorUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Rate', 'last_name' => 'Actor',
            'email' => 'rate-actor'.uniqid().'@example.test', 'status' => true, 'is_admin' => true,
            'is_customer' => false, 'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function createMeter(string $meterKey, string $featureKey, int $currencyId, int $actorId): void
    {
        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey,
            'feature_key' => $featureKey,
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'setActiveRate() concurrency fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);
    }

    /**
     * Three runner modes, all real, genuine OS-process behavior:
     *
     * - hold-meter-then-set-rate (proof 1): manually locks the target
     *   meter row (mirroring findForUpdateByMeterKey()), holds it, then
     *   calls the REAL setActiveRate() from inside that same held
     *   transaction (a harmless reentrant re-lock of a row this same
     *   connection already holds) before committing.
     * - hold-full-rate-then-commit (proofs 2/3): manually replicates
     *   setActiveRate()'s own writes (rate + activation + meter update)
     *   for one sibling meter, held open and uncommitted, so a
     *   concurrently-racing REAL setActiveRate() call for a *different*
     *   sibling meter genuinely collides on the shared legacy
     *   (feature_key, version) unique index.
     * - hold-bare-rate-then-commit (proof 4): holds open a single bare
     *   rate insert for one exact (feature_key, version) pair, nothing
     *   else — used three times, staggered, to force three consecutive
     *   collisions.
     */
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

if (\$mode === 'hold-meter-then-set-rate') {
    \$meterKey = \$argv[2];
    \$retailRateMicro = \$argv[3];
    \$currencyId = (int) \$argv[4];
    \$actorId = (int) \$argv[5];
    \$holdSeconds = (float) \$argv[6];

    \Illuminate\Support\Facades\DB::transaction(function () use (\$meterKey, \$retailRateMicro, \$currencyId, \$actorId, \$holdSeconds) {
        \Illuminate\Support\Facades\DB::table('usage_meters')->where('meter_key', \$meterKey)->lockForUpdate()->first();
        fwrite(STDOUT, "LOCKED\\n");
        fflush(STDOUT);
        usleep((int) (\$holdSeconds * 1000000));

        \$rate = app(\App\Library\Usage\UsageWalletManager::class)->setActiveRate(
            \$meterKey, \$retailRateMicro, '500000', 'per message', \$currencyId, \$actorId, 'Holder rotation.',
        );
        fwrite(STDOUT, "RATE_ID:{\$rate->id} VERSION:{\$rate->version}\\n");
    });
} elseif (\$mode === 'hold-full-rate-then-commit') {
    \$meterKey = \$argv[2];
    \$featureKey = \$argv[3];
    \$currencyId = (int) \$argv[4];
    \$actorId = (int) \$argv[5];
    \$holdSeconds = (float) \$argv[6];

    \Illuminate\Support\Facades\DB::transaction(function () use (\$meterKey, \$featureKey, \$currencyId, \$actorId, \$holdSeconds) {
        \Illuminate\Support\Facades\DB::table('usage_meters')->where('meter_key', \$meterKey)->lockForUpdate()->first();
        \$nextVersion = (int) (\Illuminate\Support\Facades\DB::table('business_usage_rates')->where('feature_key', \$featureKey)->max('version') ?? 0) + 1;
        \$now = now();

        \$rateId = \Illuminate\Support\Facades\DB::table('business_usage_rates')->insertGetId([
            'feature_key' => \$featureKey, 'meter_key' => \$meterKey, 'version' => \$nextVersion,
            'retail_rate_micro' => 1000000, 'provider_cost_micro' => 500000, 'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up', 'currency_id' => \$currencyId,
            'created_by_user_id' => \$actorId, 'created_at' => \$now,
        ]);

        \Illuminate\Support\Facades\DB::table('business_usage_rate_activations')->insert([
            'feature_key' => \$featureKey, 'meter_key' => \$meterKey, 'rate_id' => \$rateId,
            'activated_at' => \$now, 'activated_by_user_id' => \$actorId,
            'reason' => 'Holder rotation.', 'created_at' => \$now,
        ]);

        \Illuminate\Support\Facades\DB::table('usage_meters')->where('meter_key', \$meterKey)->update([
            'active_rate_id' => \$rateId, 'updated_by_user_id' => \$actorId, 'updated_at' => \$now,
        ]);

        fwrite(STDOUT, "LOCKED:RATE_ID:{\$rateId}:VERSION:{\$nextVersion}\\n");
        fflush(STDOUT);
        usleep((int) (\$holdSeconds * 1000000));
    });
    fwrite(STDOUT, "COMMITTED\\n");
} elseif (\$mode === 'hold-bare-rate-then-commit') {
    \$meterKey = \$argv[2];
    \$featureKey = \$argv[3];
    \$version = (int) \$argv[4];
    \$currencyId = (int) \$argv[5];
    \$actorId = (int) \$argv[6];
    \$holdSeconds = (float) \$argv[7];

    \Illuminate\Support\Facades\DB::transaction(function () use (\$meterKey, \$featureKey, \$version, \$currencyId, \$actorId, \$holdSeconds) {
        \Illuminate\Support\Facades\DB::table('business_usage_rates')->insert([
            'feature_key' => \$featureKey, 'meter_key' => \$meterKey, 'version' => \$version,
            'retail_rate_micro' => 1000000, 'provider_cost_micro' => 500000, 'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up', 'currency_id' => \$currencyId,
            'created_by_user_id' => \$actorId, 'created_at' => now(),
        ]);
        fwrite(STDOUT, "LOCKED\\n");
        fflush(STDOUT);
        usleep((int) (\$holdSeconds * 1000000));
    });
    fwrite(STDOUT, "COMMITTED\\n");
}
PHP;
    }

    /**
     * Proof 1 + proof 9 — same-meter concurrent rotations serialize
     * correctly, strictly increasing versions, no lost update; and
     * findForUpdateByMeterKey() genuinely locks its row (the holder's
     * manual pre-lock IS that same row-locking finder's own lock — the
     * waiter's real setActiveRate() call cannot proceed past its own
     * findForUpdateByMeterKey() call until the holder's transaction
     * commits).
     */
    public function test_same_meter_concurrent_rotations_serialize_with_strictly_increasing_versions_and_no_lost_update(): void
    {
        $currencyId = Currency::query()->where('code', 'USD')->value('id');
        $actorId = $this->createActorUserId();
        $meterKey = 'crm.rotate.'.uniqid();
        $this->createMeter($meterKey, 'crm', $currencyId, $actorId);

        $holder = new Process([
            $this->phpBinary(), $this->runnerPath, 'hold-meter-then-set-rate',
            $meterKey, '1000000', (string) $currencyId, (string) $actorId, '2',
        ]);
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

        $start = microtime(true);
        $waiterRate = app(UsageWalletManager::class)->setActiveRate(
            $meterKey, '2000000', '500000', 'per message', $currencyId, $actorId, 'Waiter rotation.',
        );
        $elapsed = microtime(true) - $start;

        $holder->wait();

        // The waiter genuinely blocked on findForUpdateByMeterKey()'s row
        // lock for close to the full hold duration — proof of real
        // contention, not sequential coincidence.
        $this->assertGreaterThan(1.0, $elapsed);

        $this->assertStringContainsString('RATE_ID', $holder->getOutput());
        preg_match('/RATE_ID:(\d+) VERSION:(\d+)/', $holder->getOutput(), $holderMatch);
        $holderRateId = (int) $holderMatch[1];
        $holderVersion = (int) $holderMatch[2];

        $this->assertSame(1, $holderVersion);
        $this->assertSame(2, $waiterRate->version);
        $this->assertSame(2, DB::table('business_usage_rates')->where('meter_key', $meterKey)->count());

        // No lost update: the meter's active_rate_id reflects the last
        // completed rotation (the waiter's), not the holder's.
        $meter = DB::table('usage_meters')->where('meter_key', $meterKey)->first();
        $this->assertSame($waiterRate->id, (int) $meter->active_rate_id);
        $this->assertNotSame($holderRateId, (int) $meter->active_rate_id);
    }

    /**
     * Proof 2 + proof 3 — two sibling UsageMeter rows sharing one legacy
     * feature_key race setActiveRate(); the loser genuinely collides on
     * business_usage_rates_feature_key_version_unique, retries, and
     * succeeds; both meters end up with their own durable, correctly
     * dual-written rate/activation and correct active_rate_id.
     */
    public function test_sibling_meters_sharing_one_feature_collide_and_the_loser_retries_and_succeeds(): void
    {
        $currencyId = Currency::query()->where('code', 'USD')->value('id');
        $actorId = $this->createActorUserId();
        $featureKey = 'crm';
        $meterKeyA = 'crm.sibling.a.'.uniqid();
        $meterKeyB = 'crm.sibling.b.'.uniqid();
        $this->createMeter($meterKeyA, $featureKey, $currencyId, $actorId);
        $this->createMeter($meterKeyB, $featureKey, $currencyId, $actorId);

        $holder = new Process([
            $this->phpBinary(), $this->runnerPath, 'hold-full-rate-then-commit',
            $meterKeyA, $featureKey, (string) $currencyId, (string) $actorId, '2',
        ]);
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

        preg_match('/LOCKED:RATE_ID:(\d+):VERSION:(\d+)/', $holder->getOutput(), $holderMatch);
        $holderRateId = (int) $holderMatch[1];
        $holderVersion = (int) $holderMatch[2];
        $this->assertSame(1, $holderVersion);

        $start = microtime(true);
        $loserRate = app(UsageWalletManager::class)->setActiveRate(
            $meterKeyB, '3000000', '500000', 'per message', $currencyId, $actorId, 'Waiter rotation.',
        );
        $elapsed = microtime(true) - $start;

        $holder->wait();

        // The loser genuinely blocked on the holder's uncommitted insert
        // (a real InnoDB duplicate-key insert-intention lock wait), then
        // collided, retried, and succeeded — not an immediate, contention-
        // free version(2) grant.
        $this->assertGreaterThan(1.0, $elapsed);
        $this->assertSame(2, $loserRate->version);

        // Proof 3 — both meters durably reflect their own genuinely
        // persisted rate/activation, correctly dual-written.
        $meterA = DB::table('usage_meters')->where('meter_key', $meterKeyA)->first();
        $meterB = DB::table('usage_meters')->where('meter_key', $meterKeyB)->first();
        $this->assertSame($holderRateId, (int) $meterA->active_rate_id);
        $this->assertSame($loserRate->id, (int) $meterB->active_rate_id);

        $this->assertDatabaseHas('business_usage_rates', [
            'id' => $holderRateId, 'feature_key' => $featureKey, 'meter_key' => $meterKeyA, 'version' => 1,
        ]);
        $this->assertDatabaseHas('business_usage_rates', [
            'id' => $loserRate->id, 'feature_key' => $featureKey, 'meter_key' => $meterKeyB, 'version' => 2,
        ]);
        $this->assertDatabaseHas('business_usage_rate_activations', [
            'rate_id' => $holderRateId, 'feature_key' => $featureKey, 'meter_key' => $meterKeyA,
        ]);
        $this->assertDatabaseHas('business_usage_rate_activations', [
            'rate_id' => $loserRate->id, 'feature_key' => $featureKey, 'meter_key' => $meterKeyB,
        ]);
    }

    /**
     * Proof 4 — a fixture that forces the unique-constraint collision on
     * all 3 attempts: three independent holders, each pre-holding one of
     * the three versions setActiveRate()'s successive attempts will
     * compute (1, then 2, then 3), staggered so each is still open when
     * the corresponding attempt's insert arrives. The original
     * QueryException propagates uncaught after the third attempt, and no
     * rate, activation, or meter update from any attempt is left
     * committed.
     */
    public function test_exact_collision_on_all_three_attempts_propagates_the_original_query_exception_with_no_partial_state(): void
    {
        $currencyId = Currency::query()->where('code', 'USD')->value('id');
        $actorId = $this->createActorUserId();
        $featureKey = 'crm';
        $meterKeyWaiter = 'crm.exhaust.waiter.'.uniqid();
        $meterKeyH1 = 'crm.exhaust.h1.'.uniqid();
        $meterKeyH2 = 'crm.exhaust.h2.'.uniqid();
        $meterKeyH3 = 'crm.exhaust.h3.'.uniqid();
        $this->createMeter($meterKeyWaiter, $featureKey, $currencyId, $actorId);
        $this->createMeter($meterKeyH1, $featureKey, $currencyId, $actorId);
        $this->createMeter($meterKeyH2, $featureKey, $currencyId, $actorId);
        $this->createMeter($meterKeyH3, $featureKey, $currencyId, $actorId);

        // Staggered, generous hold durations (each measured from its own
        // process's own lock confirmation) — large enough margins that
        // ordinary CI process-startup jitter cannot let a later holder
        // release before setActiveRate()'s corresponding attempt reaches
        // it.
        $holders = [];
        $holderSpecs = [
            [$meterKeyH1, 1, '6'],
            [$meterKeyH2, 2, '10'],
            [$meterKeyH3, 3, '14'],
        ];

        foreach ($holderSpecs as [$meterKey, $version, $holdSeconds]) {
            $holder = new Process([
                $this->phpBinary(), $this->runnerPath, 'hold-bare-rate-then-commit',
                $meterKey, $featureKey, (string) $version, (string) $currencyId, (string) $actorId, $holdSeconds,
            ]);
            $holder->start();

            $locked = false;
            $holder->waitUntil(function ($type, $output) use (&$locked) {
                if (str_contains($output, 'LOCKED')) {
                    $locked = true;

                    return true;
                }

                return false;
            });
            $this->assertTrue($locked, "Holder for version {$version} never confirmed its lock.");

            $holders[] = $holder;
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/business_usage_rates_feature_key_version_unique/');

        try {
            app(UsageWalletManager::class)->setActiveRate(
                $meterKeyWaiter, '9000000', '500000', 'per message', $currencyId, $actorId, 'Waiter rotation.',
            );
        } finally {
            foreach ($holders as $holder) {
                $holder->wait();
            }

            // No partial state from any of the three failed attempts —
            // every attempt's own transaction rolled back on its own
            // duplicate-key exception.
            $this->assertSame(0, DB::table('business_usage_rates')->where('meter_key', $meterKeyWaiter)->count());
            $this->assertSame(0, DB::table('business_usage_rate_activations')->where('meter_key', $meterKeyWaiter)->count());
            $waiterMeter = DB::table('usage_meters')->where('meter_key', $meterKeyWaiter)->first();
            $this->assertNull($waiterMeter->active_rate_id);
        }
    }
}
