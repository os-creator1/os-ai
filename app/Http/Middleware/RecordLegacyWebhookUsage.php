<?php

namespace App\Http\Middleware;

use App\Library\Messaging\LegacyWebhookRouteRegistry;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Legacy Provider Webhook Measurement Contract §3.6 — the narrowest possible
 * integration: one middleware, registered once on the `routes/public.php`
 * group (§8), that measures usage of the legacy inbound/DLR route family
 * without touching a single one of the ~85 controller methods it measures.
 *
 * WHY terminate() AND WHAT IT DOES NOT PROMISE (Correction Round 1, §3.6).
 * Terminable middleware is the preferred placement because it runs only
 * after the controller has produced its Response, so it cannot participate
 * in producing that response. It is NOT a portable guarantee that bytes have
 * already reached the client — that depends on the SAPI, web server and
 * deployment. The acceptance property this contract locks is narrower and
 * honest: response semantics are invariant. This middleware never changes
 * status, headers or body, and no failure here can change what a provider
 * callback's response says, because the work runs strictly after that
 * response was already produced.
 *
 * BOUNDED BY CONSTRUCTION (§3.6, §3.5). Exactly one atomic
 * update-or-insert, with at most one additional statement for the single
 * contracted unique-race retry: no payload parsing, no network call, no
 * transaction wrapping handler work, no lock wait, no retry beyond that one.
 *
 * PROVIDER SLUG NEVER COMES FROM REQUEST INPUT. It comes only from
 * LegacyWebhookRouteRegistry's explicit route-name map. No {gateway}
 * segment, query parameter, body field or header participates.
 *
 * NO REQUEST BODY IS EVER READ. This class never reads the request body or
 * headers through any Request accessor.
 */
class RecordLegacyWebhookUsage
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            $routeName = $request->route()?->getName();
            $providerSlug = LegacyWebhookRouteRegistry::slugFor($routeName);

            if ($providerSlug === null) {
                return;
            }

            $this->recordHit($routeName, $providerSlug);
        } catch (Throwable $e) {
            // §3.6: every telemetry exception is swallowed and reported.
            // A database outage, a missing table, a lock timeout — none of
            // it may surface to a provider or alter a supported callback's
            // status, headers or body.
            report($e);
        }
    }

    /**
     * §3.5's exact required shape: attempt an atomic conditional update
     * first; if it affects zero rows, insert; if that insert loses a race
     * to a concurrent inserter, retry the update exactly once.
     */
    private function recordHit(string $routeName, string $providerSlug): void
    {
        $now = now();

        $updated = DB::table('legacy_webhook_route_usage')
            ->where('route_name', $routeName)
            ->where('provider_slug', $providerSlug)
            ->update([
                'hit_count' => DB::raw('hit_count + 1'),
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            return;
        }

        try {
            DB::table('legacy_webhook_route_usage')->insert([
                'route_name' => $routeName,
                'provider_slug' => $providerSlug,
                'hit_count' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another process inserted the row between our failed update
            // and this insert. Retry the update exactly once (§3.5); do not
            // retry the insert, and do not loop.
            DB::table('legacy_webhook_route_usage')
                ->where('route_name', $routeName)
                ->where('provider_slug', $providerSlug)
                ->update([
                    'hit_count' => DB::raw('hit_count + 1'),
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }
}
