<?php

namespace Tests\Feature\Payments;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\AgencyStripeConnection;
use App\Models\Business;
use App\Models\BusinessStripeConnection;
use App\Models\Customer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * REAL two-process concurrency for the shared Stripe-account claim (Business
 * lane B + Agency lane C). Each racer is a separate OS process on its own MySQL
 * connection (Support/stripe_account_claim_runner.php); only Stripe is faked.
 *
 * Not RefreshDatabase: separate processes need COMMITTED rows. Like
 * PaymentConcurrencyTest, one fresh schema is migrated for the class and
 * restored after it, and it must run against a validated disposable database
 * (TestDatabaseSafety) — preferably its own sibling, e.g.
 * DB_DATABASE=ultimatesms_testing_stripe_claims.
 *
 * Assertions are INVARIANTS that hold for every interleaving.
 */
class StripeAccountClaimConcurrencyTest extends TestCase
{
    use CreatesCustomerContextFixtures;

    private static bool $schemaPrepared = false;

    private static int $remaining = 0;

    private const START_GATE_MICROSECONDS = 4_000_000;

    private const MAX_ENTRY_SKEW_MICROSECONDS = 1_500_000;

    private string $callsFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(TestDatabaseSafety::activeTestDatabase(), DB::connection()->getDatabaseName());

        if (! self::$schemaPrepared) {
            $this->freshSchema();
            self::$schemaPrepared = true;
            self::$remaining = count(array_filter(get_class_methods(static::class), static fn (string $m) => str_starts_with($m, 'test_')));
        }

        $this->beforeApplicationDestroyed(function (): void {
            if (--self::$remaining <= 0) {
                $this->freshSchema();
                RefreshDatabaseState::$migrated = true;
                self::$schemaPrepared = false;
            }
        });

