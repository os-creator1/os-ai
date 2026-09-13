<?php

namespace Tests\Unit\Support;

use App\Library\Support\RequestScopedCache;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Shared customer request query-budget optimization (Automations V2 §18,
 * the V2-E blocker on PR #280) — RequestScopedCache is the primitive every
 * repository-level fix in this slice is built on. Its one job is to make
 * "cached for this request" mean the same thing here as it already does
 * for CustomerShellComposer::currentMenuEntitlements() (request-attribute
 * scoped, not container-lifetime scoped) — get that wrong and every
 * caller built on it either leaks stale data across requests or never
 * saves a query at all.
 */
class RequestScopedCacheTest extends TestCase
{
    private function cache(): RequestScopedCache
    {
        return app(RequestScopedCache::class);
    }

    public function test_remember_only_calls_the_resolver_once_for_the_same_key(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return 'value';
        };

        $first = $this->cache()->remember('k', $resolver);
        $second = $this->cache()->remember('k', $resolver);

        $this->assertSame('value', $first);
        $this->assertSame('value', $second);
        $this->assertSame(1, $calls, 'The resolver must run once per key per request.');
    }

    public function test_remember_caches_a_null_result_too(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return null;
        };

        $this->cache()->remember('k', $resolver);
        $this->cache()->remember('k', $resolver);

        $this->assertSame(1, $calls, 'A null answer (e.g. "no row") must still be memoized, not re-resolved every time.');
    }

    public function test_different_keys_are_resolved_independently(): void
    {
        $this->assertSame('a', $this->cache()->remember('key-a', fn () => 'a'));
        $this->assertSame('b', $this->cache()->remember('key-b', fn () => 'b'));
    }

    public function test_forget_makes_the_next_remember_call_the_resolver_again(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return $calls;
        };

        $this->assertSame(1, $this->cache()->remember('k', $resolver));
        $this->cache()->forget('k');
        $this->assertSame(2, $this->cache()->remember('k', $resolver), 'forget() must force a fresh resolve — this is the write-invalidation seam every caching repository method relies on.');
    }

    public function test_forget_prefixed_clears_every_matching_key_and_nothing_else(): void
    {
        $this->cache()->remember('business:find:1', fn () => 'one');
        $this->cache()->remember('business:find:2', fn () => 'two');
        $this->cache()->remember('workspace:find:1', fn () => 'workspace-one');

        $this->cache()->forgetPrefixed('business:find:');

        $this->assertFalse($this->cache()->has('business:find:1'));
        $this->assertFalse($this->cache()->has('business:find:2'));
        $this->assertTrue($this->cache()->has('workspace:find:1'), 'forgetPrefixed() must never touch a differently-prefixed key.');
    }

    /**
     * The core scoping guarantee (Automations V2 §18's own security
     * requirement: no cross-request/cross-actor leakage). A container
     * singleton alone would NOT prove this — the container persists for
     * the life of a PHPUnit test method, which can dispatch more than one
     * Illuminate Request (exactly as two real, separate page loads
     * would). Rebinding 'request', as the kernel does between two real
     * requests and as every $this->get()/$this->post() call does in a
     * test, must reset the cache.
     */
    public function test_a_new_request_never_sees_the_previous_requests_cached_values(): void
    {
        $this->cache()->remember('k', fn () => 'first-request-value');
        $this->assertTrue($this->cache()->has('k'));

        $this->app->instance('request', Request::create('/next'));

        $this->assertFalse($this->cache()->has('k'), 'A fresh request must start with an empty cache.');
        $this->assertSame('second-request-value', $this->cache()->remember('k', fn () => 'second-request-value'));
    }

    /**
     * flush() is the lifecycle reset: it empties the store for the current
     * Request without replacing the Request — exactly what a long-lived
     * console process needs, because its Request is never replaced.
     */
    public function test_flush_forgets_every_key_for_the_current_request(): void
    {
        $this->cache()->remember('a', fn () => 1);
        $this->cache()->remember('b:c', fn () => 2);

        $request = app('request');

        $this->cache()->flush();

        $this->assertSame($request, app('request'), 'flush() resets the store, never the Request.');
        $this->assertFalse($this->cache()->has('a'));
        $this->assertFalse($this->cache()->has('b:c'));

        $calls = 0;
        $this->cache()->remember('a', function () use (&$calls) {
            $calls++;

            return 'fresh';
        });
        $this->assertSame(1, $calls, 'After a flush the resolver must run again.');
    }

    public function test_flush_is_harmless_on_an_empty_store_and_can_repeat(): void
    {
        $this->cache()->flush();
        $this->cache()->flush();

        $this->assertFalse($this->cache()->has('anything'));
        $this->assertSame('v', $this->cache()->remember('anything', fn () => 'v'));
    }

    public function test_flush_touches_only_the_current_request(): void
    {
        $this->cache()->remember('k', fn () => 'old');
        $previous = app('request');

        $this->app->instance('request', Request::create('/other'));
        $this->cache()->remember('k', fn () => 'other');

        $this->cache()->flush();

        $this->assertFalse($this->cache()->has('k'));
        $this->assertSame(['k' => 'old'], $previous->attributes->get('__request_scoped_cache'), 'Another Request\'s store is not reached.');
    }
}
