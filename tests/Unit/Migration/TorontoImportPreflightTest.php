<?php

namespace Tests\Unit\Migration;

use App\Library\Migration\Toronto\TorontoImportPreflight;
use App\Library\Migration\Toronto\TorontoProvisioner;
use PHPUnit\Framework\TestCase;

/** Synthetic fixtures only — no real customer data. Pure: no database, no network. */
class TorontoImportPreflightTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/toronto-preflight-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/snap', 0700, true);
        mkdir($this->dir.'/assets', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir.'/snap/*') ?: [], glob($this->dir.'/assets/*') ?: [], glob($this->dir.'/*.json') ?: []) as $f) {
            @unlink($f);
        }
        @rmdir($this->dir.'/snap');
        @rmdir($this->dir.'/assets');
        @rmdir($this->dir);
    }

    private function writeSnapshot(array $override = [], ?array $report = null): string
    {
        $data = array_merge([
            'location' => ['id' => TorontoProvisioner::SOURCE_LOCATION],
            'customFields' => [], 'tags' => [], 'pipelines' => [['id' => 'P1', 'name' => 'Pipe', 'stages' => []]],
            'contacts' => [['id' => 'C1']], 'opportunities' => [], 'conversations' => [], 'messages' => [],
        ], $override);
        foreach ($data as $name => $rows) {
            file_put_contents("{$this->dir}/snap/{$name}.json", json_encode($rows));
        }
        $report ??= [];
        if ($report === []) {
            foreach (['location' => 1, 'customFields' => 0, 'tags' => 0, 'pipelines' => 1, 'contacts' => 1, 'opportunities' => 0, 'conversations' => 0, 'messages' => 0] as $n => $c) {
                $report[$n] = ['status' => 200, 'count' => $c, 'failures' => []];
            }
        }
        file_put_contents($this->dir.'/snap/_report.json', json_encode($report));

        return $this->dir.'/snap';
    }

    public function test_a_complete_snapshot_from_the_right_location_passes(): void
    {
        $this->assertSame([], (new TorontoImportPreflight())->snapshot($this->writeSnapshot())['errors']);
    }

    public function test_missing_or_null_snapshot_directory_is_refused(): void
    {
        $p = new TorontoImportPreflight();
        $this->assertNotSame([], $p->snapshot(null)['errors']);
        $this->assertNotSame([], $p->snapshot($this->dir.'/nope')['errors']);
    }

    public function test_missing_report_is_refused_not_assumed_complete(): void
    {
        $dir = $this->writeSnapshot();
        unlink($dir.'/_report.json');
        $this->assertStringContainsString('_report.json', implode(' ', (new TorontoImportPreflight())->snapshot($dir)['errors']));
    }

    public function test_report_with_failures_or_unverified_counts_is_refused(): void
    {
        $dir = $this->writeSnapshot();
        $report = json_decode((string) file_get_contents($dir.'/_report.json'), true);
        $report['messages']['failures'] = [['conversation' => 'X', 'page' => 1, 'status' => 500]];
        $report['contacts']['count'] = null;
        file_put_contents($dir.'/_report.json', json_encode($report));
        $errors = implode(' ', (new TorontoImportPreflight())->snapshot($dir)['errors']);
        $this->assertStringContainsString('INCOMPLETE', $errors);
        $this->assertStringContainsString('messages', $errors);
    }

    public function test_wrong_ghl_location_is_refused(): void
    {
        $dir = $this->writeSnapshot(['location' => ['id' => 'OTHERLOCATION']]);
        $this->assertStringContainsString('not from GHL location '.TorontoProvisioner::SOURCE_LOCATION, implode(' ', (new TorontoImportPreflight())->snapshot($dir)['errors']));
    }

    public function test_missing_required_dataset_and_empty_contacts_are_refused(): void
    {
        $dir = $this->writeSnapshot(['contacts' => []]);
        unlink($dir.'/opportunities.json');
        $errors = implode(' ', (new TorontoImportPreflight())->snapshot($dir)['errors']);
        $this->assertStringContainsString('opportunities.json is missing', $errors);
        $this->assertStringContainsString('no contacts', $errors);
    }

    public function test_report_counts_must_match_the_files(): void
    {
        $dir = $this->writeSnapshot(['contacts' => [['id' => 'C1'], ['id' => 'C2']]]);
        $this->assertStringContainsString('does not match the file', implode(' ', (new TorontoImportPreflight())->snapshot($dir)['errors']));
    }

    public function test_corrupt_json_file_is_refused(): void
    {
        $dir = $this->writeSnapshot();
        file_put_contents($dir.'/tags.json', '{not json');
        $this->assertStringContainsString('tags.json is not valid JSON', implode(' ', (new TorontoImportPreflight())->snapshot($dir)['errors']));
    }

    private function content(string $hash): string
    {
        $path = $this->dir.'/content.json';
        file_put_contents($path, json_encode(['assets' => ['logo' => ['sha256' => $hash]], 'pages' => ['' => ['title' => 'Home']]]));

        return $path;
    }

    public function test_assets_must_exist_and_match_their_checksum(): void
    {
        $p = new TorontoImportPreflight();
        file_put_contents($this->dir.'/assets/logo.jpg', 'image-bytes');
        $good = hash('sha256', 'image-bytes');

        $this->assertSame([], $p->assets($this->dir.'/assets', $this->content($good)));
        $this->assertStringContainsString('missing or corrupt', implode(' ', $p->assets($this->dir.'/assets', $this->content(hash('sha256', 'other')))));
        $this->assertStringContainsString('no valid sha256', implode(' ', $p->assets($this->dir.'/assets', $this->content('nothex'))));
        $this->assertStringContainsString('assets directory does not exist', implode(' ', $p->assets($this->dir.'/gone', $this->content($good))));
        $this->assertStringContainsString('assets directory does not exist', implode(' ', $p->assets(null, $this->content($good))));
        $this->assertStringContainsString('content file is missing', implode(' ', $p->assets($this->dir.'/assets', $this->dir.'/absent.json')));
    }

    public function test_the_real_website_content_file_declares_a_valid_hash_for_every_asset(): void
    {
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../docs/migrations/toronto-photo-booth/website-content.json'), true);
        $this->assertNotEmpty($content['assets']);
        foreach ($content['assets'] as $role => $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry['sha256'], $role);
        }
    }
}
