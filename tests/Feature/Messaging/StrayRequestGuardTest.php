<?php

namespace Tests\Feature\Messaging;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\Support\UsesTemporaryEnvironmentFile;
use Tests\TestCase;

/**
 * Customer Experience Slice 3 §4.4 item 19 / §4.13 step 3 — T-MSG-33.
 *
 * `Http::preventStrayRequests()` is active for the base test class, so a
 * deliberately unmatched HTTP call fails immediately instead of silently
 * reaching a real endpoint.
 *
 * This file deliberately does NOT call `Http::fake()` in setUp(). Every
 * other messaging test does, which means none of them can prove the guard
 * exists — a faked client never reaches the stray-request check at all.
 * The proof has to come from a test that leaves the guard exposed.
 *
 * No request in this file is ever actually issued: the guard throws while
 * building the request, before any socket is opened, which is the whole
 * point of asserting it.
 */
class StrayRequestGuardTest extends TestCase
{
    // ---------------------------------------------------------------
    // T-MSG-33 — the guard itself
    // ---------------------------------------------------------------

    public function test_an_unmatched_http_call_fails_immediately(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://api.telnyx.com/v2/messages');
    }

    public function test_the_refusal_names_the_url_that_tried_to_escape(): void
    {
        try {
            Http::post('https://example.invalid/webhook', ['probe' => true]);
            $this->fail('A stray outbound request must never be allowed to proceed.');
        } catch (StrayRequestException $e) {
            $this->assertStringContainsString('example.invalid/webhook', $e->getMessage());
        }
    }

    public function test_the_guard_is_armed_by_the_base_class_not_by_this_file(): void
    {
        // Nothing in this test class arms the guard — no setUp(), no
        // Http::fake(), no preventStrayRequests() call of its own. If the
        // line were ever removed from Tests\TestCase::setUp(), this call
        // would quietly succeed or fail with a connection error instead,
        // and this assertion is what would notice.
        $this->assertFalse(
            method_exists($this, 'setUp') && (new \ReflectionMethod($this, 'setUp'))->getDeclaringClass()->getName() === self::class,
            'This test class must not define its own setUp(), or it would be proving its own arrangement.',
        );

        $this->expectException(StrayRequestException::class);

        Http::head('https://sms.example.invalid/');
    }

    // ---------------------------------------------------------------
    // The guard must not have cost the suite anything it needs
    // ---------------------------------------------------------------

    public function test_an_explicitly_faked_call_still_works(): void
    {
        // The escape hatch every legitimate caller uses. If this broke,
        // the guard would have made the suite unusable rather than safe.
        Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['id' => 'pm_faked']], 200)]);

        $response = Http::get('https://api.telnyx.com/v2/messages');

        $this->assertSame(200, $response->status());
        $this->assertSame('pm_faked', $response->json('data.id'));
    }

    public function test_a_bare_fake_absorbs_everything_without_reaching_the_network(): void
    {
        Http::fake();

        $this->assertSame(200, Http::get('https://anything.invalid/at/all')->status());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'anything.invalid'));
    }

    // ---------------------------------------------------------------
    // Chat D's environment isolation must still be in force here
    // ---------------------------------------------------------------

    public function test_the_environment_isolation_is_untouched_by_the_new_guard(): void
    {
        // Slice 3 added its line to the same base class Chat D's
        // environment-file isolation lives on. This asserts the two
        // coexist rather than one having quietly replaced the other:
        // the trait is still applied, and the disposable copy — not a
        // repository file — is still the active environment path.
        $this->assertContains(
            UsesTemporaryEnvironmentFile::class,
            class_uses_recursive(TestCase::class),
            'The environment-file isolation trait must remain on the base test class.',
        );

        $active = $this->temporaryEnvironmentFilePath();

        $this->assertNotNull($active, 'The disposable environment file must be installed.');
        $this->assertNotSame(base_path('.env'), $active);
        $this->assertNotSame(base_path('.env.testing'), $active);
    }

    public function test_the_teardown_order_the_isolation_depends_on_is_preserved(): void
    {
        // Chat D's tearDown() deliberately calls parent::tearDown() FIRST
        // and restores afterwards, so the disposable copy stays in force
        // through the framework's own destroy-callbacks (where
        // migrate:fresh runs). Slice 3 added a setUp() to the same class;
        // this pins that it did not reorder or replace the teardown.
        $source = file_get_contents((new \ReflectionClass(TestCase::class))->getFileName());

        $tearDown = substr($source, (int) strpos($source, 'protected function tearDown'));
        $parentCall = strpos($tearDown, 'parent::tearDown();');
        $restoreCall = strpos($tearDown, '$this->restoreEnvironmentFile();');

        $this->assertNotFalse($parentCall);
        $this->assertNotFalse($restoreCall);
        $this->assertLessThan(
            $restoreCall,
            $parentCall,
            'parent::tearDown() must still run BEFORE restoreEnvironmentFile().',
        );
    }
}
