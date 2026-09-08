<?php

namespace App\Library\Navigation;

use App\Enums\Business\BusinessStatus;

/**
 * One Business as it appears to the navigation shell: identity for
 * display, plus whether the actor may reach it and whether it is active.
 *
 * `accessible` mirrors RFC-003 §14.1 for PRESENTATION ONLY (contract §18
 * S-3: navigation visibility is never an authorization mechanism). Every
 * route and every switch action re-authorizes through
 * WorkspaceManager::userCanAccessBusiness(); nothing is ever granted from
 * this flag.
 */
final class BusinessCandidate
{
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly string $name,
        public readonly string $status,
        public readonly int $customerId,
        public readonly bool $isPrimary,
        public readonly bool $accessible,
        public readonly string $workspaceUid,
        public readonly string $workspaceName,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === BusinessStatus::Active->value;
    }

    /**
     * A Business the actor may enter: reachable AND active. Draft or
     * inactive Businesses are listed for explanation but never selected
     * (Slice 1B brief §5: never switch into archived/inactive/inaccessible
     * Businesses).
     */
    public function isSelectable(): bool
    {
        return $this->accessible && $this->isActive();
    }
}
