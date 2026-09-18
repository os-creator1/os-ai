<?php

namespace App\Library\Workspace;

use App\Library\ViewAs\ViewAsContext;
use App\Library\ViewAs\ViewAsManager;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * The ONE decision every Business-addressed customer surface asks before it
 * serves a Workspace/Business pair:
 *
 *     while a View-As session is active — its exact target, and nothing else;
 *     otherwise                        — ordinary WorkspaceManager tenancy.
 *
 * Both halves matter. The first ADMITS a pair ordinary tenancy refuses (the
 * Client a cross-Workspace Agency actor is genuinely viewing) and REFUSES
 * pairs ordinary tenancy would admit (every other Business the actor happens
 * to be a tenant of), because View As only ever narrows.
 *
 * WHY THIS EXISTS. Until V1 Contract 04, "may this actor use this Business
 * route" and "is this actor a tenant of this Business" were the same
 * question, so every Business-scoped surface asked
 * WorkspaceManager::userCanAccessBusiness() directly. Cross-Workspace Agency
 * View As separates them: an Agency actor viewing a managed Client is
 * deliberately NOT a tenant of that Client Workspace — userCanAccessBusiness()
 * correctly answers false, and must keep answering false (it is ordinary
 * tenancy authority and nothing else). The actor's authority to act inside
 * the viewed Business comes from the View-As session instead, and only from
 * it.
 *
 * Asking userCanAccessBusiness() alone therefore refused a whole class of
 * requests the product allows: while a valid session is active, View As is
 * true impersonation, so a Conversations reply, a retry or any other allowed
 * Business-scoped route must work exactly as it would for the customer. This
 * class is the single place that rule is written, so the shared Business
 * tenancy trait, the Conversations controller and the inbox broadcast channel
 * cannot drift into three different answers.
 *
 * WHAT IT DOES NOT DO:
 *  - it never widens ordinary tenancy: without a session the answer is
 *    verbatim WorkspaceManager's, unchanged in semantics, and that is every
 *    ordinary request the product serves;
 *  - it never grants anything from an Agency RELATIONSHIP by itself. The
 *    grant exists only while ViewAsManager::current() — the canonical
 *    authority, which re-reads and re-validates the relationship, the Agency's
 *    management eligibility, the actor's Agency authority, both Workspaces'
 *    active state and the Client's sole active Business on EVERY read —
 *    returns a session, and only for that session's exact target;
 *  - it never trusts request state. current() is asked for itself rather than
 *    read from the request's CustomerContext, so a forged or hand-shaped
 *    request attribute cannot manufacture a grant;
 *  - it never reaches past the viewed pair: both the Workspace id AND the
 *    Business id must equal the session's own, so the viewed Business in a
 *    foreign Workspace, and a foreign Business in the viewed Workspace, are
 *    refused as firmly as an unrelated tenant's.
 *
 * ResolveCustomerContext's narrowing (prohibited actions, and a 404 for any
 * BusinessScoped route whose pair is not the viewed one) stays exactly as it
 * was: this decision is the independent, fail-closed second line beneath it,
 * never a replacement for it.
 */
final class BusinessRouteAccess
{
    public function __construct(
        private readonly WorkspaceManager $workspaceManager,
        private readonly ViewAsManager $viewAs,
    ) {
    }

    /**
     * Whether this actor may use a route addressed to exactly this
     * Workspace/Business pair — for callers holding an ADDRESSED Workspace
     * (a route's own `workspaceUid`), where the address itself must be the
     * viewed one and not merely the Business's.
     */
    public function actorMayUseBusinessRoute(User $actor, Workspace $workspace, Business $business): bool
    {
        return $this->decide($actor, (int) $workspace->id, $business);
    }

    /**
     * Whether this actor may act on this Business when no Workspace was
     * addressed separately — the shape a guard deeper than the route sees,
     * where the only Workspace in play is the Business's own.
     *
     * The same one rule as actorMayUseBusinessRoute(), reached with the
     * Business's own workspace_id: these are two entry points into a single
     * decision, never two decisions. Nothing is looser here — a session still
     * has to name this exact Business, and the Business still has to sit in
     * the Workspace that session named.
     */
    public function actorMayUseBusiness(User $actor, Business $business): bool
    {
        return $this->decide($actor, (int) $business->workspace_id, $business);
    }

    /**
     * Whether an already-revalidated session targets exactly this pair. For a
     * caller that has just obtained the canonical ViewAsContext and should
     * not pay for it twice.
     */
    public function viewAsTargets(?ViewAsContext $viewAs, Workspace $workspace, Business $business): bool
    {
        return $this->targets($viewAs, (int) $workspace->id, $business);
    }

    /**
     * The Business a valid View-As session currently points this actor at, or
     * null when there is no such session.
     *
     * For the OPERATIONAL services beneath the route — the Location ACL guard
     * and the Business location manager — whose own authority tables are
     * richer than "can reach the Business" and whose signatures carry an actor
     * id rather than a User. They need two facts in one answer: whether a
     * session is narrowing this actor at all (so they know to set their
     * ordinary table aside rather than consult it), and which single Business
     * it names (so they can admit exactly that one).
     *
     * This is the SAME canonical authority the decisions above use, exposed as
     * a target rather than a verdict — never a second algorithm, and never an
     * Agency-relationship read of its own. A caller can do exactly one thing
     * with the answer: compare it to the Business in front of it. Ordinary
     * requests get null and keep their existing rules untouched, which is why
     * nothing here can loosen ordinary tenancy or the Location ACL.
     */
    public function viewedBusinessIdFor(int $actorId): ?int
    {
        // ONLY ever asked about the authenticated actor. A View-As session
        // lives in the browser session of the user who started it, so "is
        // some OTHER user being viewed-as right now" is not a question this
        // request can answer — and asking it is actively harmful: current()
        // looks the session row up by uid AND actor_user_id, so a mismatched
        // id finds nothing and FORGETS the session key, silently ending the
        // real actor's live session mid-request. (That is not hypothetical:
        // it is exactly what happened when a service resolved one user's
        // authority while another was signed in.) Anyone who is not the
        // authenticated user is simply not viewing, and falls through to the
        // caller's ordinary rules.
        $actor = Auth::user();

        if (! $actor instanceof User || (int) $actor->id !== $actorId) {
            return null;
        }

        $viewAs = $this->viewAs->current($actor);

        return $viewAs === null ? null : (int) $viewAs->businessId;
    }

    private function decide(User $actor, int $workspaceId, Business $business): bool
    {
        $viewAs = $this->viewAs->current($actor);

        // While a session is active it is the WHOLE answer, in both
        // directions: it admits its own exact target (which ordinary tenancy
        // would refuse for a cross-Workspace Agency actor) and it refuses
        // everything else (which ordinary tenancy would otherwise admit, for
        // an actor viewing one of their own Businesses while a session names
        // another). View As only ever narrows, so "I am a tenant of this
        // other Business" must not survive it — that is the rule that keeps
        // a viewing actor inside the client they are viewing.
        if ($viewAs !== null) {
            return $this->targets($viewAs, $workspaceId, $business);
        }

        return $this->workspaceManager->userCanAccessBusiness((int) $actor->id, $business);
    }

    private function targets(?ViewAsContext $viewAs, int $workspaceId, Business $business): bool
    {
        return $viewAs !== null
            && (int) $viewAs->workspaceId === $workspaceId
            && (int) $viewAs->businessId === (int) $business->id;
    }
}
