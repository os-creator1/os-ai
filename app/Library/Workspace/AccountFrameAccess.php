<?php

namespace App\Library\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipRepository;

/**
 * The ONE rule for "may this actor stand in this account's own frame?".
 *
 * It was already enforced by WorkspaceController's read-only account
 * overview, which answers 404 to everyone else: the owner, or an active
 * membership whose Business access scope is `all`. A selected-scope member
 * works inside the Businesses assigned to them and never at the account
 * level (Slice 1B Correction Round 1).
 *
 * The rule lives here, in one place, because the context switcher and
 * SwitchAccountAction must answer exactly the same question the page does.
 * Two copies of an authorization rule is how the copies start to disagree.
 *
 * This is not a second tenancy algorithm: Business access stays
 * WorkspaceManager::userCanAccessBusiness()'s decision, untouched.
 */
final class AccountFrameAccess
{
    public function __construct(
        private readonly WorkspaceMembershipRepository $membershipRepository,
    ) {
    }

    /**
     * Re-read from persistence, for a caller holding only a uid-resolved
     * Workspace: never from a presentation DTO.
     */
    public function allows(Workspace $workspace, int $userId): bool
    {
        if ((int) $workspace->owner_user_id === $userId) {
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        return $membership !== null && self::membershipAllows($membership);
    }

    /**
     * The predicate half, for a caller that already holds the membership row
     * (WorkspaceController resolves it to label the actor's role).
     */
    public static function membershipAllows(WorkspaceMembership $membership): bool
    {
        return (bool) $membership->is_active
            && $membership->business_access_scope === WorkspaceBusinessAccessScope::All;
    }
}
