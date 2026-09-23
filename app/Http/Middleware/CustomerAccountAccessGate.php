<?php

namespace App\Http\Middleware;

use App\Library\Entitlement\CustomerAccountAccessDecision;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Workspace\AccountFrameAccess;
use App\Library\Workspace\BusinessRouteAccess;
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
 *
 * PR #302 CORRECTION 3 — two more fixes to the same boundary:
 *
 * 4. (finding C) A route carrying BOTH {workspaceUid} AND {businessUid} is
 *    no longer decided by AccountFrameAccess alone. AccountFrameAccess only
 *    ever answers "may this actor stand in the Workspace's own ACCOUNT
 *    FRAME" (owner, or an active all-scope member) — it says nothing about
 *    a direct Business owner or a selected-scope member authorized for
 *    exactly this Business, both of which the routed controller itself
 *    authorizes via the canonical BusinessRouteAccess decision (ordinary
 *    WorkspaceManager tenancy, or the exact valid View-As target, so a
 *    viewed Client account's own state is the one evaluated).
 *    Treating "fails AccountFrameAccess" as "foreign Workspace, pass
 *    through unevaluated" let such an actor's operational Business writes
 *    (e.g. a location update) bypass the lock entirely. When {businessUid}
 *    is present, the routed Business is first proven to belong to the
 *    routed Workspace (WorkspaceRepository::businessesForWorkspace(),
 *    the same lookup ContactsController::currentBusinessContext() and
 *    ResolvesBusinessTenancy already use) and then proven reachable via
 *    that same BusinessRouteAccess decision — never a second tenancy
 *    algorithm — before
 *    the Workspace lock is evaluated. Failing either still passes through
 *    unevaluated, exactly like finding C's foreign-Workspace case: the
 *    route's own tenancy authorization decides what happens next.
 *
 * 5. (finding F) 'customer.view-as.exit' is allowlisted alongside logout: a
 *    viewed Workspace that becomes locked during an active View-as session
 *    must still let the actor explicitly end that session. It carries no
 *    {workspaceUid}, so without this it fell to the workspace-agnostic
 *    path, resolved the (locked) viewed Workspace, and redirected to the
 *    locked screen before ExitViewAsAction ever ran.
 *
 * IMPLEMENTATION CONTRACT 07 — 'client-invitations.claim'/'.accept' are
 * allowlisted for the same structural reason as the 2FA challenge routes
 * above: they carry no {workspaceUid} of their own, so without this they
 * would fall to the workspace-agnostic path and be decided by the
 * accepting actor's CURRENT/other Workspace(s) — Workspace(s) wholly
 * unrelated to the one this flow is about to create. Accepting an
 * invitation always creates a brand-new, independent Client Workspace; the
 * lifecycle state of a User's existing Workspace A must never gate their
 * ability to accept ownership of a new Workspace B (Contract 07 §5 —
 * "the same global User may accept while retaining independent
 * memberships elsewhere"). This is not an operational-access bypass:
 * acceptance still requires a valid, Pending, unexpired invitation and
 * token; an authenticated User whose normalized email matches; a
 * completed 2FA challenge (TwoFactor middleware, unaffected by this
 * allowlist); current Agency eligibility (re-asserted fresh by Contract
 * 01's own create()); and the whole five-step atomic transaction to
 * succeed. Every OTHER route addressing that existing locked/inactive/
 * suspended Workspace remains exactly as gated as before — this allowlist
 * names these two routes only, never a prefix.
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
        // Implementation Contract 21 §10.4 — RESTARTING A SUBSCRIPTION AFTER
        // IT ENDED.
        //
        // A fully canceled account is LOCKED, which is correct: the service
        // has stopped. But the locked screen's own recovery action sends the
        // customer to 'customer.workspaces.plan.show', which is allowlisted
        // above precisely so they can put that right — and the button it
        // offers them there is this one. Without these two names the form
        // would render on a reachable page and then bounce straight back to
        // the locked screen, which is the dead end §10.4 exists to remove.
        //
        // Neither route grants any product access: one opens a hosted
        // Checkout Session, the other re-reads its result from the provider.
        // Access returns only when the provider confirms a new subscription
        // and EntitlementManager's own writer clears the lock. Both are still
        // owner-or-active-Admin, answered 404 otherwise, inside the
        // controller.
        'customer.workspaces.plan.resubscribe',
        'customer.workspaces.plan.resubscribe-return',
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
        // PR #302 correction 3, finding F — ending an active View-as
        // session must remain possible even when the viewed Workspace is
        // itself locked; see the class docblock, point 5.
        'customer.view-as.exit',
        // Implementation Contract 07 — accepting a client invitation
        // creates a brand-new, independent Client Workspace; it must never
        // be gated by an unrelated existing Workspace's lifecycle state.
        // See the class docblock's "IMPLEMENTATION CONTRACT 07" section.
        'client-invitations.claim',
        'client-invitations.accept',
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
     * The route's own {workspaceUid} first. When the route ALSO carries a
     * {businessUid}, the Business-level authorization path decides
     * reachability (correction 3, finding C) — never AccountFrameAccess,
     * which only ever answers the narrower "may stand in the account
     * frame" question. Without a {businessUid}, AccountFrameAccess is the
     * right — and unchanged — question (correction 2, point 1). Either way,
     * a Workspace/Business the actor cannot reach is never evaluated at
     * all, so its plan state is never disclosed; the request passes through
     * and the route's own tenancy authorization decides what happens next.
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

            if ($workspace === null) {
                return CustomerAccountAccessDecision::usable();
            }

            $routeBusinessUid = $request->route('businessUid');

            if (is_string($routeBusinessUid) && $routeBusinessUid !== '') {
                $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $routeBusinessUid);

                if ($business === null || ! app(BusinessRouteAccess::class)->actorMayUseBusinessRoute($user, $workspace, $business)) {
                    return CustomerAccountAccessDecision::usable();
                }

                return $this->resolver->resolve($workspace);
            }

            if (! $this->accountFrameAccess->allows($workspace, (int) $user->id)) {
                return CustomerAccountAccessDecision::usable();
            }

            return $this->resolver->resolve($workspace);
        }

        return $this->resolver->resolveForContext($this->shell->currentContext($user));
    }
}
