<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsitePage;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Independent-review correction round 5 (item 1) — proves
 * WebsiteGenerationCoordinator::runExclusive() actually closes the check-
 * then-mutate race round 4's assertNotLeased() left open: the Website row
 * lock is now held across the WHOLE mutation, not merely a check released
 * before it. Two true, independent-process directions, for each of three
 * representative Studio mutations (general asset upload, Gallery page
 * mutation, a direct draft-page update):
 *
 *   A. The mutation acquires the Website row lock first. A concurrent
 *      beginLease() call must genuinely BLOCK on MySQL's own row lock
 *      until the mutation's transaction commits — never interleave with
 *      it, never silently proceed first.
 *   B. A generation lease is already active when the mutation starts. The
 *      mutation must refuse immediately, before performing any DB or
 *      filesystem write at all.
 *
 * Deliberately does NOT use RefreshDatabase — the real, independent child
 * processes below need genuinely committed rows, which an open
 * RefreshDatabase transaction would hide from them entirely, following
 * WebsiteGenerationLeaseConcurrencyTest's own proven cross-process
 * pattern.
 */
class WebsiteStudioMutationConcurrencyTest extends TestCase
{
    use CreatesBusinessTestData;

    private const RUNNER = __DIR__ . '/Support/concurrent_studio_mutation_runner.php';

    private ?int $userId = null;

    private ?int $workspaceId = null;

    private ?int $businessId = null;

    private ?int $websiteId = null;

