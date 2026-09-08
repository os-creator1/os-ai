<?php

namespace App\Library\ViewAs;

use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Workspace\WorkspaceManager;
use App\Models\User;
use App\Models\ViewAsSession;
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
 */
final class ViewAsManager
{
    public const SESSION_KEY = 'view_as_session_uid';

    public const DEFAULT_TTL_MINUTES = 60;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly WorkspaceManager $workspaceManager,
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
        if (! $this->accessChainStillHolds($session, $actor)) {
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
