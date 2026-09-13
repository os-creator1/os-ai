<?php

namespace App\Library\Support;

use Closure;
use Illuminate\Http\Request;

/**
 * Shared customer request query-budget optimization — a per-request
 * memoization cache for facts that are re-read, identically, more than
 * once while resolving ONE incoming request (Automations V2 §18; the
 * V2-E blocker on PR #280).
 *
 * Scope: the storage lives on the current Illuminate\Http\Request's own
 * attribute bag — the same mechanism CustomerShellComposer's
 * currentMenuEntitlements() already established for the request's one
 * menu-entitlement snapshot. This is deliberate, and NOT merely a
 * container singleton over an internal array: this class itself IS
 * resolved as a container singleton (see AppServiceProvider), but the
 * container instance persists for as long as the container does, and a
 * single container can legitimately dispatch more than one Illuminate
 * Request through the kernel in one process lifetime — every feature
 * test that calls $this->get()/$this->post() more than once keeps the
 * same $this->app across those calls, each of which is still a genuinely
 * separate request. Keying storage off the Request object itself (fresh
 * per dispatch, in production and in tests alike) is what makes "one
 * request" mean the same thing here as it does everywhere else this
 * codebase already memoizes per-request state, and it also means a
 * console command or job with no current request simply never caches
 * (see storeFor()) rather than silently caching for the wrong scope.
 *
 * This is NOT a second source of truth and NOT a place to cache anything
 * cross-request: it exists solely so that asking the database the exact
 * same question twice within one already-open request returns the
 * answer from the first query instead of issuing a second one. Any
 * caller that also WRITES the underlying row must invalidate the
 * relevant key(s) (`forget`/`forgetPrefixed`) in the same call, so a
 * later read in that same request never returns data stale as of
 * before that write.
 */
final class RequestScopedCache
{
    private const ATTRIBUTE = '__request_scoped_cache';

    public function remember(string $key, Closure $resolver): mixed
    {
        $store = $this->store();

        if (! array_key_exists($key, $store)) {
            $store[$key] = $resolver();
            $this->putStore($store);
        }

        return $store[$key];
    }

    public function forget(string $key): void
    {
        $store = $this->store();

        if (! array_key_exists($key, $store)) {
            return;
        }

        unset($store[$key]);
        $this->putStore($store);
    }

    /**
     * Forgets every cached key starting with $prefix — the safe default
     * for a write whose exact blast radius (which cached keys it could
     * have invalidated) is not cheaply knowable, e.g. a bulk UPDATE that
     * touches rows by a foreign key rather than by their own primary key.
     */
    public function forgetPrefixed(string $prefix): void
    {
        $store = $this->store();
        $changed = false;

        foreach (array_keys($store) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($store[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->putStore($store);
        }
    }

    /** Test/debug seam only — never called from production request code. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store());
    }

    /** @return array<string, mixed> */
    private function store(): array
    {
        $request = $this->currentRequest();

        if ($request === null) {
            return [];
        }

        $store = $request->attributes->get(self::ATTRIBUTE);

        return is_array($store) ? $store : [];
    }

    /** @param  array<string, mixed>  $store */
    private function putStore(array $store): void
    {
        $this->currentRequest()?->attributes->set(self::ATTRIBUTE, $store);
    }

    /**
     * The CURRENT request, resolved fresh from the container on every
     * call rather than captured once at construction time: the container
     * rebinds 'request' at the start of each dispatch (including each
     * $this->get()/$this->post() within one test method, each a genuinely
     * separate request sharing the same $this->app), and this class is
     * itself a container singleton built once — reading request() eagerly
     * in a constructor would freeze on whichever request happened to be
     * current when this singleton was first resolved.
     */
    private function currentRequest(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
