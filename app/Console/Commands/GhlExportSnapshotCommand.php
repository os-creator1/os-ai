<?php

namespace App\Console\Commands;

use App\Library\Migration\Ghl\GhlApiClient;
use App\Library\Migration\Ghl\GhlSnapshotExporter;
use Illuminate\Console\Command;

class GhlExportSnapshotCommand extends Command
{
    protected $signature = 'ghl:export-snapshot {--location= : GHL location id (defaults to GHL_LOCATION_ID)} {--out= : Output directory (default storage/app/ghl-snapshots/<location>)}';

    protected $description = 'Read-only export of a GoHighLevel location to JSON files (contains personal data; never commit).';

    public function handle(): int
    {
        $location = (string) ($this->option('location') ?: config('ghl_migration.location_id') ?: getenv('GHL_LOCATION_ID'));
        if ($location === '') {
            $this->error('Set GHL_LOCATION_ID or pass --location.');

            return self::FAILURE;
        }

        $result = (new GhlSnapshotExporter(GhlApiClient::fromConfig()))->export($location);
        $dir = $this->option('out') ?: storage_path("app/ghl-snapshots/{$location}");
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        foreach ($result['data'] as $name => $rows) {
            file_put_contents("{$dir}/{$name}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        file_put_contents("{$dir}/_report.json", json_encode($result['report'], JSON_PRETTY_PRINT));

        $this->table(['dataset', 'http', 'rows'], collect($result['report'])->map(fn ($r, $k) => [$k, $r['status'], $r['count'] ?? 'UNVERIFIED'])->values()->all());
        $this->info("Snapshot written to {$dir}");

        return self::SUCCESS;
    }
}
