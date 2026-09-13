<?php

namespace Tests\Feature\Interactions;

use Tests\TestCase;

/**
 * window.AsyncRegion (resources/js/core/async-region.js) — publication and the
 * safety half of its contract, read from source.
 *
 * Deliberately database-free, like ThemeAssetPublicationTest: every assertion
 * reads tracked source, compiled output or the manifest.
 *
 * The module must stay explicit and opt-in. It may only ever handle a GET form
 * or a link that names a region, must leave modified clicks and other origins
 * to the browser, and must fall back to a real navigation whenever the server
 * answers with something other than the page for that URL.
 */
class AsyncRegionModuleContractTest extends TestCase
{
    private const SOURCE = 'resources/js/core/async-region.js';

    private const PUBLISHED = '/js/core/async-region.js';

    private function source(): string
    {
        $this->assertFileExists(base_path(self::SOURCE));

        return (string) file_get_contents(base_path(self::SOURCE));
    }

    public function test_the_module_is_compiled_published_and_in_the_manifest(): void
    {
        $this->assertStringContainsString(
            ".js('resources/js/core/async-region.js', 'public/js/core')",
            (string) file_get_contents(base_path('webpack.mix.js'))
        );

        $published = public_path(ltrim(self::PUBLISHED, '/'));
        $this->assertFileExists($published, 'A clean checkout must ship the compiled module; the shell loads it on every customer page.');
        $this->assertGreaterThan(0, filesize($published));

        $manifest = json_decode((string) file_get_contents(public_path('mix-manifest.json')), true);
        $this->assertSame(self::PUBLISHED, $manifest[self::PUBLISHED] ?? null);

        // The build is not stale against the source in the ways that matter.
        $compiled = (string) file_get_contents($published);
        foreach (['data-async-region', 'data-async-form', 'data-async-link', 'async-region:updated', 'X-Async-Region', 'data-error-message', 'AsyncRegion'] as $token) {
            $this->assertStringContainsString($token, $compiled, "Compiled module is missing {$token}; rebuild it from source.");
        }
    }

    public function test_only_marked_get_forms_and_marked_links_are_handled(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('form.hasAttribute(FORM_ATTR)', $source);
        $this->assertStringContainsString('toLowerCase() !== "get"', $source, 'A POST form is never handled in place.');
        $this->assertStringContainsString('closest("a[" + LINK_ATTR + "]")', $source);

        // No blanket interception of every link or form on the page.
        $this->assertStringNotContainsString('closest("a")', $source);
        $this->assertStringNotContainsString("closest('a')", $source);
        $this->assertStringNotContainsString('querySelectorAll("form")', $source);
    }

    public function test_modified_clicks_other_origins_and_new_windows_are_left_to_the_browser(): void
    {
        $source = $this->source();

        foreach (['event.metaKey', 'event.ctrlKey', 'event.shiftKey', 'event.altKey', 'event.button !== 0', 'hasAttribute("download")', 'url.origin !== window.location.origin'] as $guard) {
            $this->assertStringContainsString($guard, $source);
        }
    }

    public function test_anything_other_than_the_page_for_that_url_falls_back_to_a_real_navigation(): void
    {
        $source = $this->source();

        // A redirect (an expired session) and a response without the region.
        $this->assertStringContainsString('response.redirected', $source);
        $this->assertSame(3, substr_count($source, 'window.location.assign('), 'A redirected response, a response without the region, and a browser without fetch/history support.');

        // It asks for HTML like a browser would, and never identifies as XHR —
        // which would switch the application's error and auth handling to JSON.
        $this->assertStringContainsString('Accept: "text/html"', $source);
        $this->assertStringNotContainsString('X-Requested-With', $source);
        $this->assertStringContainsString('credentials: "same-origin"', $source);
    }

    public function test_history_is_kept_and_back_forward_restore_the_state(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('window.history.pushState(', $source);
        $this->assertStringContainsString('window.addEventListener("popstate"', $source);
        $this->assertStringContainsString('syncForms(name, doc)', $source, 'A search box outside its list follows Back/Forward too.');
    }
}
