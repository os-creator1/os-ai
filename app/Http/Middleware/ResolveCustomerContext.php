<?php

namespace App\Http\Middleware;

use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerContextResolver;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\ViewAs\ViewAsManager;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer Experience Slice 1B — resolves the account context once per
 * request for customer-portal users and applies the View-as-client layer
 * (contract §5.5). Registered in the `web` group so the landing page
 * (routes/auth.php `user.home`) and every routes/customer.php route see
 * the same context; it does nothing for guests, admins or API traffic.
 *
 * It grants nothing: every route keeps its own server-side tenancy check.
 * What it does:
 *  1. ends an expired view-as session and, while one is active, refuses
 *     the §5.5 prohibited actions (audited) and forbids reaching any OTHER
 *     Business's route with a 404 — view-as only ever narrows;
 *  2. resolves the CustomerContext (CustomerContextResolver) and binds it
 *     for the shell composer, the menu builder and the switcher actions.
 */
class ResolveCustomerContext
{
    public function __construct(
        private readonly Container $container,
        private readonly CustomerContextResolver $resolver,
        private readonly ViewAsManager $viewAs,
        private readonly ViewAsProhibitedActions $prohibited,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! CustomerShellComposer::isCustomerPortal($user)) {
            return $next($request);
        }

        $viewAs = $this->viewAs->current($user);

        if ($viewAs !== null) {
            if ($this->prohibited->isProhibited($request)) {
                $this->viewAs->refuse($viewAs, $request);

                if ($request->expectsJson()) {
                    return response()->json(['status' => 'error', 'message' => $this->prohibited->refusalMessage()], 403);
                }

                return redirect()->route('user.home')->with([
                    'status' => 'warning',
                    'message' => $this->prohibited->refusalMessage(),
                ]);
            }

            $routeBusinessUid = $request->route()?->parameter('businessUid');

            if (is_string($routeBusinessUid) && $routeBusinessUid !== '' && $routeBusinessUid !== $viewAs->businessUid) {
                // Narrowing rule: while viewing one client, every other
                // Business is as unreachable as a foreign one (T-VIEW-4).
                abort(404);
            }
        }

        $context = $this->resolver->resolve($user, $request, $viewAs);

        $this->container->instance(CustomerContext::class, $context);
        $request->attributes->set('customerContext', $context);

        return $next($request);
    }
}
