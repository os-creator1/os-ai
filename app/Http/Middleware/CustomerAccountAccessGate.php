<?php

namespace App\Http\Middleware;

use App\Library\Entitlement\CustomerAccountAccessDecision;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Workspace\AccountFrameAccess;
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
 *
 * PR #302 CORRECTION 2 — three fixes to that same boundary, none of them a
 * second policy:
 *
 * 1. A routed {workspaceUid} is evaluated only once AccountFrameAccess — the
 *    SAME owner-or-active-all-scope-membership rule the account page and
 *    the context switcher already use, App\Library\Workspace\
 *    AccountFrameAccess — proves the actor may actually reach it. A foreign
 *    Workspace's plan state (Active, Inactive or Suspended) is never
 *    evaluated or disclosed; the request passes through untouched and the
 *    route's own controller applies its ordinary 404/authorization denial,
 *    exactly as it would if this gate did not exist.
 *
 * 2. A workspace-agnostic request (no {workspaceUid}) with MULTIPLE
 *    accessible Workspaces and no explicit selection (CustomerContext::
 *    frameWorkspace() === null, hasMultipleWorkspaces() === true) is never
 *    collapsed into the same "usable" case as a brand-new customer with
 *    zero Workspaces. Every accessible Workspace is evaluated together via
 *    CustomerAccountAccessResolver::resolveAmbiguous() — locked only when
 *    ALL of them are locked, so one Inactive Workspace can never contaminate
 *    a genuinely reachable Active one, and no candidate is ever guessed or
 *    silently preferred.
 *
 * 3. The 2FA challenge routes (verify.index, verify.store, verify.resend,
 *    verify.backup, verify.backup.store) are allowlisted: TwoFactor
 *    middleware runs AFTER this one on every routes/auth.php route, so a
 *    locked customer with a pending challenge who was NOT allowed to reach
 *    /verify would be bounced there by TwoFactor, then straight back to the
 *    locked screen by this gate, forever. Login must be able to finish
 *    before the product lock applies.
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
        // PR #302 correction 2 — the 2FA challenge itself (routes/auth.php,
        // Route::resource('verify', ...)->only(['index','store']) plus its
        // two GET siblings and the newly-named backup-code submit). Login
        // must be able to finish before the product lock applies; see the
        // class docblock, point 3.
        'verify.index',
        'verify.store',
        'verify.resend',
        'verify.backup',
        'verify.backup.store',
    ];

    public function __construct(
        private readonly CustomerShellComposer $shell,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly CustomerAccountAccessResolver $resolver,
        private readonly AccountFrameAccess $accountFrameAccess,
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

        $decision = $this->resolveDecisionForRequest($request, $user);

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
     * The route's own {workspaceUid} first, but ONLY once AccountFrameAccess
     * proves the actor may reach it (correction 2, point 1) — a Workspace
     * that fails that check is never evaluated at all, so its plan state is
     * never disclosed; the request passes through and the route's own
     * tenancy authorization decides what happens next, unchanged.
     *
     * A route with no {workspaceUid} (the workspace-agnostic dashboard)
     * falls back to CustomerAccountAccessResolver::resolveForContext() — the
     * SAME method AccountLockedController's own self-check calls, so the two
     * can never independently drift on what "no {workspaceUid}" means
     * (correction 2, point 2).
     */
    private function resolveDecisionForRequest(Request $request, User $user): CustomerAccountAccessDecision
    {
        $routeWorkspaceUid = $request->route('workspaceUid');

        if (is_string($routeWorkspaceUid) && $routeWorkspaceUid !== '') {
            $workspace = $this->workspaceRepository->findByUid($routeWorkspaceUid);

            if ($workspace === null || ! $this->accountFrameAccess->allows($workspace, (int) $user->id)) {
                return CustomerAccountAccessDecision::usable();
            }

            return $this->resolver->resolve($workspace);
        }

        return $this->resolver->resolveForContext($this->shell->currentContext($user));
    }
}
