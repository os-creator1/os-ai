<?php

namespace Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The committed Mix manifest must describe the BUILD, and only the build.
 *
 * Two defects this locks out, both reproduced on this branch:
 *
 * 1. A MISSING ENTRY IS NOT COSMETIC.
 *    resources/views/panels/scripts.blade.php throws
 *    MixFileNotFoundException when /js/core/theme-tokens.js is absent and
 *    config('app.debug') is true — the test environment. When the asset
 *    build was broken, that single missing entry turned into 909 test
 *    errors whose message pointed at views, not at the toolchain.
 *
 * 2. THE MANIFEST INDEXES ITS OWN DESTINATION DIRECTORY.
 *    webpack.mix.js copies resources/images into public/images, and Mix
 *    records what it finds in the DESTINATION. resources/images/websites/
 *    does not exist — but public/images/websites/<uuid>/ does, because the
 *    website and branding suites upload real files there and never clean
 *    up. Building on a machine that has run the suite therefore wrote
 *    those uploads into the manifest, and four of them reached a commit.
 *    Every such build produced a different manifest.
 *
 * A pure file-content test: no database, no application boot.
 */
class MixManifestIntegrityTest extends TestCase
{
    /**
     * Entry points webpack.mix.js declares that the application actually
     * references at render time.
     */
    private const REQUIRED_ENTRIES = [
        '/js/core/app-menu.js',
        '/js/core/theme-tokens.js',
        '/js/core/app.js',
        '/js/core/scripts.js',
        '/js/scripts/pages/theme-settings.js',
        '/fonts/geist/geist-latin-wght-normal.woff2',
        '/fonts/geist/geist-latin-ext-wght-normal.woff2',
    ];

    /**
     * Directories under public/ that hold RUNTIME uploads, not build
     * output. Nothing under them may ever appear in the manifest.
     */
    private const RUNTIME_UPLOAD_PREFIXES = [
        '/images/websites/',
        '/images/branding/logo/',
        '/images/branding/logo_compact/',
        '/images/branding/logo_dark/',
        '/images/branding/favicon/',
        '/images/branding/auth_illustration/',
        '/images/branding/installer_illustration/',
    ];

    public function test_the_manifest_exists_and_is_valid_json(): void
    {
        $this->assertFileExists($this->manifestPath());

        $decoded = json_decode((string) file_get_contents($this->manifestPath()), true);

        $this->assertIsArray($decoded, 'public/mix-manifest.json must decode to an array.');
        $this->assertNotEmpty($decoded);
    }

    public function test_every_required_entry_point_is_present(): void
    {
        $manifest = $this->manifest();

        foreach (self::REQUIRED_ENTRIES as $entry) {
            $this->assertArrayHasKey(
                $entry,
                $manifest,
                "public/mix-manifest.json is missing [{$entry}]. Rebuild the assets "
                . '(npm ci && npm run production) — never hand-edit the manifest.'
            );
        }
    }

    public function test_every_manifest_target_exists_on_disk(): void
    {
        $missing = [];

        foreach ($this->manifest() as $key => $target) {
            $relative = ltrim(strtok((string) $target, '?'), '/');

            if (! is_file($this->publicPath($relative))) {
                $missing[] = "{$key} -> {$target}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "The manifest points at files that do not exist:\n  " . implode("\n  ", $missing)
        );
    }

    public function test_the_manifest_contains_no_runtime_upload_artifacts(): void
    {
        $polluted = [];

        foreach (array_keys($this->manifest()) as $key) {
            foreach (self::RUNTIME_UPLOAD_PREFIXES as $prefix) {
                if (str_starts_with((string) $key, $prefix)) {
                    $polluted[] = $key;

                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $polluted,
            "The manifest indexes runtime upload artifacts, so it was built on a dirty tree:\n  "
            . implode("\n  ", $polluted)
            . "\nRemove the untracked files under public/images/ and rebuild."
        );
    }

    private function manifest(): array
    {
        return (array) json_decode((string) file_get_contents($this->manifestPath()), true);
    }

    private function manifestPath(): string
    {
        return $this->publicPath('mix-manifest.json');
    }

    private function publicPath(string $relative): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
