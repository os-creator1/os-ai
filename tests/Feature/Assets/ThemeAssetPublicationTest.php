<?php

namespace Tests\Feature\Assets;

use Tests\TestCase;

/**
 * A clean checkout must be able to render an authenticated page, in the
 * application's own typeface, without anyone running a local build first.
 *
 * This repository tracks its compiled front-end output: ~1 294 files under
 * public/, including public/mix-manifest.json, with only /public/hot and
 * /public/storage ignored. Several first-party assets nevertheless had a
 * tracked source and a correct webpack.mix.js rule but no published output
 * and no manifest entry, because the discipline that (rightly) kept build
 * churn out of feature commits also discarded the outputs those features
 * genuinely added. Every developer regenerated them locally; nobody ever
 * committed them.
 *
 * Two consequences shipped to main:
 *
 *   1. resources/views/panels/scripts.blade.php threw
 *      MixFileNotFoundException on every authenticated render, because
 *      /js/core/theme-tokens.js was absent.
 *   2. public/css/core.css predated the Geist work entirely — it carried
 *      no @font-face rule at all and still declared Montserrat as the
 *      primary Bootstrap font — while the tracked
 *      resources/scss/base/tokens/_typography.scss had already moved the
 *      application onto self-hosted Geist Variable, and the two WOFF2
 *      files it points at were never published either.
 *
 * ChartTokenContentTest guards theme-tokens.js's SOURCE and its
 * registration order in webpack.mix.js. Nothing guarded PUBLICATION. That
 * is the gap this test closes: source, rule, output, manifest key,
 * resolvable target and not-an-upload for every mandatory theme asset;
 * the compiled font wiring inside core.css; and the package identity the
 * lockfile is generated from.
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
        '/css/core.css' => [
            'source' => 'resources/scss/core.scss',
            'rule' => ".sass('resources/scss/core.scss', 'public/css'",
        ],
        '/fonts/geist/geist-latin-wght-normal.woff2' => [
            'source' => 'resources/fonts/geist/geist-latin-wght-normal.woff2',
            'rule' => "mixAssetsDir('fonts', (src, dest) => mix.copy(src, dest))",
        ],
        '/fonts/geist/geist-latin-ext-wght-normal.woff2' => [
            'source' => 'resources/fonts/geist/geist-latin-ext-wght-normal.woff2',
            'rule' => "mixAssetsDir('fonts', (src, dest) => mix.copy(src, dest))",
        ],
        '/fonts/geist/LICENSE' => [
            'source' => 'resources/fonts/geist/LICENSE',
            'rule' => "mixAssetsDir('fonts', (src, dest) => mix.copy(src, dest))",
        ],
    ];

    /**
     * The JavaScript subset, called out separately so a regression that
     * drops one of them fails with an unambiguous message.
     *
     * @var array<int, string>
     */
    private const MANDATORY_JAVASCRIPT_ASSETS = [
        '/js/core/theme-tokens.js',
        '/js/scripts/pages/theme-settings.js',
    ];

    /**
     * The two self-hosted Geist faces `_typography.scss` declares. Both
     * must exist on disk and be referenced by the compiled stylesheet.
     *
     * @var array<int, string>
     */
    private const REQUIRED_GEIST_FACES = [
        '/fonts/geist/geist-latin-wght-normal.woff2',
        '/fonts/geist/geist-latin-ext-wght-normal.woff2',
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

    private function compiledCoreCss(): string
    {
        $path = public_path('css/core.css');

        $this->assertFileExists($path, 'public/css/core.css must be published in a clean checkout.');

        return (string) file_get_contents($path);
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

    public function test_every_mandatory_javascript_asset_is_published(): void
    {
        $manifest = $this->manifest();

        foreach (self::MANDATORY_JAVASCRIPT_ASSETS as $key) {
            $this->assertFileExists(
                public_path(ltrim($key, '/')),
                "{$key} is mandatory: without it panels/scripts.blade.php and the admin theme-settings page fail hard."
            );
            $this->assertArrayHasKey($key, $manifest, "public/mix-manifest.json must carry {$key}.");
        }
    }

    public function test_both_geist_woff2_files_are_published(): void
    {
        foreach (self::REQUIRED_GEIST_FACES as $key) {
            $path = public_path(ltrim($key, '/'));

            $this->assertFileExists(
                $path,
                "{$key} is declared by resources/scss/base/tokens/_typography.scss and must be published, "
                . 'or the self-hosted Geist face silently falls back to a system font.'
            );
            $this->assertGreaterThan(1024, (int) filesize($path), "{$key} must be a real WOFF2 payload.");
        }
    }

    public function test_the_compiled_stylesheet_declares_both_geist_faces(): void
    {
        $css = $this->compiledCoreCss();

        $this->assertSame(
            2,
            substr_count($css, '@font-face'),
            'public/css/core.css must declare exactly the two Geist @font-face rules from _typography.scss.'
        );

        foreach (self::REQUIRED_GEIST_FACES as $key) {
            $this->assertStringContainsString(
                "url({$key})",
                $css,
                "public/css/core.css must reference {$key}; without it the @font-face rule points nowhere."
            );
        }

        $this->assertStringContainsString(
            'Geist Variable',
            $css,
            'public/css/core.css must carry the Geist Variable family name.'
        );
    }

    public function test_the_compiled_primary_font_stack_is_geist_not_the_stale_montserrat(): void
    {
        $css = $this->compiledCoreCss();

        $this->assertStringNotContainsString(
            'Montserrat',
            $css,
            'public/css/core.css has fallen back to the stale pre-Geist build, which declares Montserrat as the '
            . 'primary Bootstrap font. Rebuild it from the current resources/scss source.'
        );

        foreach (['--bs-font-sans-serif', '--bs-font-monospace', '--font-family-base', '--font-family-app'] as $property) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($property, '/') . ':[^;}]*Geist Variable/',
                $css,
                "{$property} must resolve through Geist Variable, as _typography.scss defines it."
            );
        }
    }

    public function test_every_geist_manifest_target_resolves(): void
    {
        $manifest = $this->manifest();

        foreach (array_keys(self::MANDATORY_THEME_ASSETS) as $key) {
            if (! str_starts_with($key, '/fonts/geist/')) {
                continue;
            }

            $this->assertArrayHasKey(
                $key,
                $manifest,
                "public/mix-manifest.json must carry {$key}, exactly as the production build emits it."
            );

            $target = strtok((string) $manifest[$key], '?');

            $this->assertFileExists(
                public_path(ltrim($target, '/')),
                "The manifest maps {$key} to {$target}, which does not exist."
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
                "public/mix-manifest.json must carry {$key}."
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
     * npm derives the lockfile's root name from the containing DIRECTORY
     * when package.json declares no name. That put a throwaway worktree
     * directory name into a committed lockfile once already, and would
     * have put a different one there for every checkout. A stable declared
     * name is the only thing that prevents it.
     */
    public function test_the_package_identity_is_declared_and_not_derived_from_a_directory(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true);
        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true);

        $this->assertIsArray($package, 'package.json must contain a JSON object.');
        $this->assertIsArray($lock, 'package-lock.json must contain a JSON object.');

        $this->assertArrayHasKey(
            'name',
            $package,
            'package.json must declare an explicit name; without one npm derives the lockfile identity from '
            . 'whatever directory the install happened to run in.'
        );

        $name = (string) $package['name'];

        $this->assertNotSame('', $name, 'The declared package name must not be empty.');
        $this->assertSame($name, $lock['name'] ?? null, "package-lock.json's root name must match package.json.");
        $this->assertSame(
            $name,
            $lock['packages']['']['name'] ?? null,
            "package-lock.json's root package entry must carry the same declared name."
        );

        foreach ([$name, (string) ($lock['name'] ?? '')] as $candidate) {
            $this->assertStringNotContainsStringIgnoringCase(
                'worktree',
                $candidate,
                'The package identity looks like a Git worktree directory name.'
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'public_html',
                $candidate,
                'The package identity looks like a checkout directory name.'
            );
            $this->assertDoesNotMatchRegularExpression(
                '#[\\\\/:]#',
                $candidate,
                'The package identity must be a package name, never a path.'
            );
        }
    }

    /**
     * The general property, not just the known assets: every asset a
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
