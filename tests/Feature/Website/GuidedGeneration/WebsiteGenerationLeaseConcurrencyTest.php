<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Independent-review correction round 4 (item 1) — proves
 * WebsiteGenerationCoordinator::beginLease() is genuinely exclusive under
 * real concurrency, not merely under a single process's own sequential
 * ordering: two truly simultaneous callers for the SAME Website must
 * resolve to exactly one winner and one friendly, IMMEDIATE refusal —
 * never both winners, never a hang/timeout on the loser (the entire point
 * of using a non-blocking SELECT...FOR UPDATE inside beginLease() rather
 * than Cache::lock()->block()).
 *
 * Deliberately does NOT use RefreshDatabase — the real, independent child
 * processes below need a genuinely committed Website row, which an open
 * RefreshDatabase transaction would hide from them entirely, following
 * WebsiteGalleryUploadConcurrencyTest's own proven cross-process pattern.
 */
class WebsiteGenerationLeaseConcurrencyTest extends TestCase
{
    use CreatesBusinessTestData;

    private const RUNNER = __DIR__ . '/Support/concurrent_generation_lease_runner.php';

    private ?int $userId = null;

    private ?int $workspaceId = null;

    private ?int $businessId = null;

    private ?int $websiteId = null;

    protected function tearDown(): void
    {
        if ($this->websiteId !== null) {
            Website::where('id', $this->websiteId)->delete();
        }

        if ($this->businessId !== null) {
            DB::table('business_locations')->where('business_id', $this->businessId)->delete();
            DB::table('businesses')->where('id', $this->businessId)->delete();
        }

        if ($this->workspaceId !== null) {
            DB::table('workspace_memberships')->where('workspace_id', $this->workspaceId)->delete();
            DB::table('workspaces')->where('id', $this->workspaceId)->delete();
        }

        if ($this->userId !== null) {
            DB::table('customers')->where('user_id', $this->userId)->delete();
            DB::table('users')->where('id', $this->userId)->delete();
        }

        parent::tearDown();
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    /**
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return [
            'DB_DATABASE' => $database,
            'EXPECTED_TEST_DATABASE' => $database,
        ];
    }

    private function createMinimalWebsite(): Website
    {
        $user = User::create([
            'first_name' => 'Lease', 'last_name' => 'Owner',
            'email' => 'lease-owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $this->userId = $user->id;

        $customer = Customer::create(['user_id' => $user->id]);

        $workspace = Workspace::create(['name' => 'Lease Workspace', 'owner_user_id' => $user->id, 'is_active' => true]);
        $this->workspaceId = $workspace->id;

        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        DB::table('businesses')->where('id', $business->id)->update(['status' => 'active']);
        $this->businessId = $business->id;

        $website = Website::create(['business_id' => $business->id, 'name' => 'Lease Website', 'status' => 'draft']);
        $this->websiteId = $website->id;

        return $website->fresh();
    }

    public function test_two_simultaneous_lease_attempts_resolve_to_exactly_one_winner_and_an_immediate_refusal(): void
    {
        $website = $this->createMinimalWebsite();
        $env = $this->childEnvironment();

        $processA = new Process([
            $this->phpBinary(), self::RUNNER, 'lease', (string) $website->id, $env['DB_DATABASE'],
        ], null, $env, null, 30);

        $processB = new Process([
            $this->phpBinary(), self::RUNNER, 'lease', (string) $website->id, $env['DB_DATABASE'],
        ], null, $env, null, 30);

        // Started back-to-back, both still cold — neither process has
        // acquired the Website row lock yet when the other starts.
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputA = trim($processA->getOutput());
        $outputB = trim($processB->getOutput());

        $this->assertSame(0, $processA->getExitCode(), "Process A did not exit cleanly: {$processA->getErrorOutput()}");
        $this->assertSame(0, $processB->getExitCode(), "Process B did not exit cleanly: {$processB->getErrorOutput()}");

        $outcomes = [$outputA, $outputB];
        $winners = array_filter($outcomes, fn ($line) => str_starts_with($line, 'OK:'));
        $busy = array_filter($outcomes, fn ($line) => $line === 'BUSY');

        $this->assertCount(1, $winners, 'Exactly one of the two racing lease attempts must win — never both, never neither. Outcomes: ' . implode(' | ', $outcomes));
        $this->assertCount(1, $busy, 'The losing attempt must be an immediate, friendly refusal — never a hang or a raw lock-timeout exception. Outcomes: ' . implode(' | ', $outcomes));

        $winnerToken = substr(reset($winners), 3);
        $this->assertSame($winnerToken, $website->fresh()->generation_lease_token, 'The Website\'s committed lease token must match the one genuine winner.');
    }
}
