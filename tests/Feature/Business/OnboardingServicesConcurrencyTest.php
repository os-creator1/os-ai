<?php

namespace Tests\Feature\Business;

use App\Library\Business\OnboardingManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Real concurrency for the Services step: three genuinely independent OS processes submit the identical step at
 * the same instant. Deliberately does NOT use RefreshDatabase (a child process can only see committed rows); the
 * rows it creates are removed in tearDown(), following EntitlementManagerConcurrencyTest's pattern.
 */
class OnboardingServicesConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__ . '/Support/concurrent_onboarding_services_runner.php';

    private ?int $userId = null;

    private array $businessIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('customer_onboardings')) {
            $this->artisan('migrate');
        }
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            $businessIds = DB::table('businesses')->where('customer_id', $this->userId)->pluck('id')->all();
            $workspaceIds = DB::table('workspaces')->where('owner_user_id', $this->userId)->pluck('id')->all();

            foreach (DB::select('SELECT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = "business_id"') as $row) {
                if ($businessIds !== []) {
                    DB::table($row->t)->whereIn('business_id', $businessIds)->delete();
                }
            }

            DB::table('customer_onboardings')->where('customer_id', $this->userId)->delete();
            DB::table('businesses')->whereIn('id', $businessIds)->delete();

            foreach (DB::select('SELECT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = "workspace_id"') as $row) {
                if ($workspaceIds !== []) {
                    DB::table($row->t)->whereIn('workspace_id', $workspaceIds)->delete();
                }
            }

            DB::table('workspaces')->whereIn('id', $workspaceIds)->delete();
            DB::table('customers')->where('user_id', $this->userId)->delete();
            DB::table('users')->where('id', $this->userId)->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        parent::tearDown();
    }

    public function test_three_identical_submissions_leave_one_service_one_primary_and_no_raw_exception(): void
    {
        [$customer, $business] = $this->customerAtServicesStep();

        $outputs = $this->race($customer, ['Photo Booth Rental', 'Photo Booth Rental', 'Photo Booth Rental']);

        $this->assertSame(['OK', 'OK', 'OK'], $outputs, 'Every submission succeeds; none surfaces a raw exception.');

        $services = DB::table('business_services')->where('business_id', $business->id)->get();
        $this->assertCount(1, $services, 'Exactly one Service per logical service.');
        $this->assertSame(1, $services->where('is_primary', 1)->count(), 'Exactly one primary.');
        $this->assertSame('active', $services->first()->status);
    }

    public function test_repeated_and_overlapping_submissions_of_different_services_stay_consistent(): void
    {
        [$customer, $business] = $this->customerAtServicesStep();

        $outputs = $this->race($customer, ['Photo Booth Rental', 'photo  booth rental', 'Audio Guestbook']);
        $this->assertSame(['OK', 'OK', 'OK'], $outputs);

        $normalised = DB::table('business_services')->where('business_id', $business->id)->pluck('name')
            ->map(fn (string $name) => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name))))->all();
        $this->assertSame(1, count(array_keys($normalised, 'photo booth rental', true)), 'One logical service, however it was spaced or cased.');
        $this->assertSame(1, DB::table('business_services')->where('business_id', $business->id)->where('is_primary', 1)->where('status', 'active')->count());

        // Safe repeated navigation / submit afterwards.
        $again = $this->race($customer, ['Photo Booth Rental']);
        $this->assertSame(['OK'], $again);
        $this->assertSame(1, DB::table('business_services')->where('business_id', $business->id)->where('is_primary', 1)->where('status', 'active')->count());
    }

    public function test_a_stale_services_submission_and_finish_cannot_deadlock_and_cannot_reopen_a_completed_onboarding(): void
    {
        [$customer, $business] = $this->customerAtFinishStep();
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $env = ['DB_DATABASE' => TestDatabaseSafety::activeTestDatabase(), 'EXPECTED_TEST_DATABASE' => TestDatabaseSafety::activeTestDatabase()];

        // A holder keeps the Business row locked so BOTH racing requests queue behind it before either can take a
        // second lock. With the old order (Services: Business then onboarding; Finish: onboarding then Business) this
        // forces the cycle; with one order (onboarding first) it cannot form.
        $holder = new Process([$php, self::RUNNER, 'hold-business', (string) $business->id, '5'], null, $env);
        $holder->setTimeout(120)->start();
        $deadline = microtime(true) + 30;
        while (! str_contains($holder->getOutput(), 'LOCKED') && microtime(true) < $deadline) {
            usleep(100000);
        }
        $this->assertStringContainsString('LOCKED', $holder->getOutput(), 'Precondition: the holder owns the Business lock.');

        // The Services request queues first, then Finish: with the old orders this is exactly the interleaving that
        // forms the cycle (Services holds Business and wants the onboarding row, Finish holds the onboarding row and
        // wants Business).
        $stale = new Process([$php, self::RUNNER, 'services', (string) $customer->user_id, (string) microtime(true), 'Photo Booth Rental'], null, $env);
        $stale->setTimeout(120)->start();
        usleep(1500000);
        $finish = new Process([$php, self::RUNNER, 'complete', (string) $customer->user_id, (string) microtime(true)], null, $env);
        $finish->setTimeout(120)->start();

        foreach ([$holder, $finish, $stale] as $process) {
            $process->wait();
        }

        $this->assertSame('OK', trim($finish->getOutput()), 'Finish completes with no rolled-back (deadlock-retried) attempt: ' . $finish->getErrorOutput());
        $this->assertSame('OK', trim($stale->getOutput()), 'The stale Services submission succeeds first time, with no deadlock victim or raw exception: ' . $stale->getErrorOutput());

        $onboarding = DB::table('customer_onboardings')->where('customer_id', $customer->user_id)->first();
        $this->assertSame('completed', $onboarding->status, 'Completed once, and not reopened.');
        $this->assertSame('finish', $onboarding->current_step, 'The stale request did not move the bookmark back.');
        $this->assertSame(['business', 'location', 'services', 'website'], json_decode($onboarding->completed_steps, true), 'The stale request did not overwrite the progress record.');
        $this->assertSame('active', DB::table('businesses')->where('id', $business->id)->value('status'));

        $services = DB::table('business_services')->where('business_id', $business->id)->get();
        $this->assertCount(1, $services, 'No duplicate Service or slug.');
        $this->assertSame(1, $services->where('is_primary', 1)->count());
    }

    public function test_finish_and_a_burst_of_identical_services_submissions_stay_consistent(): void
    {
        [$customer, $business] = $this->customerAtFinishStep();
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $env = ['DB_DATABASE' => TestDatabaseSafety::activeTestDatabase(), 'EXPECTED_TEST_DATABASE' => TestDatabaseSafety::activeTestDatabase()];
        $start = microtime(true) + 6.0;

        $processes = [
            new Process([$php, self::RUNNER, 'complete', (string) $customer->user_id, (string) $start], null, $env),
            new Process([$php, self::RUNNER, 'services', (string) $customer->user_id, (string) $start, 'Photo Booth Rental'], null, $env),
            new Process([$php, self::RUNNER, 'services', (string) $customer->user_id, (string) $start, 'Photo Booth Rental'], null, $env),
        ];

        foreach ($processes as $process) {
            $process->setTimeout(120)->start();
        }
        foreach ($processes as $process) {
            $process->wait();
        }

        $this->assertSame(['OK', 'OK', 'OK'], array_map(fn (Process $p) => trim($p->getOutput()) ?: 'NO-OUTPUT ' . $p->getErrorOutput(), $processes));
        $this->assertSame('completed', DB::table('customer_onboardings')->where('customer_id', $customer->user_id)->value('status'));
        $this->assertSame(1, DB::table('business_services')->where('business_id', $business->id)->where('is_primary', 1)->where('status', 'active')->count());
        $this->assertSame(1, DB::table('business_services')->where('business_id', $business->id)->count());
    }

    /** A customer with a Business, Location, one Service and "later" answered: ready to press Finish, on a paid plan. */
    private function customerAtFinishStep(): array
    {
        [$customer, $business] = $this->customerAtServicesStep();
        $manager = app(OnboardingManager::class);
        $onboarding = \App\Models\CustomerOnboarding::query()->where('customer_id', $customer->user_id)->firstOrFail();
        $onboarding = $manager->saveServicesStep($onboarding, $customer, [['name' => 'Photo Booth Rental', 'is_primary' => true]]);
        $manager->saveWebsiteStep($onboarding, $customer, 'later');

        // saveBusinessStep's legacy resolver already assigned the Workspace's plan; the account is usable.
        return [$customer, $business->fresh()];
    }

    /** @return array{0: Customer, 1: Business} */
    private function customerAtServicesStep(): array
    {
        $user = User::create(['first_name' => 'Race', 'last_name' => 'Customer', 'email' => 'race' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer']);
        $this->userId = (int) $user->id;
        $customer = Customer::create(['user_id' => $user->id]);

        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);
        $onboarding = $manager->saveBusinessStep($onboarding, $customer, ['name' => 'Race Co', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/Los_Angeles', 'currency_code' => 'USD']);
        $manager->saveLocationStep($onboarding, $customer, ['service_mode' => 'service_area', 'city' => 'Orange', 'region' => 'CA', 'country_code' => 'US', 'public_address' => false]);

        return [$customer, Business::query()->where('customer_id', $user->id)->firstOrFail()];
    }

    /**
     * @param  array<int, string>  $names  one process per name, all released at the same instant
     * @return array<int, string>
     */
    private function race(Customer $customer, array $names): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();
        $start = microtime(true) + 6.0;
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $processes = [];

        foreach ($names as $name) {
            $process = new Process([$php, self::RUNNER, 'services', (string) $customer->user_id, (string) $start, $name], null, ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database]);
            $process->setTimeout(120);
            $process->start();
            $processes[] = $process;
        }

        return array_map(function (Process $process): string {
            $process->wait();

            return trim($process->getOutput()) ?: 'NO-OUTPUT: ' . trim($process->getErrorOutput());
        }, $processes);
    }
}
