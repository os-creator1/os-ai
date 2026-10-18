<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Public\MarketingController;
use App\Library\Marketing\MarketingSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/pricing` already belongs to the application: it is a signed-in customer's own pricing page
 * (routes/auth.php, behind `auth` + `verified`). The public MotionGrove marketing site also needs `/pricing`.
 *
 * This middleware sits on that existing route and, for a guest only, answers with the public pricing page
 * instead of the redirect to login. A signed-in customer falls through to the unchanged route, middleware and
 * controller. It is listed ahead of `Authenticate` in Kernel::$middlewarePriority so a guest is answered here.
 */
class ServeMarketingPricingToGuests
{
    public function __construct(private readonly MarketingSite $site) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() || ! $this->site->servesRequest($request)) {
            return $next($request);
        }

        return response(app(MarketingController::class)->pricing());
    }
}
