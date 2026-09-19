<?php

namespace App\Library\Coo\Context;

use App\Enums\Coo\CooScope;
use App\Library\ViewAs\ViewAsContext;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\AccountRepository;

/**
 * Implementation Contract 19 §5.2, §5.9b — the only place a
 * CooContextEnvelope is assembled.
 *
 * Two entry points, because the COO has exactly two kinds of caller and they
 * differ in *whose* authorization the answer is computed for:
 *
 *  - forActor(): a human asked. The envelope carries the real acting human,
 *    their own live authorization set, and — under View As — the session and
 *    the viewed Business, so a View-As read can never share a cache entry with
 *    an ordinary one (§6.4).
 *
 *  - forBackgroundAudience(): nobody asked; a schedule fired. There is no
 *    actor, so R-30 requires a DECLARED audience rather than an inferred or
 *    empty authorization set: the canonical Workspace owner
 *    (Workspace::owner() → owner_user_id). `actor_user_id` stays NULL because
 *    the owner did not request the generation; `audience_user_id` records
 *    whose authorization the fingerprint was computed from, and is never
 *    attribution.
 *
 * Both fail closed on the things that make an authorization claim
 * meaningless: a Business with no Workspace at all, and — for a background
 * generation, which is the one that must declare an audience — an inactive
 * Workspace or an unresolvable owner. Each yields null, so nothing is
 * generated and nothing is read rather than a fabricated claim being invented
 * (R-30).
 *
 * An EMPTY authorized Location set is not one of those cases, and deliberately
 * so. It is an honest authorization fact with two honest causes — the Business
 * has no Locations at all, or this actor may read none of the ones it has —
 * and both belong in the fingerprint rather than in an exception. The safety
 * follows automatically: an actor who reads no Location of a multi-Location
 * Business fingerprints differently from the owner who reads them all, so the
 * owner's cached answer can never be served to them (R-23, R-31). Whether such
 * an actor may compose any facts at all is a permission question, and 19.B's
 * permission-aware fact composition owns it.
 *
 * 19.A assembles `CooScope::Business` only. Agency and Platform envelopes are
 * 19.H's, and the writer refuses them until then.
 */
final class CooContextEnvelopeFactory
{
    public function __construct(
        private readonly LocationAccessGuard $locations,
        private readonly AccountRepository $accounts,
    ) {
    }

    /**
     * A human-initiated envelope: Ask, Explain, Draft, and every Home read.
     *
     * `$viewAs` is the resolved View-As context when an Agency user is acting
     * while viewing this client, and null otherwise. The actor is always the
     * real human — never the viewed client (§5.2, §6.4).
     */
    public function forActor(Business $business, User $actor, ?ViewAsContext $viewAs = null): ?CooContextEnvelope
    {
        // The Workspace id is a column on the Business, so the read path costs
        // no extra query for it. Workspace liveness is deliberately NOT
        // re-checked here: LocationAccessGuard already refuses an inactive
        // Workspace (it returns no authorized Location), and Home reached this
        // point only because the request's own tenancy resolution had already
        // re-authorized the Business. Adding a second liveness query would
        // make a render cost more without deciding anything new.
        if ($business->workspace_id === null) {
            return null;
        }

        $locationIds = $this->locations->authorizedLocationIdsFor((int) $actor->id, $business);

        return new CooContextEnvelope(
            scope: CooScope::Business,
            workspaceId: (int) $business->workspace_id,
            businessId: (int) $business->id,
            authorizedLocationIds: $locationIds,
            capabilityKeys: $this->capabilitiesHeldBy($actor),
            actorUserId: (int) $actor->id,
            audienceUserId: null,
            viewAsSessionId: $viewAs?->sessionId,
            viewAsTargetBusinessId: $viewAs?->businessId,
            businessLocationId: null,
        );
    }

    /**
     * A background generation's envelope, computed for the declared audience
     * (§5.9b R-30) and never for "everyone".
     */
    public function forBackgroundAudience(Business $business): ?CooContextEnvelope
    {
        $business->loadMissing('workspace.owner');

        $workspace = $business->workspace;
        $owner = $workspace?->owner;

        if ($workspace === null || ! $workspace->is_active || ! $owner instanceof User) {
            return null;
        }

        $locationIds = $this->locations->authorizedLocationIdsFor((int) $owner->id, $business);

        return new CooContextEnvelope(
            scope: CooScope::Business,
            workspaceId: (int) $workspace->id,
            businessId: (int) $business->id,
            authorizedLocationIds: $locationIds,
            capabilityKeys: $this->capabilitiesHeldBy($owner),
            actorUserId: null,
            audienceUserId: (int) $owner->id,
            viewAsSessionId: null,
            viewAsTargetBusinessId: null,
            businessLocationId: null,
        );
    }

    /**
     * R-24 — the consulted vocabulary, filtered to what this user actually
     * holds.
     *
     * DURABLE, NOT SESSION-DERIVED, and that is load-bearing rather than
     * fussy. The application's permission check prefers a session copy and
     * falls back to the stored set; a queued background generation has no
     * session at all, so the same user would resolve a different capability
     * set inside a job than inside a request, and the fingerprint a job wrote
     * could never be matched by the request that reads it back. R-21 also
     * forbids anything session-derived from entering the identity. So the
     * capability set is read from AccountRepository::durablePermissions() —
     * the durable half of the one existing permission mechanism, not a second
     * one (R-0).
     *
     * `users.id === 1` is this application's unconditional super-admin, which
     * durablePermissions() deliberately does not encode; it is honoured here
     * so the capability set matches what the gate would actually allow.
     *
     * @return array<int, string>
     */
    private function capabilitiesHeldBy(User $user): array
    {
        if ((int) $user->id === 1) {
            return CooCapabilityKeys::CONSULTED;
        }

        $held = $this->accounts->durablePermissions($user, fresh: true);

        return array_values(array_filter(
            CooCapabilityKeys::CONSULTED,
            static fn (string $key): bool => $held->contains($key),
        ));
    }
}
