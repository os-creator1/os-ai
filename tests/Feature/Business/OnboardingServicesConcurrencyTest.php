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

        $this->assertSame(1, DB::table('business_services')->where('business_id', $business->id)->whereRaw('LOWER(name) = ?', ['photo booth rental'])->count());
        $this->assertSame(1, DB::table('business_services')->where('business_id', $business->id)->where('is_primary', 1)->where('status', 'active')->count());

        // Safe repeated navigation / submit afterwards.
        $again = $this->race($customer, ['Photo Booth Rental']);
        $this->assertSame(['OK'], $again);
        $this->assertSame(1, DB::table('business_services')->where('business_id', $business->id)->where('is_primary', 1)->where('status', 'active')->count());
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
            $process = new Process([$php, self::RUNNER, (string) $customer->user_id, (string) $start, $name], null, ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database]);
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
