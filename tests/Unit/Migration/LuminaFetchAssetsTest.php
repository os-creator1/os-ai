<?php

namespace Tests\Unit\Migration;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LuminaFetchAssetsTest extends TestCase
{
    public function test_only_files_matching_the_manifest_checksum_are_kept_and_outside_dirs_are_refused(): void
    {
        $manifest = json_decode(file_get_contents(base_path('docs/migrations/lumina-aura/website-manifest.json')), true);
        $all = array_merge($manifest['assets'], $manifest['videos']);
        $first = array_key_first($all);

        // Every URL answers with wrong bytes: nothing may be kept, and the command fails.
        Http::fake(['assets.cdn.filesafe.space/*' => Http::response('tampered')]);
        $dir = sys_get_temp_dir().'/lumina-fetch-'.uniqid();
        $this->artisan('lumina:fetch-assets', ['--out' => $dir])->assertExitCode(1);
        $this->assertSame([], glob($dir.'/*') ?: [], 'A file whose SHA-256 differs from the manifest is never written.');

        // The web root is refused outright.
        $this->artisan('lumina:fetch-assets', ['--out' => public_path('x')])->assertExitCode(1);

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://assets.cdn.filesafe.space/7HLIWryaYyed99beyIB7/media/'));
        $this->assertNotNull($first);
        @rmdir($dir);
    }
}
