<?php

namespace App\Http\Middleware;

use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Navigation\CustomerShellComposer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chat F — Customer Account Access Gate.
 *
 * Backend enforcement of CustomerAccountAccessResolver's decision. Registered
 * in the global `web` middleware group, directly after ResolveCustomerContext
 * (Kernel.php), so it runs for every request that middleware also covers —
 * the entire customer.* route family (routes/customer.php) AND the legacy
 * dashboard landing route (user.home / routes/auth.php), which sits outside
 * that route group but is still the product's actual entry point — and it
 * is a no-op for guests, platform admins and API/Sanctum traffic, exactly
 * like ResolveCustomerContext.
 *
 * THIS IS THE SECURITY BOUNDARY, not merely a screen. A customer whose
 * Workspace is locked cannot reach an operational GET or POST/JSON action by
 * URL — every route not explicitly allowlisted is redirected (HTML) or
 * refused with a 403 (JSON/AJAX, "fail closed") before the controller ever
 * runs.
 *
 * THE ALLOWLIST IS DELIBERATELY MINIMAL (product decision, Chat F): only the
 * locked screen itself and the existing read-only Plan & subscription page
 * (the one truthful "Continue to billing" destination today — see
 * CustomerAccountAccessResolver's own docblock for why there is no
 * self-service reactivation route to allow instead). Settings, Team and
 * every other module stay locked even though they "live near" billing.
 * logout is a SEPARATE route registration (routes/auth.php, outside both
 * this middleware's own group and the customer.* group) and is therefore
 * always reachable without needing an entry here at all.
 *
 * CROSS-WORKSPACE CORRECTNESS: the Workspace evaluated is the one the
 * CURRENT request is actually for — the route's own {workspaceUid}
 * parameter when the route carries one, exactly like every controller's own
 * tenancy resolution already does, never a remembered "current" Workspace
 * from a previous request. A route with no {workspaceUid} (the workspace-
 * agnostic dashboard) falls back to the resolved CustomerContext's own frame
 * Workspace — the same one that route's own controller renders. This is
 * what keeps one locked Workspace from ever locking a DIFFERENT, active one
 * for the same actor (an Agency owner moving between client accounts).
 */
class CustomerAccountAccessGate
{
    /**
     * Exact route names only — deliberately not a prefix match, so
     * allowlisting 'customer.workspaces.plan.show' can never accidentally
     * also allow 'customer.workspaces.settings.show' or
     * 'customer.workspaces.team.show' merely because they share the
     * 'customer.workspaces.' prefix.
     */
    private const ALLOWED_ROUTE_NAMES = [
        'customer.account-locked.show',
        'customer.workspaces.plan.show',
        // Registered outside the customer.* route group (routes/auth.php)
        // but still runs through this Kernel-level middleware like every
        // other web route — a locked customer must always be able to sign
        // out, so it must be named here explicitly.
        'logout',
    ];

    public function __construct(
        private readonly CustomerShellComposer $shell,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly CustomerAccountAccessResolver $resolver,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! CustomerShellComposer::isCustomerPortal($user)) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName !== null && in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        $workspace = $this->resolveWorkspaceForRequest($request, $user);
        $decision = $this->resolver->resolve($workspace);

        if (! $decision->isLocked()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'error',
                'message' => $decision->message ?? 'Your account is not currently active.',
                'reason' => $decision->reason,
            ], 403);
        }

        return redirect()->route('customer.account-locked.show');
    }

    /**
     * The route's own {workspaceUid} first (tenancy-verified the same way
     * every controller already resolves it — WorkspaceRepository::findByUid(),
     * never trusted further than that), falling back to the resolved
     * CustomerContext's frame Workspace only for a route that carries no
     * {workspaceUid} at all (the workspace-agnostic dashboard).
     */
    private function resolveWorkspaceForRequest(Request $request, User $user): ?Workspace
    {
        $routeWorkspaceUid = $request->route('workspaceUid');

        if (is_string($routeWorkspaceUid) && $routeWorkspaceUid !== '') {
            return $this->workspaceRepository->findByUid($routeWorkspaceUid);
        }

        $frame = $this->shell->currentContext($user)->frameWorkspace();

        if ($frame === null) {
            return null;
        }

        return $this->workspaceRepository->findByUid($frame->uid);
    }
}
