<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §13 — two workers racing initial default-assignment
 * creation for the same Business resolve to exactly one row, mirroring
 * UsageWalletBackfillV1ConcurrencyTest.php's own established
 * two-simultaneous-runs pattern. Deliberately does NOT use
 * RefreshDatabase — real cross-process concurrency needs committed rows.
 * The runner script is generated at run time into the real OS temp
 * directory (never a repository file) and removed in tearDown.
 */
class PayerAssignmentConcurrencyTest extends TestCase
{
    private array $createdBusinessIds = [];
    private array $createdWorkspaceIds = [];
    private array $createdUserIds = [];
    private ?string $runnerPath = null;
    private ?int $createdCurrencyId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (Currency::query()->where('code', 'USD')->count() === 0) {
            $this->createdCurrencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        }

        $this->runnerPath = sys_get_temp_dir() . '/payer_assignment_concurrency_runner_' . uniqid() . '.php';
        file_put_contents($this->runnerPath, $this->runnerScript());
    }

    protected function tearDown(): void
    {
        if ($this->runnerPath !== null && file_exists($this->runnerPath)) {
            @unlink($this->runnerPath);
        }

        if ($this->createdBusinessIds !== []) {
            DB::table('business_payer_assignments')->whereIn('business_id', $this->createdBusinessIds)->delete();
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

        if ($this->createdCurrencyId !== null) {
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

\$businessId = (int) \$argv[1];

try {
    app(\App\Library\Usage\BillingProfileManager::class)->initializePayerAssignmentForBusiness(\$businessId);
    fwrite(STDOUT, "OK\\n");
} catch (\Throwable \$e) {
    fwrite(STDOUT, "FAIL:" . get_class(\$e) . "\\n");
}
PHP;
    }

    private function createUnassignedBusiness(): int
    {
        $ownerId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $ownerId;
        DB::table('customers')->insert(['user_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => 'Payer Concurrency WS ' . uniqid(), 'owner_user_id' => $ownerId,
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
            'name' => 'Payer Concurrency Biz', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'draft',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdBusinessIds[] = $businessId;

        return $businessId;
    }

    public function test_two_workers_racing_initial_assignment_resolve_to_exactly_one_row(): void
    {
        $businessId = $this->createUnassignedBusiness();

        $p1 = new Process([$this->phpBinary(), $this->runnerPath, (string) $businessId]);
        $p2 = new Process([$this->phpBinary(), $this->runnerPath, (string) $businessId]);

        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        $this->assertStringContainsString('OK', $p1->getOutput());
        $this->assertStringContainsString('OK', $p2->getOutput());

        $this->assertSame(1, DB::table('business_payer_assignments')->where('business_id', $businessId)->count());
    }
}
