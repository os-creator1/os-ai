<?php

namespace App\Http\Middleware;

use App\Library\Messaging\LegacyWebhookRouteRegistry;
use Closure;
use Illuminate\Http\Request;

/**
 * V1 release-risk closure item 4 — default-deny for the legacy provider
 * webhook family.
 *
 * `routes/public.php` still declares ~85 `inbound/*` and `dlr/*` routes, none
 * of which (bar the ones in LegacyWebhookRouteRegistry::SUPPORTED) verifies
 * who is calling: attribution was by receiving number alone, and a DLR route
 * updated a delivery report on the strength of a message id in the query
 * string. V1 supports Twilio and Telnyx only, so every other gateway's public
 * callback is refused here, BEFORE its controller runs — nothing a forged
 * request carries can reach a tenant, Contact, conversation or report.
 *
 * Keyed on the route's own URI first segment, not only its name, so an
 * unnamed or newly added `inbound/*` / `dlr/*` route is denied until it is
 * deliberately listed (with its authentication) in SUPPORTED.
 *
 * Registered after RecordLegacyWebhookUsage on the public group: that
 * middleware measures in terminate(), so decommission evidence keeps
 * accumulating for refused routes too.
 */
class DisableUnsupportedLegacyWebhooks
{
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();

        if ($route !== null
            && LegacyWebhookRouteRegistry::isLegacyFamilyUri($route->uri())
            && ! LegacyWebhookRouteRegistry::isSupported($route->getName())) {
            return response()->json(['status' => 'disabled'], 410);
        }

        return $next($request);
    }
}
