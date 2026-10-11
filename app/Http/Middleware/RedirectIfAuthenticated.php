<?php

namespace App\Http\Middleware;

use App\Helpers\Helper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string|null  ...$guards
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$guards)
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {

                // Only pending two-factor challenges may use the guest-tagged verification routes.
                // All other guest-only actions (including POST /login) must remain inaccessible.
                $user = Auth::guard($guard)->user();
                if ($request->routeIs('verify.*') && config('app.two_factor') && $user?->two_factor && $user?->two_factor_code !== null) {
                    return $next($request);
                }

                return redirect(Helper::home_route());
            }
        }

        return $next($request);
    }
}
