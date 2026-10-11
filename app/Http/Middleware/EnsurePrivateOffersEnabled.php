<?php

namespace App\Http\Middleware;

use App\Library\PlatformBilling\Offers\PrivateOffersFeature;
use Closure;
use Illuminate\Http\Request;

/**
 * While Private Offers are switched off (config/platform_billing.php) their
 * screens and offer links behave as if they do not exist: the same 404 for a
 * real link and a made-up one, before any lookup or controller runs.
 */
class EnsurePrivateOffersEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(PrivateOffersFeature::enabled(), 404);

        return $next($request);
    }
}
