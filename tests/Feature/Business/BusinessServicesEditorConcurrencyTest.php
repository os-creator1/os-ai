<?php

namespace Tests\Feature\Business;

use App\Library\Business\BusinessServiceManager;
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
 * Real concurrency for the Business Services editor: genuinely independent OS processes change one Business's services
 * at the same instant. Deliberately does NOT use RefreshDatabase (a child process can only see committed rows); the
 * rows it creates are removed in tearDown(), following EntitlementManagerConcurrencyTest's pattern.
 */
class BusinessServicesEditorConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__ . '/Support/concurrent_business_services_runner.php';

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

    public function test_identical_adds_in_parallel_leave_one_service_which_is_the_one_primary(): void
    {
        [$customer, $business] = $this->customerWithBusiness();

        $this->assertSame(['OK', 'OK', 'OK'], $this->race($customer, $business, [['add', 'Roof Repair'], ['add', 'roof  repair'], ['add', 'Roof Repair']]));

        $services = DB::table('business_services')->where('business_id', $business->id)->get();
        $this->assertCount(1, $services, 'One logical service, however it was spaced or cased; no slug collision.');
        $this->assertSame(1, $services->where('is_primary', 1)->where('status', 'active')->count());
    }

    public function test_mixed_changes_in_parallel_keep_exactly_one_active_primary_and_delete_nothing(): void
    {
        [$customer, $business] = $this->customerWithBusiness();
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            app(BusinessServiceManager::class)->add((int) $customer->user_id, $business, $name);
        }

        $outputs = $this->race($customer, $business, [['deactivate', 'Alpha'], ['primary', 'Gamma'], ['add', 'Delta'], ['deactivate', 'Beta'], ['add', 'Delta']]);
        $this->assertSame(array_fill(0, 5, 'OK'), $outputs);

        $services = DB::table('business_services')->where('business_id', $business->id)->get();
        $this->assertCount(4, $services, 'Nothing deleted, and Delta exists once.');
        $this->assertSame(1, $services->where('is_primary', 1)->count());
        $this->assertSame(1, $services->where('is_primary', 1)->where('status', 'active')->count(), 'The primary is always an active service.');
    }

    /** @return array{0: Customer, 1: Business} */
    private function customerWithBusiness(): array
    {
        $user = User::create(['first_name' => 'Race', 'last_name' => 'Customer', 'email' => 'race' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer']);
        $this->userId = (int) $user->id;
        $customer = Customer::create(['user_id' => $user->id]);

        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);
        $manager->saveBusinessStep($onboarding, $customer, ['name' => 'Race Co', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/Los_Angeles', 'currency_code' => 'USD']);

        return [$customer, Business::query()->where('customer_id', $user->id)->firstOrFail()];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $jobs  [mode, service name]; one process each, released together
     * @return array<int, string>
     */
    private function race(Customer $customer, Business $business, array $jobs): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();
        $start = microtime(true) + 6.0;
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $processes = [];

        foreach ($jobs as [$mode, $name]) {
            $process = new Process([$php, self::RUNNER, $mode, (string) $customer->user_id, (string) $business->id, (string) $start, $name], null, ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database]);
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
