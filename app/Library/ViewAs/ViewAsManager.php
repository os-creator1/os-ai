<?php

namespace App\Library\ViewAs;

use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\User;
use App\Models\ViewAsSession;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * View as client (contract §5.5) — the replacement for the legacy
 * parent_id impersonation, which is NOT extended (E-38).
 *
 * Locked properties, in code:
 *  - Identity: Auth::id() never changes. A session row is layered on top.
 *  - Entry: POST only; authorized for an active Workspace owner/admin who
 *    can reach the Business through WorkspaceManager::userCanAccessBusiness().
 *  - Authorization: every route still runs against the real actor; the
 *    context resolver and middleware only NARROW to the viewed Business.
 *  - Audit: a durable row on entry and on exit, with the refusals in between.
 *  - Expiry: bounded TTL (§28.2 default 60 minutes), evaluated server-side
 *    on every request that reads the session.
 *  - Exit: explicit, on expiry, and on logout. Exit never logs the actor out.
 *
 * V1 Contract 04 adds a second, separately-named entry, startAgencyView(),
 * for CROSS-Workspace Agency View As through an Active Contract 01
 * relationship, revalidated on every read by its own chain. The
 * same-Workspace start()/actorMayView()/accessChainStillHolds() path above is
 * left unchanged until Contract 14 retires it. No route, controller or UI
 * reaches startAgencyView() yet (Contract 07/08A); the HTTP resolver, the
 * View As middleware and the broadcast channel are deliberately unchanged
 * and stay fail-closed for an Agency session, since each still checks the
 * actor's ordinary tenancy of the viewed Business.
 */
final class ViewAsManager
{
    public const SESSION_KEY = 'view_as_session_uid';

