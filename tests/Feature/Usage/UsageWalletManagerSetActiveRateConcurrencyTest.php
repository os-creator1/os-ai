<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Repositories\Contracts\BusinessUsageRateRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * RFC-005 Amendment 1 Slice 3 CONTRACT §9, proof 41 — same-meter
 * concurrent setActiveRate() calls still serialize correctly via
 * findForUpdateByMeterKey()'s row lock alone, now that the Slice 2
 * outer retry loop is gone (the only remaining contention is
 * genuinely single-resource: one meter row), using the codebase's own
 * established genuine-OS-process concurrency technique (real, separate
 * PHP processes booting the full Laravel application, each holding a
 * real, uncommitted transaction — never simulated/mocked concurrency),
 * mirroring UsageWalletManagerConcurrencyTest.php's own pattern.
 *
 * The Slice 2 sibling-meter collision/exhausted-retry tests that
 * previously lived in this file are removed: dropping
 * business_usage_rates_feature_key_version_unique means sibling meters
 * sharing one legacy feature can no longer collide at all, at any
 * concurrency level, so that code path no longer exists to test.
 *
 * Deliberately does NOT use RefreshDatabase — a genuinely separate OS
 * process needs committed rows, which an open RefreshDatabase transaction
 * would hide entirely. The runner script is generated at run time into
 * the real OS temp directory (never a repository file) and removed in
 * tearDown.
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
     * hold-meter-then-set-rate (proof 41's same-meter re-verification):
     * manually locks the target meter row (mirroring
     * findForUpdateByMeterKey()), holds it, then calls the REAL
     * setActiveRate() from inside that same held transaction (a
     * harmless reentrant re-lock of a row this same connection already
     * holds) before committing.
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
}
PHP;
    }

    /**
     * Proof 41 (re-verification) — same-meter concurrent rotations still
     * serialize correctly, strictly increasing versions, no lost update,
     * now via the final single-transaction setActiveRate() with no
     * retry loop; findForUpdateByMeterKey() genuinely locks its row (the
     * holder's manual pre-lock IS that same row-locking finder's own
     * lock — the waiter's real setActiveRate() call cannot proceed past
     * its own findForUpdateByMeterKey() call until the holder's
     * transaction commits).
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
     * Proof 41 — meter-local versioning, re-verified after Slice 3.
     * Before Slice 3, under the (now-retired) shared feature-wide
     * allocator, meter A and meter B — sharing one legacy feature_key —
     * built a real, constraint-consistent history: A -> v1, B -> v2,
     * A -> v3 (three sequential setActiveRate() calls, no repeated
     * version number, exactly the state Slice 2's own
     * UNIQUE(feature_key, version) constraint required). After Slice 3,
     * a new setActiveRate() call for meter B allocates via
     * latestVersionForMeter('B') — sees only B's own prior row (version
     * 2), ignores meter A's history entirely, and correctly becomes B's
     * own version 3 — identical in number to A's existing version 3,
     * legal only because UNIQUE(meter_key, version) scopes uniqueness
     * per meter. Neither meter's prior rows are renumbered or disturbed.
     */
    public function test_meter_local_allocator_lets_sibling_meters_reuse_a_version_number_after_slice_3(): void
    {
        $currencyId = Currency::query()->where('code', 'USD')->value('id');
        $actorId = $this->createActorUserId();
        $featureKey = 'crm';
        $meterKeyA = 'crm.local.a.'.uniqid();
        $meterKeyB = 'crm.local.b.'.uniqid();
        $this->createMeter($meterKeyA, $featureKey, $currencyId, $actorId);
        $this->createMeter($meterKeyB, $featureKey, $currencyId, $actorId);

        // A -> v1, B -> v2, A -> v3: the real, constraint-consistent
        // history the (now-retired) Slice 2 shared feature-wide
        // allocator would have produced from three sequential calls, no
        // repeated version number — reconstructed here via raw inserts
        // (final Slice 3 schema shape, meter_key only) since the current
        // code under test no longer implements that retired algorithm at
        // all and cannot itself reproduce this history.
        $now = now();
        $rateA1Id = DB::table('business_usage_rates')->insertGetId([
            'meter_key' => $meterKeyA, 'version' => 1, 'retail_rate_micro' => 1000000, 'provider_cost_micro' => 500000,
            'unit_label' => 'per message', 'rounding_rule' => 'round_half_up', 'currency_id' => $currencyId,
            'created_by_user_id' => $actorId, 'created_at' => $now,
        ]);
        $rateB1Id = DB::table('business_usage_rates')->insertGetId([
            'meter_key' => $meterKeyB, 'version' => 2, 'retail_rate_micro' => 2000000, 'provider_cost_micro' => 500000,
            'unit_label' => 'per message', 'rounding_rule' => 'round_half_up', 'currency_id' => $currencyId,
            'created_by_user_id' => $actorId, 'created_at' => $now,
        ]);
        $rateA2Id = DB::table('business_usage_rates')->insertGetId([
            'meter_key' => $meterKeyA, 'version' => 3, 'retail_rate_micro' => 3000000, 'provider_cost_micro' => 500000,
            'unit_label' => 'per message', 'rounding_rule' => 'round_half_up', 'currency_id' => $currencyId,
            'created_by_user_id' => $actorId, 'created_at' => $now,
        ]);
        DB::table('usage_meters')->where('meter_key', $meterKeyA)->update(['active_rate_id' => $rateA2Id]);
        DB::table('usage_meters')->where('meter_key', $meterKeyB)->update(['active_rate_id' => $rateB1Id]);

        $repository = app(BusinessUsageRateRepository::class);
        $this->assertSame(3, $repository->latestVersionForMeter($meterKeyA));
        $this->assertSame(2, $repository->latestVersionForMeter($meterKeyB));

        // After Slice 3: B's next rate allocates via latestVersionForMeter,
        // sees only B's own version 2, and becomes version 3 — identical
        // in number to A's existing version 3.
        $rateB2 = app(UsageWalletManager::class)->setActiveRate(
            $meterKeyB, '4000000', '500000', 'per message', $currencyId, $actorId, 'B rotation 2.',
        );
        $this->assertSame(3, $rateB2->version);

        // No historical row was renumbered or disturbed.
        $this->assertDatabaseHas('business_usage_rates', ['id' => $rateA1Id, 'meter_key' => $meterKeyA, 'version' => 1]);
        $this->assertDatabaseHas('business_usage_rates', ['id' => $rateB1Id, 'meter_key' => $meterKeyB, 'version' => 2]);
        $this->assertDatabaseHas('business_usage_rates', ['id' => $rateA2Id, 'meter_key' => $meterKeyA, 'version' => 3]);
        $this->assertDatabaseHas('business_usage_rates', ['id' => $rateB2->id, 'meter_key' => $meterKeyB, 'version' => 3]);
        $this->assertSame(
            4,
            DB::table('business_usage_rates')->whereIn('meter_key', [$meterKeyA, $meterKeyB])->count(),
        );

        $meterA = DB::table('usage_meters')->where('meter_key', $meterKeyA)->first();
        $meterB = DB::table('usage_meters')->where('meter_key', $meterKeyB)->first();
        $this->assertSame($rateA2Id, (int) $meterA->active_rate_id);
        $this->assertSame($rateB2->id, (int) $meterB->active_rate_id);
    }
}
