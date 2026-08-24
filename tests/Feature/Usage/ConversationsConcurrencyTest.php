<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Milestone 5 §13 items 9-10, §3.8/§6/§E — same-token
 * concurrency: two genuinely independent OS processes racing
 * UsageWalletManager::reserve() with the identical business-namespaced
 * idempotency key resolve to exactly one created reservation. Only the
 * winner gets createdByThisInvocation = true; the loser refetches the
 * winner's own committed row after its own transaction rolls back,
 * never creating a second reservation and never (per §6/§H's own design)
 * proceeding to call the provider.
 *
 * Deliberately does NOT use RefreshDatabase — a genuinely separate OS
 * process needs committed rows, which an open RefreshDatabase transaction
 * would hide entirely (mirrors
 * UsageWalletManagerSetActiveRateConcurrencyTest.php's own established
 * precedent exactly). Fixture rows are cleaned up explicitly in
 * tearDown().
 */
class ConversationsConcurrencyTest extends TestCase
{
    use CreatesBusinessTestData;

    private array $createdBusinessIds = [];
    private array $createdUserIds = [];
    private array $createdWorkspaceIds = [];
    private ?int $createdCurrencyId = null;

    protected function tearDown(): void
    {
        if ($this->createdBusinessIds !== []) {
            $meterKeys = DB::table('usage_meters')->whereIn('business_id', $this->createdBusinessIds)->pluck('meter_key');
            DB::table('business_usage_ledger_entries')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_reservations')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('usage_meters')->whereIn('business_id', $this->createdBusinessIds)->update(['active_rate_id' => null]);
            DB::table('usage_meter_transitions')->whereIn('meter_key', $meterKeys)->delete();
            DB::table('business_usage_rate_activations')->whereIn('meter_key', $meterKeys)->delete();
            DB::table('business_usage_rates')->whereIn('meter_key', $meterKeys)->delete();
            DB::table('usage_meters')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_wallets')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
        }

        if ($this->createdWorkspaceIds !== []) {
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        if ($this->createdCurrencyId !== null) {
            DB::table('currencies')->where('id', $this->createdCurrencyId)->delete();
        }

        parent::tearDown();
    }

    /**
     * Matches UsageWalletManagerSetActiveRateConcurrencyTest.php's own
     * established precedent exactly: this class does not use
     * RefreshDatabase (a separate OS process needs committed rows), so a
     * blindly-created 'USD' Currency row would be a real, permanent
     * commit that poisons every other Usage test's own
     * resolveCurrencyId() ambiguous-match check for the rest of the
     * suite run. Reuse the existing row if one is already present;
     * create (and track for teardown) only if genuinely absent.
     */
    private function usdCurrencyId(): int
    {
        $existing = Currency::query()->where('code', 'M5T')->first();

        if ($existing !== null) {
            return $existing->id;
        }

        $this->createdCurrencyId = Currency::create(['name' => 'M5 Test Dollar', 'code' => 'M5T', 'format' => '$', 'status' => true])->id;

        return $this->createdCurrencyId;
    }

    public function test_same_idempotency_key_concurrent_reserve_calls_yield_exactly_one_reservation(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = User::create([
            'first_name' => 'Test', 'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test', 'status' => true,
            'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'M5T']));
        $this->createdBusinessIds[] = $business->id;
        $this->createdWorkspaceIds[] = $business->workspace_id;
        $this->createdUserIds[] = $customer->user_id;
        $this->createdUserIds[] = $actorId;

        $now = now();
        DB::table('business_usage_wallets')->insert([
            'business_id' => $business->id, 'currency_id' => $currencyId,
            'available_balance_micro' => 10_000_000, 'reserved_balance_micro' => 0, 'debt_balance_micro' => 0,
            'spend_period_key' => $now->format('Y-m'), 'spend_period_start_utc' => $now, 'spend_period_end_utc' => $now->clone()->addMonth(),
            'recharge_period_key' => $now->format('Y-m'), 'recharge_period_start_utc' => $now, 'recharge_period_end_utc' => $now->clone()->addMonth(),
            'billing_status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $meterKey = 'conversations.pilot.' . $business->id;
        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey, 'feature_key' => 'conversations', 'business_id' => $business->id,
            'currency_id' => $currencyId, 'description' => 'Concurrency fixture meter.', 'updated_by_user_id' => $actorId,
        ]);

        $manager = app(UsageWalletManager::class);
        $manager->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        $manager->activateMetering($meterKey, $actorId, 'Fixture.');

        $idempotencyKey = 'race-' . uniqid();
        $barrierFile = tempnam(sys_get_temp_dir(), 'm5_barrier_');
        $providerCallLogFile = tempnam(sys_get_temp_dir(), 'm5_provider_calls_');
        $runnerPath = __DIR__ . '/Support/concurrent_conversations_send_runner.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $processA = new Process([$phpBinary, $runnerPath, 'reserve', (string) $business->id, $meterKey, $idempotencyKey, '1', $barrierFile, '2', '10', $providerCallLogFile]);
        $processB = new Process([$phpBinary, $runnerPath, 'reserve', (string) $business->id, $meterKey, $idempotencyKey, '1', $barrierFile, '2', '10', $providerCallLogFile]);

        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        @unlink($barrierFile);

        $this->assertTrue($processA->isSuccessful(), $processA->getErrorOutput());
        $this->assertTrue($processB->isSuccessful(), $processB->getErrorOutput());

        preg_match('/granted=(\d) reservation_id=(\S+) created=(\d)/', $processA->getOutput(), $matchA);
        preg_match('/granted=(\d) reservation_id=(\S+) created=(\d)/', $processB->getOutput(), $matchB);

        $this->assertSame('1', $matchA[1]);
        $this->assertSame('1', $matchB[1]);
        $this->assertSame($matchA[2], $matchB[2], 'Both processes must resolve to the identical reservation id.');

        // Exactly one of the two created it.
        $createdFlags = [$matchA[3], $matchB[3]];
        sort($createdFlags);
        $this->assertSame(['0', '1'], $createdFlags);

        $this->assertSame(1, DB::table('business_usage_reservations')->where('idempotency_key', 'like', "%{$idempotencyKey}%")->count());

        // §6 step 4 — exactly one of the two processes ever reached the
        // point that would call the provider (the atomic-claim winner);
        // the race loser's own control flow never reaches it at all.
        $providerCallLines = array_values(array_filter(explode("\n", trim((string) file_get_contents($providerCallLogFile)))));
        @unlink($providerCallLogFile);

        $this->assertCount(1, $providerCallLines, 'Exactly one process must have recorded a provider-call marker.');
    }

    /**
     * RFC-005 Milestone 5 §3.8/§H — reserve()'s race-catch narrowly
     * matches only business_usage_reservations_idempotency_key_unique.
     * A genuine UniqueConstraintViolationException for a DIFFERENT
     * constraint must be rethrown, never swallowed as if it were the
     * same-key race. Proven with a controlled repository double bound
     * into the container for this test only, whose create() throws a
     * real UniqueConstraintViolationException (driver code 1062) whose
     * message names an unrelated constraint.
     */
    public function test_unrelated_unique_constraint_violation_is_not_swallowed_as_a_race(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = User::create([
            'first_name' => 'Test', 'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test', 'status' => true,
            'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'M5T']));
        $this->createdBusinessIds[] = $business->id;
        $this->createdWorkspaceIds[] = $business->workspace_id;
        $this->createdUserIds[] = $customer->user_id;
        $this->createdUserIds[] = $actorId;

        $now = now();
        DB::table('business_usage_wallets')->insert([
            'business_id' => $business->id, 'currency_id' => $currencyId,
            'available_balance_micro' => 10_000_000, 'reserved_balance_micro' => 0, 'debt_balance_micro' => 0,
            'spend_period_key' => $now->format('Y-m'), 'spend_period_start_utc' => $now, 'spend_period_end_utc' => $now->clone()->addMonth(),
            'recharge_period_key' => $now->format('Y-m'), 'recharge_period_start_utc' => $now, 'recharge_period_end_utc' => $now->clone()->addMonth(),
            'billing_status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $meterKey = 'conversations.pilot.' . $business->id;
        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey, 'feature_key' => 'conversations', 'business_id' => $business->id,
            'currency_id' => $currencyId, 'description' => 'Fixture meter.', 'updated_by_user_id' => $actorId,
        ]);
        app(UsageWalletManager::class)->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        app(UsageWalletManager::class)->activateMetering($meterKey, $actorId, 'Fixture.');

        $pdoException = new \PDOException("SQLSTATE[23000]: Duplicate entry 'x' for key 'some_unrelated_unique_constraint'");
        $pdoException->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'some_unrelated_unique_constraint'"];
        $queryException = new \Illuminate\Database\UniqueConstraintViolationException('mysql', 'insert into `business_usage_reservations` ...', [], $pdoException);

        $throwingRepository = new class($queryException) extends \App\Repositories\Eloquent\EloquentBusinessUsageReservationRepository {
            public function __construct(private \Illuminate\Database\UniqueConstraintViolationException $toThrow)
            {
                parent::__construct(new \App\Models\BusinessUsageReservation());
            }

            public function create(array $attributes): \App\Models\BusinessUsageReservation
            {
                throw $this->toThrow;
            }
        };

        $this->app->instance(\App\Repositories\Contracts\BusinessUsageReservationRepository::class, $throwingRepository);
        $manager = app(UsageWalletManager::class);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('some_unrelated_unique_constraint');

        try {
            $manager->reserve($business, $meterKey, 'distinct-' . uniqid(), '1');
        } finally {
            $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
        }
    }
}
