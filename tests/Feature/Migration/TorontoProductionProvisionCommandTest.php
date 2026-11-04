<?php

namespace Tests\Feature\Migration;

use App\Library\Migration\Toronto\TorontoProductionGuard;
use App\Library\Migration\Toronto\TorontoProvisioner;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The target guard is replaced by an always-passing stub so the INPUT preflight and the dry-run's read-only behaviour
 * can be exercised on the isolated test database. The real guard has its own refusals; nothing here writes.
 * Synthetic fixtures only.
 */
class TorontoProductionProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/toronto-cmd-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/snap', 0700, true);
        $this->app->instance(TorontoProductionGuard::class, new class extends TorontoProductionGuard {
            public function assertTarget(): array
            {
                return ['app_env' => 'production', 'app_host' => 'getmotiongrove.com', 'db_name' => 'GetMotionGrove'];
            }

            public function assertMayWrite(?string $confirmedDatabase, ?string $backupReference, ?string $phrase, ?bool $optedIn = null): array
            {
                return $this->assertTarget();
            }
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/snap/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir.'/snap');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function snapshot(string $locationId = TorontoProvisioner::SOURCE_LOCATION): string
    {
        $data = [
            'location' => ['id' => $locationId], 'customFields' => [], 'tags' => [],
            'pipelines' => [['id' => 'P1', 'name' => 'Pipe', 'stages' => [['id' => 'S1', 'name' => 'New Lead', 'position' => 0]]]],
            'contacts' => [['id' => 'C1', 'firstNameRaw' => 'Ann', 'email' => 'ann@example.test', 'dnd' => false, 'dateAdded' => '2026-01-02T00:00:00.000Z']],
            'opportunities' => [], 'conversations' => [], 'messages' => [],
        ];
        foreach ($data as $n => $rows) {
            file_put_contents("{$this->dir}/snap/{$n}.json", json_encode($rows));
        }
        $report = [];
        foreach (['location' => 1, 'customFields' => 0, 'tags' => 0, 'pipelines' => 1, 'contacts' => 1, 'opportunities' => 0, 'conversations' => 0, 'messages' => 0] as $n => $c) {
            $report[$n] = ['status' => 200, 'count' => $c, 'failures' => []];
        }
        file_put_contents($this->dir.'/snap/_report.json', json_encode($report));

        return $this->dir.'/snap';
    }

    /** @return array{int, int, int} */
    private function counts(): array
    {
        return [User::count(), Workspace::count(), Business::count()];
    }

    private function apply(array $options): int
    {
        return $this->artisan('toronto:production-provision', $options + [
            '--owner-email' => 'agency@example.test', '--confirm-database' => 'GetMotionGrove',
            '--backup-reference' => '/tmp/backup.sql.gz', '--confirm-phrase' => TorontoProductionGuard::PHRASE, '--apply' => true,
        ])->run();
    }

    public function test_apply_without_a_snapshot_writes_nothing(): void
    {
        $before = $this->counts();
        $this->assertSame(1, $this->apply(['--skip-website' => true]));
        $this->assertSame($before, $this->counts());
    }

    public function test_apply_with_a_snapshot_from_the_wrong_ghl_location_writes_nothing(): void
    {
        $before = $this->counts();
        $this->assertSame(1, $this->apply(['--snapshot' => $this->snapshot('SOMEOTHERLOCATION'), '--skip-website' => true]));
        $this->assertSame($before, $this->counts());
    }

    public function test_apply_with_an_incomplete_report_writes_nothing(): void
    {
        $dir = $this->snapshot();
        $report = json_decode((string) file_get_contents($dir.'/_report.json'), true);
        $report['conversations']['count'] = null;
        file_put_contents($dir.'/_report.json', json_encode($report));
        $before = $this->counts();
        $this->assertSame(1, $this->apply(['--snapshot' => $dir, '--skip-website' => true]));
        $this->assertSame($before, $this->counts());
    }

    public function test_apply_with_a_missing_assets_directory_writes_nothing_unless_the_website_is_skipped_explicitly(): void
    {
        $before = $this->counts();
        $this->assertSame(1, $this->apply(['--snapshot' => $this->snapshot()]));
        $this->assertSame(1, $this->apply(['--snapshot' => $this->snapshot(), '--assets' => $this->dir.'/missing']));
        $this->assertSame($before, $this->counts());
    }

    public function test_dry_run_with_a_valid_snapshot_is_read_only(): void
    {
        $before = $this->counts();
        $code = $this->artisan('toronto:production-provision', ['--owner-email' => 'agency@example.test', '--snapshot' => $this->snapshot(), '--skip-website' => true])->run();
        $this->assertSame(0, $code);
        $this->assertSame($before, $this->counts());
    }

    public function test_dry_run_with_a_wrong_location_fails_and_stays_read_only(): void
    {
        $before = $this->counts();
        $code = $this->artisan('toronto:production-provision', ['--owner-email' => 'agency@example.test', '--snapshot' => $this->snapshot('WRONG'), '--skip-website' => true])->run();
        $this->assertSame(1, $code);
        $this->assertSame($before, $this->counts());
    }
}
