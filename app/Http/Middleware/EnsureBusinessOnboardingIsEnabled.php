<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Master switch (RFC-001-BUSINESS-CORE-DEPLOYMENT.md §1): when
 * business.onboarding.enabled is false, the entire onboarding route
 * group behaves as if it does not exist. Runs before controller
 * dispatch and before any FormRequest is resolved, so a disabled
 * feature rejects a malformed or well-formed request identically —
 * no onboarding row lookup, no tenant lookup beyond what the inherited
 * route stack already ran, no mutation.
 */
class EnsureBusinessOnboardingIsEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (config('business.onboarding.enabled', false)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return response()->json(['status' => 'error', 'message' => 'Not Found'], 404);
        }

        abort(404);
    }
}
