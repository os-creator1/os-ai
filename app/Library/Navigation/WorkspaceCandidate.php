<?php

namespace App\Library\Navigation;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;

/**
 * One Workspace the actor can see (WorkspaceRepository::allForUser()
 * semantics: owned, or reached through an active membership), with the
 * actor's relationship to it and the Businesses inside it.
 *
 * Presentation data only — see BusinessCandidate for the authorization
 * boundary statement.
 */
final class WorkspaceCandidate
{
    /**
     * @param  array<int, BusinessCandidate>  $businesses
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly string $name,
        public readonly bool $isActive,
        public readonly int $ownerUserId,
        public readonly bool $isOwner,
        public readonly ?WorkspaceMembershipRole $membershipRole,
        public readonly ?WorkspaceBusinessAccessScope $membershipScope,
        public readonly bool $membershipActive,
        public readonly ?WorkspacePlanTier $tier,
        public readonly ?string $tierDisplayName,
        public readonly array $businesses,
    ) {
    }

    public function isAgency(): bool
    {
        return $this->tier === WorkspacePlanTier::Agency;
    }

    /**
     * Owner-or-active-Admin — the same authority rule WorkspaceManager
     * applies to every Workspace mutation (RFC-003 Milestone 2 §8).
     */
    public function canManage(): bool
    {
        if ($this->isOwner) {
            return true;
        }

        return $this->membershipActive && $this->membershipRole === WorkspaceMembershipRole::Admin;
    }

    /**
     * Whether the actor may stand in this account's own frame — the account
     * home, the account page and the account-level menu.
     *
     * The rule is App\Library\Workspace\AccountFrameAccess's, mirrored here
     * for PRESENTATION from data the snapshot already resolved: the owner, or
     * an active membership whose Business access scope is `all`. A
     * selected-scope member works inside assigned Businesses only, which is
     * also why an agency account's name is never disclosed to a client who is
     * one (contract §5.4, S-6).
     *
     * Presentation only, like $accessible on BusinessCandidate:
     * SwitchAccountAction re-reads the Workspace and re-applies the same rule
     * through AccountFrameAccess on every switch, and the account page
     * independently answers 404. Nothing is granted from this flag.
     */
    public function seesAccountFrame(): bool
    {
        if ($this->isOwner) {
            return true;
        }

        return $this->membershipActive
            && $this->membershipScope === WorkspaceBusinessAccessScope::All;
    }

    /**
     * @return array<int, BusinessCandidate>
     */
    public function selectableBusinesses(): array
    {
        return array_values(array_filter($this->businesses, fn (BusinessCandidate $b) => $b->isSelectable()));
    }

    /**
     * @return array<int, BusinessCandidate>
     */
    public function accessibleBusinesses(): array
    {
        return array_values(array_filter($this->businesses, fn (BusinessCandidate $b) => $b->accessible));
    }

    public function findBusinessByUid(string $uid): ?BusinessCandidate
    {
        foreach ($this->businesses as $business) {
            if ($business->uid === $uid) {
                return $business;
            }
        }

        return null;
    }
}
