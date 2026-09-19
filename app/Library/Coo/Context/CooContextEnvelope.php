<?php

namespace App\Library\Coo\Context;

use App\Enums\Coo\CooScope;
use InvalidArgumentException;

/**
 * Implementation Contract 19 §5.2 — the one immutable value object every COO
 * request carries, at every stage.
 *
 * R-2, assemble once and narrow only: no COO component reads tenancy or
 * permission state for itself; it receives this. A component may narrow the
 * envelope (pin a Location); nothing may widen it. An envelope is always
 * re-assembled from live state, never restored from storage, so the
 * fingerprint it carries is by construction a fresh authorization claim
 * (R-22).
 *
 * ACTOR vs AUDIENCE, the distinction this class exists to keep honest:
 *
 *  - `actorUserId` is the real authenticated human, derived server-side from
 *    the authenticated principal. It is NULL for a background generation,
 *    because no human asked for one, and it is never the viewed client under
 *    View As — it is the Agency user who is really acting.
 *  - `audienceUserId` is the declared audience of a background generation
 *    (§5.9b R-30): the canonical Workspace owner, whose authorization set the
 *    fingerprint was computed from. It is an authorization input, never audit
 *    attribution, and it is never presented or joined as if it were the acting
 *    human.
 *
 * The two are mutually exclusive by construction, and the constructor refuses
 * any other combination.
 */
final class CooContextEnvelope
{
    /**
     * Every Location the actor (or the declared audience) may read, sorted
     * ascending. Not a display filter — the authorization set itself (R-23).
     *
     * @var array<int, int>
     */
    public readonly array $authorizedLocationIds;

    /**
     * The subset of CooCapabilityKeys::CONSULTED this actor holds, sorted.
     *
     * @var array<int, string>
     */
    public readonly array $capabilityKeys;

    /** §5.8 — the cache identity, computed here and never accepted from a caller. */
    public readonly string $authorizationScopeFingerprint;

    /**
     * @param  array<int, int>  $authorizedLocationIds
     * @param  array<int, string>  $capabilityKeys
     */
    public function __construct(
        public readonly CooScope $scope,
        public readonly ?int $workspaceId,
        public readonly ?int $businessId,
        array $authorizedLocationIds,
        array $capabilityKeys,
        public readonly ?int $actorUserId = null,
        public readonly ?int $audienceUserId = null,
        public readonly ?int $viewAsSessionId = null,
        public readonly ?int $viewAsTargetBusinessId = null,
        public readonly ?int $businessLocationId = null,
    ) {
        if ($scope->requiresTenancy() && ($workspaceId === null || $businessId === null)) {
            throw new InvalidArgumentException($scope->value . ' scope requires both a Workspace and a Business (Contract 19 §8).');
        }

        if (! $scope->requiresTenancy() && ($workspaceId !== null || $businessId !== null)) {
            throw new InvalidArgumentException('platform scope carries no tenant: a fabricated Workspace or Business is forbidden (Contract 19 R-19).');
        }

        if ($actorUserId !== null && $audienceUserId !== null) {
            throw new InvalidArgumentException('an envelope is either human-initiated (actor) or a background generation (audience), never both (Contract 19 §5.9b).');
        }

        $this->authorizedLocationIds = AuthorizationScopeFingerprint::normaliseLocationIds($authorizedLocationIds);
        $this->capabilityKeys = AuthorizationScopeFingerprint::normaliseCapabilityKeys($capabilityKeys);

        $this->authorizationScopeFingerprint = AuthorizationScopeFingerprint::compute(
            $scope,
            $workspaceId,
            $businessId,
            $this->authorizedLocationIds,
            $this->capabilityKeys,
            $viewAsTargetBusinessId,
            $businessLocationId,
        );
    }

    /** True when this envelope was assembled for a background generation rather than a human. */
    public function isBackgroundAudience(): bool
    {
        return $this->actorUserId === null && $this->audienceUserId !== null;
    }

    /** True when an Agency user is really acting while viewing a client (§6.4). */
    public function isViewingAsClient(): bool
    {
        return $this->viewAsSessionId !== null;
    }

    /**
     * R-2 — narrowing is allowed, widening is not. Pinning a Location produces
     * a new envelope with a new fingerprint; the pin must be one of the
     * Locations this envelope already authorises.
     */
    public function pinnedTo(int $businessLocationId): self
    {
        if (! in_array($businessLocationId, $this->authorizedLocationIds, true)) {
            throw new InvalidArgumentException('an envelope can only be pinned to a Location it already authorises (Contract 19 R-2).');
        }

        return new self(
            $this->scope,
            $this->workspaceId,
            $this->businessId,
            $this->authorizedLocationIds,
            $this->capabilityKeys,
            $this->actorUserId,
            $this->audienceUserId,
            $this->viewAsSessionId,
            $this->viewAsTargetBusinessId,
            $businessLocationId,
        );
    }

    /**
     * The columns this envelope contributes to a `coo_insights` row. The
     * fingerprint is included because it is stored for audit and for the
     * UNIQUE key — never because a reader may trust the stored copy (R-22).
     *
     * @return array<string, mixed>
     */
    public function insightColumns(): array
    {
        return [
            'scope' => $this->scope->value,
            'workspace_id' => $this->workspaceId,
            'business_id' => $this->businessId,
            'business_location_id' => $this->businessLocationId,
            'authorization_scope_fingerprint' => $this->authorizationScopeFingerprint,
            'actor_user_id' => $this->actorUserId,
            'audience_user_id' => $this->audienceUserId,
            'view_as_session_id' => $this->viewAsSessionId,
        ];
    }
}
