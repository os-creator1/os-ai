<?php

namespace Tests\Feature\Assets;

use Tests\TestCase;

/**
 * A clean checkout must be able to render an authenticated page without
 * anyone running a local build first.
 *
 * This repository tracks its compiled front-end output: 1 294 files under
 * public/, including public/mix-manifest.json, with only /public/hot and
 * /public/storage ignored. Two first-party assets nevertheless had a
 * tracked source and a correct webpack.mix.js rule but no published
 * output and no manifest entry, because the discipline that (rightly)
 * kept build churn out of feature commits also discarded the two outputs
 * those features genuinely added. Every developer regenerated them
 * locally; nobody ever committed them, so
 * resources/views/panels/scripts.blade.php threw
 * MixFileNotFoundException on every authenticated render in a clean
 * checkout.
 *
 * ChartTokenContentTest already guards theme-tokens.js's SOURCE and its
 * registration order in webpack.mix.js. Nothing guarded its
 * PUBLICATION. That is the gap this test closes: source, rule, output,
 * manifest key, resolvable target, and not-an-upload — for every
 * mandatory theme asset, and for every mix() reference the tracked Blade
 * views actually make.
 */
class ThemeAssetPublicationTest extends TestCase
{
    /**
     * Assets a clean checkout must ship compiled, with the source that
     * produces each one and the webpack.mix.js fragment that compiles it.
     *
     * @var array<string, array{source: string, rule: string}>
     */
    private const MANDATORY_THEME_ASSETS = [
        '/js/core/theme-tokens.js' => [
            'source' => 'resources/js/core/theme-tokens.js',
            'rule' => ".js('resources/js/core/theme-tokens.js', 'public/js/core')",
        ],
        '/js/scripts/pages/theme-settings.js' => [
            'source' => 'resources/js/scripts/pages/theme-settings.js',
            'rule' => "mixAssetsDir('js/scripts/**/*.js'",
        ],
    ];

    /**
     * Directories the application writes into at runtime. A build must
     * never index these, and a manifest that does was built on a dirty
     * tree — mix.copyDirectory('resources/images', 'public/images')
     * indexes its own destination, so uploads left there by a previous
     * suite run get swept in.
     *
     * @var array<int, string>
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

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $path = public_path('mix-manifest.json');

        $this->assertFileExists($path, 'public/mix-manifest.json must be tracked and present in a clean checkout.');

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'public/mix-manifest.json must contain a JSON object.');

        return $decoded;
    }

    public function test_every_mandatory_theme_asset_has_a_tracked_source(): void
    {
        foreach (self::MANDATORY_THEME_ASSETS as $key => $asset) {
            $this->assertFileExists(
                base_path($asset['source']),
                "{$key} must be compiled from {$asset['source']}, which is missing."
            );
        }
    }

    public function test_every_mandatory_theme_asset_has_a_compilation_rule(): void
    {
        $mix = (string) file_get_contents(base_path('webpack.mix.js'));

        foreach (self::MANDATORY_THEME_ASSETS as $key => $asset) {
            $this->assertStringContainsString(
                $asset['rule'],
                $mix,
                "webpack.mix.js must still carry the rule that compiles {$key}."
            );
        }
    }

    public function test_every_mandatory_theme_asset_is_published_under_public(): void
    {
        foreach (array_keys(self::MANDATORY_THEME_ASSETS) as $key) {
            $path = public_path(ltrim($key, '/'));

            $this->assertFileExists(
                $path,
                "{$key} must exist in a clean checkout. Regenerating it locally is not enough — it has to be committed."
            );
            $this->assertGreaterThan(
                0,
                (int) filesize($path),
                "{$key} must not be published empty."
            );
        }
    }

    public function test_every_mandatory_theme_asset_has_a_manifest_entry_that_resolves(): void
    {
        $manifest = $this->manifest();

        foreach (array_keys(self::MANDATORY_THEME_ASSETS) as $key) {
            $this->assertArrayHasKey(
                $key,
                $manifest,
                "public/mix-manifest.json must carry {$key}; resources/views/panels/scripts.blade.php and "
                . 'resources/views/admin/theme-settings/index.blade.php both fail hard without it.'
            );

            $target = strtok((string) $manifest[$key], '?');

            $this->assertFileExists(
                public_path(ltrim($target, '/')),
                "The manifest maps {$key} to {$target}, which does not exist."
            );
        }
    }

    public function test_no_mandatory_theme_asset_lives_in_a_runtime_upload_directory(): void
    {
        foreach (array_keys(self::MANDATORY_THEME_ASSETS) as $key) {
            foreach (self::RUNTIME_UPLOAD_PREFIXES as $prefix) {
                $this->assertStringStartsNotWith(
                    $prefix,
                    $key,
                    "{$key} must be a compiled application asset, never a runtime upload."
                );
            }
        }
    }

    public function test_the_manifest_indexes_no_runtime_upload(): void
    {
        $swept = [];

        foreach (array_keys($this->manifest()) as $key) {
            foreach (self::RUNTIME_UPLOAD_PREFIXES as $prefix) {
                if (str_starts_with((string) $key, $prefix)) {
                    $swept[] = $key;
                }
            }
        }

        $this->assertSame(
            [],
            $swept,
            'The manifest indexes runtime upload artifacts, so it was built on a dirty tree. '
            . 'Delete them and rebuild before committing.'
        );
    }

    /**
     * The general property, not just the two known assets: every asset a
     * tracked Blade view asks mix() for must resolve. mix() throws
     * MixFileNotFoundException on a missing key, so any gap here is a
     * page that cannot render.
     */
    public function test_every_mix_reference_in_a_tracked_blade_view_resolves(): void
    {
        $manifest = $this->manifest();
        $unresolved = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! preg_match_all('/mix\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $contents, $matches)) {
                continue;
            }

            foreach ($matches[1] as $asset) {
                $key = '/' . ltrim($asset, '/');

                if (! isset($manifest[$key])) {
                    $unresolved[] = $key . ' (' . $file->getFilename() . ': no manifest entry)';

                    continue;
                }

                $target = strtok((string) $manifest[$key], '?');

                if (! is_file(public_path(ltrim($target, '/')))) {
                    $unresolved[] = $key . ' (' . $file->getFilename() . ": maps to {$target}, which is missing)";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unresolved)));
    }
}
