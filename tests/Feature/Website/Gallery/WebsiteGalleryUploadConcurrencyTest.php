<?php

namespace Tests\Feature\Website\Gallery;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Website\Gallery\WebsiteGalleryManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Independent-review correction round 3 (item 10) — proves
 * WebsiteGalleryManager::uploadMany() genuinely serializes gallery and
 * custom-section uploads for the SAME Website against their SHARED
 * 120MB byte quota under real concurrency, not merely under a single
 * process's own sequential ordering (which could never have exposed the
 * bug: uploadMany() previously locked only THIS call's own purpose-
 * scoped asset rows, so two concurrent uploads of DIFFERENT purposes
 * locked disjoint row sets and could both read the same "room
 * remaining" snapshot).
 *
 * Deliberately does NOT use RefreshDatabase — the real, independent
 * child processes below need genuinely COMMITTED rows, which an open
 * RefreshDatabase transaction would hide from them entirely, following
 * SlotAgreementConcurrencyTest's own proven cross-process pattern.
 * Fixture rows are created directly (auto-committed, since nothing here
 * wraps them in an explicit transaction) and are explicitly cleaned up
 * in tearDown().
 */
class WebsiteGalleryUploadConcurrencyTest extends TestCase
{
    use CreatesBusinessTestData;

    private const RUNNER = __DIR__ . '/Support/concurrent_gallery_upload_runner.php';

    private ?int $userId = null;

    private ?int $workspaceId = null;

    private ?int $businessId = null;

    private ?int $websiteId = null;

    protected function tearDown(): void
    {
        if ($this->websiteId !== null) {
            WebsiteAsset::where('website_id', $this->websiteId)->delete();
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
            'first_name' => 'Race', 'last_name' => 'Owner',
            'email' => 'race-owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $this->userId = $user->id;

        $customer = Customer::create(['user_id' => $user->id]);

        $workspace = Workspace::create(['name' => 'Race Workspace', 'owner_user_id' => $user->id, 'is_active' => true]);
        $this->workspaceId = $workspace->id;

        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        DB::table('businesses')->where('id', $business->id)->update(['status' => 'active']);
        $this->businessId = $business->id;

        $website = Website::create(['business_id' => $business->id, 'name' => 'Race Website', 'status' => 'draft']);
        $this->websiteId = $website->id;

        return $website->fresh();
    }

    /**
     * 100MB of already-"used" quota is a plain DB fact — it does not need
     * a real 100MB file on disk, since uploadMany()'s byte-cap check only
     * ever sums the `size` column, never re-reads existing files.
     */
    private function seedExistingUsage(Website $website, int $bytes): void
    {
        WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/race/preexisting.png',
            'mime_type' => 'image/png', 'size' => $bytes, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);
    }

    public function test_simultaneous_gallery_and_custom_section_uploads_cannot_exceed_the_combined_limit(): void
    {
        $website = $this->createMinimalWebsite();

        // 114MB already used, leaving 6MB of headroom under the shared
        // 120MB cap (WebsiteGalleryManager::MAX_TOTAL_UPLOAD_BYTES).
        $this->seedExistingUsage($website, 114 * 1024 * 1024);

        // Each process uploads a real, magic-byte-valid 5MB image —
        // individually well within the 6MB headroom (and under the
        // separate 8MB per-file cap), but 5MB + 5MB = 10MB together
        // exceeds it. Neither call knows about the other.
        $fiveMb = 5 * 1024 * 1024;
        $env = $this->childEnvironment();

        $processA = new Process([
            $this->phpBinary(), self::RUNNER, 'upload', (string) $website->id, WebsiteAssetPurpose::Gallery->value, (string) $fiveMb, $env['DB_DATABASE'],
        ], null, $env, null, 60);

        $processB = new Process([
            $this->phpBinary(), self::RUNNER, 'upload', (string) $website->id, WebsiteAssetPurpose::CustomSection->value, (string) $fiveMb, $env['DB_DATABASE'],
        ], null, $env, null, 60);

        // Started back-to-back, both still cold — this is what makes the
        // race genuine: neither process has acquired the Website row
        // lock yet when the other starts.
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputA = trim($processA->getOutput());
        $outputB = trim($processB->getOutput());

        $this->assertSame(0, $processA->getExitCode(), "Process A did not exit cleanly: {$processA->getErrorOutput()}");
        $this->assertSame(0, $processB->getExitCode(), "Process B did not exit cleanly: {$processB->getErrorOutput()}");

        $outcomes = [$outputA, $outputB];
        $succeeded = array_filter($outcomes, fn ($line) => $line === 'OK');
        $refused = array_filter($outcomes, fn ($line) => str_starts_with($line, 'REFUSED'));

        $this->assertCount(1, $succeeded, 'Exactly one of the two racing uploads must succeed — never both, never neither. Outcomes: ' . implode(' | ', $outcomes));
        $this->assertCount(1, $refused, 'The losing upload must be explicitly refused, never silently dropped. Outcomes: ' . implode(' | ', $outcomes));

        $totalBytes = (int) WebsiteAsset::where('website_id', $website->id)->sum('size');
        $this->assertLessThanOrEqual(
            WebsiteGalleryManager::MAX_TOTAL_UPLOAD_BYTES,
            $totalBytes,
            'The Website\'s combined uploaded-asset bytes must never exceed the shared cap, even under genuine concurrency.'
        );
        $this->assertSame(114 * 1024 * 1024 + $fiveMb, $totalBytes, 'Exactly one 5MB upload must have been committed alongside the pre-existing 114MB.');
    }
}