    protected function tearDown(): void
    {
        if ($this->websiteId !== null) {
            $uid = Website::where('id', $this->websiteId)->value('uid');
            WebsiteAsset::where('website_id', $this->websiteId)->delete();
            WebsitePage::where('website_id', $this->websiteId)->delete();
            Website::where('id', $this->websiteId)->delete();

            if ($uid !== null) {
                $directory = public_path("images/websites/{$uid}");
                if (is_dir($directory)) {
                    array_map('unlink', glob("{$directory}/*") ?: []);
                    @rmdir($directory);
                }
            }
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
            'first_name' => 'Studio', 'last_name' => 'Race',
            'email' => 'studio-race' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $this->userId = $user->id;

        $customer = Customer::create(['user_id' => $user->id]);

        $workspace = Workspace::create(['name' => 'Studio Race Workspace', 'owner_user_id' => $user->id, 'is_active' => true]);
        $this->workspaceId = $workspace->id;

        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        DB::table('businesses')->where('id', $business->id)->update(['status' => 'active']);
        $this->businessId = $business->id;

        $website = Website::create(['business_id' => $business->id, 'name' => 'Studio Race Website', 'status' => 'draft']);
        $this->websiteId = $website->id;

        return $website->fresh();
    }

    private function process(array $args, array $env): Process
    {
        return new Process([$this->phpBinary(), self::RUNNER, ...$args, $env['DB_DATABASE']], null, $env, null, 30);
    }

    private function parseLine(string $output): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output))));
        $outcomeLine = end($lines) ?: '';
        [$status, $time] = array_pad(explode(':', $outcomeLine, 2), 2, null);

        return ['status' => $status, 'time' => $time !== null ? (float) $time : null, 'raw' => $outcomeLine];
    }

    /**
     * Blocks until the mutation process has printed its "LOCKED:" signal
     * — i.e. until it has genuinely acquired the real MySQL row lock
     * inside runExclusive(), not merely been started. This is what makes
     * "the mutation owns the row first" a deterministic precondition for
     * the test, rather than a coin flip on which child process happens
     * to finish booting Laravel first.
     */
    private function waitForLockedSignal(Process $process, float $timeoutSeconds = 10): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (str_contains($process->getIncrementalOutput(), 'LOCKED:')) {
                return;
            }

            if (! $process->isRunning()) {
                $this->fail("Mutation process exited before signaling it held the lock. Output: {$process->getOutput()} / Errors: {$process->getErrorOutput()}");
            }

            usleep(10_000);
        }

        $this->fail('Timed out waiting for the mutation process to signal it held the Website row lock.');
    }

    // ----------------------------------------------------------------
    // General asset upload
    // ----------------------------------------------------------------

    public function test_asset_upload_owning_the_row_first_makes_generation_wait_until_it_commits(): void
    {
        $website = $this->createMinimalWebsite();
        $env = $this->childEnvironment();

        $mutation = $this->process(['upload-asset', (string) $website->id, '1.5'], $env);
        $lease = $this->process(['lease', (string) $website->id, '0'], $env);

        $mutation->start();
        $this->waitForLockedSignal($mutation);
        $lease->start();
        $mutation->wait();
        $lease->wait();

        $this->assertSame(0, $mutation->getExitCode(), "Mutation process failed: {$mutation->getErrorOutput()}");
        $this->assertSame(0, $lease->getExitCode(), "Lease process failed: {$lease->getErrorOutput()}");

        $mutationResult = $this->parseLine($mutation->getOutput());
        $leaseResult = $this->parseLine($lease->getOutput());

        $this->assertSame('OK', $mutationResult['status'], 'The mutation must succeed: ' . $mutation->getOutput());
        $this->assertSame('OK', $leaseResult['status'], "beginLease() must succeed once the mutation's transaction commits, never refuse: " . $lease->getOutput());
        $this->assertGreaterThan($mutationResult['time'], $leaseResult['time'], 'beginLease() must genuinely BLOCK until the mutation commits — it must never complete before the mutation does.');

        $this->assertSame(1, WebsiteAsset::where('website_id', $website->id)->count(), 'The uploaded asset must be committed.');
        $this->assertNotNull($website->fresh()->generation_lease_token, 'The lease acquired after the mutation committed must still be active.');
    }

    public function test_asset_upload_refuses_immediately_while_a_generation_lease_is_already_active(): void
    {
        $website = $this->createMinimalWebsite();
        $env = $this->childEnvironment();

        $lease = $this->process(['lease', (string) $website->id, '1.5'], $env);
        $mutation = $this->process(['upload-asset', (string) $website->id, '0'], $env);

        $lease->start();
        usleep(200_000); // let the lease genuinely commit before the mutation even attempts its own lock acquisition
        $mutation->start();
        $lease->wait();
        $mutation->wait();

        $this->assertSame(0, $lease->getExitCode(), "Lease process failed: {$lease->getErrorOutput()}");
        $this->assertSame(0, $mutation->getExitCode(), "Mutation process failed: {$mutation->getErrorOutput()}");

        $mutationResult = $this->parseLine($mutation->getOutput());
        $this->assertSame('BUSY', $mutationResult['status'], 'The mutation must refuse immediately while the lease is active: ' . $mutation->getOutput());

        $this->assertSame(0, WebsiteAsset::where('website_id', $website->id)->count(), 'No asset row may ever be created for a refused mutation.');
        $directory = public_path("images/websites/{$website->uid}");
        $this->assertTrue(! is_dir($directory) || glob("{$directory}/*") === [], 'No file may ever be written to disk for a refused mutation.');
    }

    public function test_asset_delete_owning_the_row_first_makes_generation_wait_until_it_commits(): void
    {
        $website = $this->createMinimalWebsite();
        $asset = WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/race/seed.png',
            'mime_type' => 'image/png', 'size' => 10, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);
        $env = $this->childEnvironment();

        $mutation = $this->process(['delete-asset', (string) $website->id, $asset->uid, '1.5'], $env);
        $lease = $this->process(['lease', (string) $website->id, '0'], $env);

        $mutation->start();
        $this->waitForLockedSignal($mutation);
        $lease->start();
        $mutation->wait();
        $lease->wait();

        $this->assertSame(0, $mutation->getExitCode(), "Mutation process failed: {$mutation->getErrorOutput()}");
        $this->assertSame(0, $lease->getExitCode(), "Lease process failed: {$lease->getErrorOutput()}");

        $mutationResult = $this->parseLine($mutation->getOutput());
        $leaseResult = $this->parseLine($lease->getOutput());

        $this->assertSame('OK', $mutationResult['status'], 'The delete must succeed: ' . $mutation->getOutput());
        $this->assertSame('OK', $leaseResult['status'], 'beginLease() must succeed once the delete commits: ' . $lease->getOutput());
        $this->assertGreaterThan($mutationResult['time'], $leaseResult['time'], 'beginLease() must genuinely BLOCK until the delete commits.');

        $this->assertNull(WebsiteAsset::find($asset->id), 'The asset must have been deleted.');
    }

    public function test_asset_delete_refuses_immediately_while_a_generation_lease_is_already_active(): void
    {
        $website = $this->createMinimalWebsite();
        $asset = WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/race/seed.png',
            'mime_type' => 'image/png', 'size' => 10, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);
        $env = $this->childEnvironment();

        $lease = $this->process(['lease', (string) $website->id, '1.5'], $env);
        $mutation = $this->process(['delete-asset', (string) $website->id, $asset->uid, '0'], $env);

        $lease->start();
        usleep(200_000);
        $mutation->start();
        $lease->wait();
        $mutation->wait();

        $this->assertSame(0, $lease->getExitCode(), "Lease process failed: {$lease->getErrorOutput()}");
        $this->assertSame(0, $mutation->getExitCode(), "Mutation process failed: {$mutation->getErrorOutput()}");

        $mutationResult = $this->parseLine($mutation->getOutput());
        $this->assertSame('BUSY', $mutationResult['status'], 'The delete must refuse immediately while the lease is active: ' . $mutation->getOutput());

        $this->assertNotNull(WebsiteAsset::find($asset->id), 'The asset must never be deleted for a refused mutation.');
    }

    // ----------------------------------------------------------------
    // Gallery page mutation
    // ----------------------------------------------------------------

    public function test_gallery_mutation_owning_the_row_first_makes_generation_wait_until_it_commits(): void
    {
        $website = $this->createMinimalWebsite();
        $asset = WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/race/seed.png',
            'mime_type' => 'image/png', 'size' => 10, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);
        $env = $this->childEnvironment();

        $mutation = $this->process(['store-gallery', (string) $website->id, $asset->uid, '1.5'], $env);
        $lease = $this->process(['lease', (string) $website->id, '0'], $env);

        $mutation->start();
        $this->waitForLockedSignal($mutation);
        $lease->start();
        $mutation->wait();
        $lease->wait();

        $this->assertSame(0, $mutation->getExitCode(), "Mutation process failed: {$mutation->getErrorOutput()}");
        $this->assertSame(0, $lease->getExitCode(), "Lease process failed: {$lease->getErrorOutput()}");

        $mutationResult = $this->parseLine($mutation->getOutput());
        $leaseResult = $this->parseLine($lease->getOutput());

        $this->assertSame('OK', $mutationResult['status'], 'The gallery mutation must succeed: ' . $mutation->getOutput());
        $this->assertSame('OK', $leaseResult['status'], 'beginLease() must succeed once the gallery mutation commits: ' . $lease->getOutput());
        $this->assertGreaterThan($mutationResult['time'], $leaseResult['time'], 'beginLease() must genuinely BLOCK until the gallery mutation commits.');

        $galleryPage = $website->pages()->where('slug', 'gallery')->first();
        $this->assertNotNull($galleryPage, 'The Gallery page must have been committed.');
    }

    public function test_gallery_mutation_refuses_immediately_while_a_generation_lease_is_already_active(): void
    {
        $website = $this->createMinimalWebsite();
        $asset = WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/race/seed.png',
            'mime_type' => 'image/png', 'size' => 10, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);
        $env = $this->childEnvironment();

        $lease = $this->process(['lease', (string) $website->id, '1.5'], $env);
        $mutation = $this->process(['store-gallery', (string) $website->id, $asset->uid, '0'], $env);

        $lease->start();
        usleep(200_000);
        $mutation->start();
        $lease->wait();
        $mutation->wait();

        $this->assertSame(0, $lease->getExitCode(), "Lease process failed: {$lease->getErrorOutput()}");
        $this->assertSame(0, $mutation->getExitCode(), "Mutation process failed: {$mutation->getErrorOutput()}");

        $mutationResult = $this->parseLine($mutation->getOutput());
        $this->assertSame('BUSY', $mutationResult['status'], 'The gallery mutation must refuse immediately while the lease is active: ' . $mutation->getOutput());

        $this->assertNull($website->fresh()->pages()->where('slug', 'gallery')->first(), 'No Gallery page may ever be created for a refused mutation.');
    }

    // ----------------------------------------------------------------
    // Direct draft-page mutation
    // ----------------------------------------------------------------

    public function test_page_update_owning_the_row_first_makes_generation_wait_until_it_commits(): void
    {
        $website = $this->createMinimalWebsite();
        $page = WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Original Title', 'slug' => 'original',
            'is_home' => false, 'sections' => [], 'noindex' => true,
        ]);
        $env = $this->childEnvironment();

        $mutation = $this->process(['update-page', (string) $website->id, $page->uid, 'Updated Title', '1.5'], $env);
        $lease = $this->process(['lease', (string) $website->id, '0'], $env);

        $mutation->start();
        $this->waitForLockedSignal($mutation);
        $lease->start();
        $mutation->wait();
        $lease->wait();

        $this->assertSame(0, $mutation->getExitCode(), "Mutation process failed: {$mutation->getErrorOutput()}");
        $this->assertSame(0, $lease->getExitCode(), "Lease process failed: {$lease->getErrorOutput()}");

        $mutationResult = $this->parseLine($mutation->getOutput());
        $leaseResult = $this->parseLine($lease->getOutput());

        $this->assertSame('OK', $mutationResult['status'], 'The page update must succeed: ' . $mutation->getOutput());
        $this->assertSame('OK', $leaseResult['status'], 'beginLease() must succeed once the page update commits: ' . $lease->getOutput());
        $this->assertGreaterThan($mutationResult['time'], $leaseResult['time'], 'beginLease() must genuinely BLOCK until the page update commits.');

        $this->assertSame('Updated Title', $page->fresh()->title, 'The page title must have been committed.');
    }

    public function test_page_update_refuses_immediately_while_a_generation_lease_is_already_active(): void
    {
        $website = $this->createMinimalWebsite();
        $page = WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Original Title', 'slug' => 'original',
            'is_home' => false, 'sections' => [], 'noindex' => true,
        ]);
        $env = $this->childEnvironment();

        $lease = $this->process(['lease', (string) $website->id, '1.5'], $env);
        $mutation = $this->process(['update-page', (string) $website->id, $page->uid, 'Updated Title', '0'], $env);

        $lease->start();
        usleep(200_000);
        $mutation->start();
        $lease->wait();
        $mutation->wait();

        $this->assertSame(0, $lease->getExitCode(), "Lease process failed: {$lease->getErrorOutput()}");
        $this->assertSame(0, $mutation->getExitCode(), "Mutation process failed: {$mutation->getErrorOutput()}");

        $mutationResult = $this->parseLine($mutation->getOutput());
        $this->assertSame('BUSY', $mutationResult['status'], 'The page update must refuse immediately while the lease is active: ' . $mutation->getOutput());

        $this->assertSame('Original Title', $page->fresh()->title, 'The page must never be mutated for a refused update.');
    }
}
