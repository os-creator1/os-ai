<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Downloads the customer's own original images and videos listed in docs/migrations/lumina-aura/website-manifest.json
 * straight from their public GoHighLevel media URLs into a PRIVATE directory, and keeps each file ONLY if its SHA-256
 * equals the manifest's. Needs no token and writes nothing to the database, GHL or the public web root.
 */
class LuminaFetchAssetsCommand extends Command
{
    public const BASE = 'https://assets.cdn.filesafe.space';

    protected $signature = 'lumina:fetch-assets {--out= : Private directory (outside the web root)} {--location=7HLIWryaYyed99beyIB7 : The GHL location id in the media URLs}';

    protected $description = 'Download and checksum-verify the Lumina Aura website images/videos from the customer\'s public GHL media URLs.';

    public function handle(): int
    {
        $out = rtrim((string) $this->option('out'), '/');
        if ($out === '' || str_contains($out, base_path('public'))) {
            $this->error('--out must be a private directory outside the web root.');

            return self::FAILURE;
        }
        if (! is_dir($out) && ! mkdir($out, 0700, true) && ! is_dir($out)) {
            $this->error("Cannot create {$out}.");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents(base_path('docs/migrations/lumina-aura/website-manifest.json')), true);
        $ok = 0;
        $failed = [];

        foreach (array_merge($manifest['assets'], $manifest['videos'] ?? []) as $role => $entry) {
            $target = "{$out}/{$entry['ghl_file']}";
            if (is_file($target) && hash_file('sha256', $target) === $entry['sha256']) {
                $ok++;

                continue;
            }

            $response = Http::timeout(120)->withHeaders(['User-Agent' => 'MotionGrove-GHL-Migration/1.0 (read-only)'])
                ->get(self::BASE.'/'.$this->option('location').'/media/'.$entry['ghl_file']);

            if (! $response->successful() || hash('sha256', $response->body()) !== $entry['sha256']) {
                $failed[] = $role;

                continue;
            }

            file_put_contents($target, $response->body());
            chmod($target, 0600);
            $ok++;
        }

        $this->line("Verified {$ok} of ".(count($manifest['assets']) + count($manifest['videos'] ?? [])).' assets in '.$out);
        if ($failed !== []) {
            $this->error('Missing or checksum mismatch (nothing wrong was kept): '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
