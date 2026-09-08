<?php

namespace App\Http\Middleware;

use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerContextResolver;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\ViewAs\ViewAsManager;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
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
        private readonly ViewAsRouteClassification $classification,
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
            $route = $request->route();

            // Correction Round 1: every authenticated customer route is
            // placed in a closed class (ViewAsRouteClassification). Nothing
            // is inferred from the URL shape; an unclassified route is
            // treated exactly like a denied one.
            $class = $route === null
                ? ViewAsRouteClass::Denied
                : $this->classification->classify($route, $request->getMethod());

            switch ($class) {
                case ViewAsRouteClass::Prohibited:
                    $this->viewAs->refuse($viewAs, $request);

                    if ($request->expectsJson()) {
                        return response()->json(['status' => 'error', 'message' => $this->prohibited->refusalMessage()], 403);
                    }

                    return redirect()->route('user.home')->with([
                        'status' => 'warning',
                        'message' => $this->prohibited->refusalMessage(),
                    ]);

                case ViewAsRouteClass::BusinessScoped:
                    // Narrowing rule: while viewing one client, every other
                    // Workspace/Business pair is as unreachable as a foreign
                    // one (T-VIEW-4). The route's own tenancy check still runs.
                    if ($route?->parameter('businessUid') !== $viewAs->businessUid
                        || $route?->parameter('workspaceUid') !== $viewAs->workspaceUid) {
                        abort(404);
                    }
                    break;

                case ViewAsRouteClass::RedirectToViewed:
                    $target = $this->classification->redirectTargetFor((string) $route?->getName());

                    if ($target !== null) {
                        return redirect()->route($target, [$viewAs->workspaceUid, $viewAs->businessUid]);
                    }

                    abort(404);

                case ViewAsRouteClass::Safe:
                    break;

                default:
                    // Denied or Unclassified: outside the viewed Business.
                    abort(404);
            }
        }

        $context = $this->resolver->resolve($user, $request, $viewAs);

        $this->container->instance(CustomerContext::class, $context);
        $request->attributes->set('customerContext', $context);

        return $next($request);
    }
}