    public const DEFAULT_TTL_MINUTES = 60;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly WorkspaceManager $workspaceManager,
        // V1 Contract 04 §6 — the single source of Agency authority, the
        // active-relationship lookup and the current Agency-tier check for
        // the cross-Workspace path. Never re-implemented here.
        private readonly AgencyClientRelationshipManager $agencyRelationships,
    ) {
    }

    /**
     * Starts a view-as session or aborts with 404 (the §5.4 existence-
     * disclosure rule: an unauthorized pair is indistinguishable from a
     * missing one). Starting again replaces the previous session, so an
     * actor never holds two.
     */
    public function start(User $actor, string $workspaceUid, string $businessUid, ?string $reason = null): ViewAsSession
    {
        $actorId = (int) $actor->id;
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active || ! $this->actorMayView($actorId, $workspace)) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null
            || $business->status !== BusinessStatus::Active
            || ! $this->workspaceManager->userCanAccessBusiness($actorId, $business)) {
            abort(404);
        }

        $this->endAllForActor($actorId, ViewAsSession::END_REASON_REPLACED);

        $now = CarbonImmutable::now();

        $session = ViewAsSession::create([
            'actor_user_id' => $actorId,
            'workspace_id' => $workspace->id,
            'business_id' => $business->id,
            'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'started_at' => $now,
            'expires_at' => $now->addMinutes(self::DEFAULT_TTL_MINUTES),
            'refusals' => [],
        ]);

        session([self::SESSION_KEY => $session->uid]);

        return $session;
    }

    /**
     * V1 Contract 04 — starts a CROSS-Workspace Agency View As session, or
     * aborts with 404 on every failure (the same existence-disclosure rule as
     * start(): an unauthorized, unlinked, missing or malformed target is
     * indistinguishable from an absent one, never a confirming 403).
     *
     * Deliberately a separate, explicitly-named entry point, not an overload
     * of start(): the two authorization paths must never be confusable at a
     * call site. start() authorizes membership of the VIEWED Workspace; this
     * authorizes Agency standing in a DIFFERENT Workspace plus an Active
     * Contract 01 relationship to the viewed one. It never calls
     * WorkspaceManager::userCanAccessBusiness() — that is ordinary Client
     * Workspace tenancy, which correctly refuses an Agency actor who is not
     * a Client member.
     *
     * Starts only when ALL hold:
     *  1. the Agency Workspace exists and is active, and the actor has
     *     Agency authority in it — AgencyClientRelationshipManager::
     *     actorHasAgencyAuthority(), the one definition (the exact owner, or
     *     an ACTIVE Admin/Staff membership in exactly that Agency Workspace;
     *     customer permissions, platform status, other Workspaces'
     *     memberships and Client Workspace membership count for nothing);
     *  2. the Client Workspace exists, is active, and is not the Agency
     *     Workspace itself;
     *  3. an ACTIVE relationship links exactly this Agency to this Client;
     *  4. the Agency Workspace has management eligibility right now —
     *     AgencyClientRelationshipManager::agencyWorkspaceHasManagementEligibility():
     *     Agency tier AND a usable account per CustomerAccountAccessResolver
     *     (Trial, Active and Grace qualify; Locked, Inactive and Suspended
     *     do not);
     *  5. the Client Workspace holds exactly one Business, and it is active.
     *     No businessUid is accepted: the Business is the Client Workspace's
     *     sole Business by construction, and zero or more than one is a
     *     data-integrity contradiction that fails closed — never a guess.
     *
     * Like start(), starting again replaces the actor's previous session.
     * The row records the real acting User; nothing about identity changes.
     */
    public function startAgencyView(
        User $actor,
        string $agencyWorkspaceUid,
        string $clientWorkspaceUid,
        ?string $reason = null,
    ): ViewAsSession {
        $actorId = (int) $actor->id;

        $agencyWorkspace = $this->workspaceRepository->findByUid($agencyWorkspaceUid);

        if ($agencyWorkspace === null
            || ! $agencyWorkspace->is_active
            || ! $this->agencyRelationships->actorHasAgencyAuthority($actorId, $agencyWorkspace)) {
            abort(404);
        }

        $clientWorkspace = $this->workspaceRepository->findByUid($clientWorkspaceUid);

        if ($clientWorkspace === null
            || ! $clientWorkspace->is_active
            || (int) $clientWorkspace->id === (int) $agencyWorkspace->id
            || ! $this->activeRelationshipLinks($agencyWorkspace, $clientWorkspace)
            || ! $this->agencyRelationships->agencyWorkspaceHasManagementEligibility($agencyWorkspace)) {
            abort(404);
        }

        $business = $this->soleBusinessOf($clientWorkspace);

        if ($business === null || $business->status !== BusinessStatus::Active) {
            abort(404);
        }

        $this->endAllForActor($actorId, ViewAsSession::END_REASON_REPLACED);

        $now = CarbonImmutable::now();

        $session = ViewAsSession::create([
            'actor_user_id' => $actorId,
            'workspace_id' => $clientWorkspace->id,
            'viewing_agency_workspace_id' => $agencyWorkspace->id,
            'business_id' => $business->id,
            'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'started_at' => $now,
            'expires_at' => $now->addMinutes(self::DEFAULT_TTL_MINUTES),
            'refusals' => [],
        ]);

        session([self::SESSION_KEY => $session->uid]);

        return $session;
    }

    /**
     * The active session for this actor, or null. An expired session is
     * ended (audited) here and forgotten, which is how expiry "returns the
     * actor to the Agency frame" (T-VIEW-2) without a scheduler.
     */
    public function current(User $actor): ?ViewAsContext
    {
        $uid = session(self::SESSION_KEY);

        if (! is_string($uid) || $uid === '') {
            return null;
        }

        $session = ViewAsSession::query()
            ->with(['business', 'workspace', 'actor'])
            ->where('uid', $uid)
            ->where('actor_user_id', (int) $actor->id)
            ->whereNull('ended_at')
            ->first();

        if ($session === null) {
            session()->forget(self::SESSION_KEY);

            return null;
        }

        $now = CarbonImmutable::now();

        if ($session->expires_at->lessThanOrEqualTo($now)) {
            $this->end($session, ViewAsSession::END_REASON_EXPIRED);
            session()->forget(self::SESSION_KEY);

            return null;
        }

        // Correction Round 1 — every request re-validates the authoritative
        // access chain, not merely row existence: the Workspace is active,
        // the Business is active and still inside that Workspace, the actor
        // is still the Workspace owner or an active Admin, and the actor
        // can still reach the Business through the canonical RFC-003 §14.1
        // decision. Any loss ends the durable row atomically as
        // access_lost, forgets the session key and restores the actor's
        // normal context — never a zombie session that keeps applying
        // prohibited-action restrictions, never a widening.
        //
        // V1 Contract 04: a cross-Workspace Agency session (non-null
        // viewing_agency_workspace_id) is revalidated by its OWN chain,
        // textually separate from the same-Workspace one so Contract 14 can
        // delete the old path without touching the new. A same-Workspace
        // session takes exactly the path it always did.
        $agencyWorkspace = null;

        if ($session->viewing_agency_workspace_id !== null) {
            $agencyWorkspace = $this->workspaceRepository->findById((int) $session->viewing_agency_workspace_id);
            $endReason = $this->agencyAccessChainEndReason($session, $actor);

            if ($endReason !== null) {
                $this->end($session, $endReason);
                session()->forget(self::SESSION_KEY);

                return null;
            }
        } elseif (! $this->accessChainStillHolds($session, $actor)) {
            $this->end($session, ViewAsSession::END_REASON_ACCESS_LOST);
            session()->forget(self::SESSION_KEY);

            return null;
        }

        return new ViewAsContext(
            sessionId: (int) $session->id,
            uid: (string) $session->uid,
            actorUserId: (int) $session->actor_user_id,
            actorDisplayName: $session->actor?->displayName() ?? 'Agency user',
            workspaceId: (int) $session->workspace_id,
            workspaceUid: (string) $session->workspace->uid,
            businessId: (int) $session->business_id,
            businessUid: (string) $session->business->uid,
            businessName: (string) $session->business->name,
            startedAt: $session->started_at,
            expiresAt: $session->expires_at,
            viewingAgencyWorkspaceId: $agencyWorkspace !== null ? (int) $agencyWorkspace->id : null,
            viewingAgencyWorkspaceUid: $agencyWorkspace !== null ? (string) $agencyWorkspace->uid : null,
        );
    }

    /**
     * Explicit exit. Never logs the actor out; only ends the view context.
     */
    public function exit(User $actor, string $reason = ViewAsSession::END_REASON_EXIT): void
    {
        $this->endAllForActor((int) $actor->id, $reason);
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Ends every open session for the actor (logout listener, replacement).
     */
    public function endAllForActor(int $actorUserId, string $reason): int
    {
        $open = ViewAsSession::query()->where('actor_user_id', $actorUserId)->whereNull('ended_at')->get();

        foreach ($open as $session) {
            $this->end($session, $reason);
        }

        return $open->count();
    }

    /**
     * Records a refused prohibited action on the audit row (§5.5: "the
     * refusal is audited").
     */
    public function refuse(ViewAsContext $context, Request $request): void
    {
        $session = ViewAsSession::query()->find($context->sessionId);

        if ($session === null) {
            return;
        }

        $refusals = $session->refusals ?? [];
        $refusals[] = [
            'route' => (string) ($request->route()?->getName() ?? ''),
            'method' => $request->getMethod(),
            'path' => '/' . ltrim($request->path(), '/'),
            'at' => CarbonImmutable::now()->toIso8601String(),
        ];

        $session->refusals = $refusals;
        $session->save();
    }

    /**
     * The authoritative view-as access chain, re-evaluated from persisted
     * rows on every read (Correction Round 1):
     *  1. the Workspace row exists and is active;
     *  2. the Business row exists, is active, and still belongs to the
     *     session's Workspace (a moved Business ends the view);
     *  3. the actor is still the Workspace owner or an ACTIVE Admin member
     *     (a deactivated or demoted membership ends the view);
     *  4. the actor can still reach the Business through
     *     WorkspaceManager::userCanAccessBusiness() (a revoked assignment
     *     ends the view).
     */
    private function accessChainStillHolds(ViewAsSession $session, User $actor): bool
    {
        $workspace = $this->workspaceRepository->findById((int) $session->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            return false;
        }

        $business = $session->business;

        if ($business === null
            || (int) $business->workspace_id !== (int) $workspace->id
            || $business->status !== BusinessStatus::Active) {
            return false;
        }

        if (! $this->actorMayView((int) $actor->id, $workspace)) {
            return false;
        }

        return $this->workspaceManager->userCanAccessBusiness((int) $actor->id, $business);
    }

    /**
     * V1 Contract 04 §6/§9 — the cross-Workspace Agency access chain
     * (the contract's agencyAccessChainStillHolds()), re-evaluated from
     * persisted rows on EVERY read, never trusting row existence. Returns
     * null while the session still holds, or the end reason to record:
     *
     *  1. an ACTIVE relationship still links the session's Agency Workspace
     *     to the viewed Client Workspace — else relationship_ended;
     *  2. the Agency Workspace still has management eligibility — Agency
     *     tier AND a usable account (a Locked, Inactive or Suspended Agency
     *     is not eligible) — else agency_entitlement_lost (the relationship
     *     alone is never eligibility proof; the two causes are reported
     *     separately, and no separate lifecycle end reason is invented);
     *  3. the actor still has Agency authority in that Agency Workspace
     *     (AgencyClientRelationshipManager::actorHasAgencyAuthority(), the
     *     same definition start used) — else access_lost;
     *  4. both Workspaces still exist and are active, and the viewed
     *     Business still exists, is active, still belongs to the Client
     *     Workspace and is still that Workspace's sole Business — else
     *     access_lost.
     *
     * The relationship and entitlement checks run first so that, when a
     * relationship ends or a plan lapses, the audit row records that root
     * cause rather than a generic access loss. Ordinary Client Workspace
     * tenancy (WorkspaceManager::userCanAccessBusiness()) is deliberately
     * NOT consulted: it correctly refuses an Agency actor.
     */
    private function agencyAccessChainEndReason(ViewAsSession $session, User $actor): ?string
    {
        $agencyWorkspace = $this->workspaceRepository->findById((int) $session->viewing_agency_workspace_id);
        $clientWorkspace = $this->workspaceRepository->findById((int) $session->workspace_id);

        if ($agencyWorkspace === null || $clientWorkspace === null) {
            return ViewAsSession::END_REASON_ACCESS_LOST;
        }

        if (! $this->activeRelationshipLinks($agencyWorkspace, $clientWorkspace)) {
            return ViewAsSession::END_REASON_RELATIONSHIP_ENDED;
        }

        if (! $this->agencyRelationships->agencyWorkspaceHasManagementEligibility($agencyWorkspace)) {
            return ViewAsSession::END_REASON_AGENCY_ENTITLEMENT_LOST;
        }

        if (! $this->agencyRelationships->actorHasAgencyAuthority((int) $actor->id, $agencyWorkspace)) {
            return ViewAsSession::END_REASON_ACCESS_LOST;
        }

        if (! $agencyWorkspace->is_active || ! $clientWorkspace->is_active) {
            return ViewAsSession::END_REASON_ACCESS_LOST;
        }

        $business = $session->business;
        $sole = $this->soleBusinessOf($clientWorkspace);

        if ($business === null
            || $sole === null
            || (int) $sole->id !== (int) $business->id
            || (int) $business->workspace_id !== (int) $clientWorkspace->id
            || $business->status !== BusinessStatus::Active) {
            return ViewAsSession::END_REASON_ACCESS_LOST;
        }

        return null;
    }

    /**
     * Whether the Client Workspace's one ACTIVE managing relationship is with
     * exactly this Agency Workspace — read fresh through the Contract 01
     * manager on every call (a Client Workspace has 0 or 1 active managing
     * Agency, so a relationship to any other Agency is no link at all).
     */
    private function activeRelationshipLinks(Workspace $agencyWorkspace, Workspace $clientWorkspace): bool
    {
        $relationship = $this->agencyRelationships->findActiveForClientWorkspace((int) $clientWorkspace->id);

        return $relationship !== null
            && (int) $relationship->agency_workspace_id === (int) $agencyWorkspace->id;
    }

    /**
     * The Client Workspace's sole Business, or null when it holds zero or
     * more than one. V1 guarantees exactly one per Client Workspace; anything
     * else is an integrity contradiction to fail closed on, never to resolve
     * by picking one.
     */
    private function soleBusinessOf(Workspace $clientWorkspace): ?Business
    {
        $businesses = $this->workspaceRepository->businessesForWorkspace($clientWorkspace);

        return $businesses->count() === 1 ? $businesses->first() : null;
    }

    /**
     * Owner, or an ACTIVE membership with role Admin — the same authority
     * rule WorkspaceManager applies to Workspace management.
     */
    private function actorMayView(int $actorId, $workspace): bool
    {
        if ((int) $workspace->owner_user_id === $actorId) {
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $actorId);

        return $membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin;
    }

    private function end(ViewAsSession $session, string $reason): void
    {
        $session->ended_at = CarbonImmutable::now();
        $session->end_reason = $reason;
        $session->save();
    }
}