        $this->callsFile = tempnam(sys_get_temp_dir(), 'claim-calls-');
        $this->beforeApplicationDestroyed(fn () => @unlink($this->callsFile));
    }

    private function freshSchema(): void
    {
        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    /** @return array{0: Customer, 1: Business} */
    private function oauthBusiness(string $name): array
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, $name . ' ' . Str::random(5), $name . ' WS ' . Str::random(5));
        $business->status = BusinessStatus::Active;
        $business->country_code = 'US';
        $business->save();

        return [$customer, $business->fresh()];
    }

    private function account(): string
    {
        return 'acct_' . Str::lower(Str::random(14));
    }

    public function test_two_businesses_racing_for_one_account_yield_exactly_one_owner(): void
    {
        foreach (range(1, 2) as $round) {
            [$a, $bizA] = $this->oauthBusiness('Race A');
            [$b, $bizB] = $this->oauthBusiness('Race B');
            $account = $this->account();

            $results = $this->race([
                ['business', (string) $bizA->id, (string) $a->user_id, $account],
                ['business', (string) $bizB->id, (string) $b->user_id, $account],
            ]);

            $this->assertGenuinelyRaced($results);
            $codes = array_column($results, 'exitCode');
            sort($codes);
            $this->assertSame([0, 4], $codes, "Round {$round}: exactly one winner, one clean domain refusal.");
            $this->assertSame(1, BusinessStripeConnection::query()->where('stripe_account_id', $account)->count(), 'The loser left no row behind.');
            $this->assertSame(1, DB::table('stripe_account_claims')->where('stripe_account_id', $account)->count());
        }
    }

    public function test_a_business_and_an_agency_racing_for_one_account_yield_exactly_one_owner(): void
    {
        foreach (range(1, 2) as $round) {
            [$owner, $business] = $this->oauthBusiness('Cross lane');
            $pair = $this->createAgencyManagedClient(clientBusinessName: 'Agency Client ' . Str::random(4), agencyBusinessName: 'Primary ' . Str::random(4), agencyWorkspaceName: 'Agency ' . Str::random(4));
            $account = $this->account();

            $results = $this->race([
                ['business', (string) $business->id, (string) $owner->user_id, $account],
                ['agency', (string) $pair['agencyWorkspace']->id, (string) $pair['agencyOwner']->user_id, $account],
            ]);

            $this->assertGenuinelyRaced($results);
            $codes = array_column($results, 'exitCode');
            sort($codes);
            $this->assertSame([0, 4], $codes, "Round {$round}: exactly one lane owns the account.");

            $live = BusinessStripeConnection::query()->where('stripe_account_id', $account)->count()
                + AgencyStripeConnection::query()->where('stripe_account_id', $account)->count();
            $this->assertSame(1, $live, 'No second connection row in either lane.');
            $this->assertSame(1, DB::table('stripe_account_claims')->where('stripe_account_id', $account)->count());
        }
    }

    public function test_a_disconnecting_connection_never_revokes_after_a_competitor_has_won_the_account(): void
    {
        foreach (range(1, 3) as $round) {
            [$a, $bizA] = $this->oauthBusiness('Disconnector');
            [$b, $bizB] = $this->oauthBusiness('Competitor');
            $account = $this->account();

            // A owns the account first (sequential setup, then the race).
            $setup = $this->race([['business', (string) $bizA->id, (string) $a->user_id, $account]]);
            $this->assertSame([0], array_column($setup, 'exitCode'));
            $this->truncateCalls();

            $results = $this->race([
                ['disconnect', (string) $bizA->id, (string) $a->user_id],
                ['business', (string) $bizB->id, (string) $b->user_id, $account],
            ]);

            $this->assertGenuinelyRaced($results);
            $this->assertSame(0, $results[0]['exitCode'], 'The disconnect always completes.');
            $this->assertContains($results[1]['exitCode'], [0, 4]);

            $deauth = $this->stamps('deauth');
            $claimed = $this->stamps('claimed');
            $this->assertCount(1, $deauth, 'The grant is revoked exactly once.');

            if ($results[1]['exitCode'] === 0) {
                // The competitor won only AFTER A's revocation: A's claim was
                // held across the revoke, so a winner can never be revoked.
                $this->assertCount(1, $claimed);
                $this->assertLessThan($claimed[0], $deauth[0], 'Revocation happened before the competitor was able to claim.');
                $this->assertSame(1, BusinessStripeConnection::query()->where('business_id', $bizB->id)->where('status', '!=', 'disconnected')->count());
            }

            $this->assertSame('disconnected', BusinessStripeConnection::query()->where('business_id', $bizA->id)->sole()->status->value);
            $this->assertLessThanOrEqual(1, DB::table('stripe_account_claims')->where('stripe_account_id', $account)->count());
        }
    }

    // =================================================================
    // Harness
    // =================================================================

    /** @return array<int, string> */
    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database];
    }

    /**
     * @param  array<int, array<int, string>>  $operations
     * @return array<int, array{exitCode: int, stdout: string, stderr: string, enteredUs: int}>
     */
    private function race(array $operations): array
    {
        $runner = __DIR__ . '/Support/stripe_account_claim_runner.php';
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $startAt = (int) (microtime(true) * 1_000_000) + self::START_GATE_MICROSECONDS;

        $processes = [];

        foreach ($operations as $operation) {
            $command = array_merge([$php, $runner, array_shift($operation), (string) $startAt, $this->callsFile], $operation);
            $processes[] = new Process($command, null, $this->childEnvironment(), null, 90.0);
        }

        foreach ($processes as $process) {
            $process->start();
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            $stdout = $process->getOutput();

            $results[] = [
                'exitCode' => (int) $process->getExitCode(),
                'stdout' => $stdout,
                'stderr' => $process->getErrorOutput(),
                'enteredUs' => preg_match('/ENTERED (\d+)/', $stdout, $m) === 1 ? (int) $m[1] : 0,
            ];
        }

        foreach ($results as $index => $result) {
            $this->assertNotSame(3, $result['exitCode'], "Child {$index} refused an unexpected database: {$result['stderr']}");
            $this->assertNotSame(1, $result['exitCode'], "Child {$index} failed unexpectedly: {$result['stderr']}");
        }

        return $results;
    }

    private function assertGenuinelyRaced(array $results): void
    {
        $entries = array_column($results, 'enteredUs');

        foreach ($entries as $index => $entry) {
            $this->assertGreaterThan(0, $entry, "Child {$index} never entered the engine.");
        }

        $this->assertLessThan(self::MAX_ENTRY_SKEW_MICROSECONDS, max($entries) - min($entries), 'The children did not enter together, so this was not a race.');
    }

    private function truncateCalls(): void
    {
        file_put_contents($this->callsFile, '');
    }

    /** @return array<int, int> microsecond stamps of the named call lines */
    private function stamps(string $kind): array
    {
        $stamps = [];

        foreach (array_filter(array_map('trim', explode("\n", (string) file_get_contents($this->callsFile)))) as $line) {
            $parts = explode('|', $line);
            if ($parts[0] === $kind) {
                $stamps[] = (int) $parts[1];
            }
        }

        return $stamps;
    }
}
