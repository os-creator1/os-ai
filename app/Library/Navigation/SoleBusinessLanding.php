<?php

namespace App\Library\Navigation;

use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;

/**
 * Where someone with Business access but no account frame (a selected-scope
 * team member) lands: their one authorized, active Business Home.
 *
 * It only ever REMEMBERS a navigation preference, and only for a Business the
 * canonical WorkspaceManager::userCanAccessBusiness() allows right now; it
 * grants nothing and never guesses between several. When the choice is
 * ambiguous it remembers nothing and the normal context resolver decides.
 */
final class SoleBusinessLanding
{
    public function __construct(
        private readonly CustomerContextSnapshot $snapshot,
        private readonly CustomerContextPreference $preference,
        private readonly WorkspaceManager $workspaceManager,
    ) {
    }

    /**
     * @param string|null $workspaceUid limit the choice to this account (the one just joined)
     *
     * @return bool true when exactly one authorized, active Business exists and is now remembered
     */
    public function rememberSole(int $userId, ?string $workspaceUid = null): bool
    {
        $found = [];

        foreach ($this->snapshot->forUser($userId) as $workspace) {
            if (! $workspace->isActive || ($workspaceUid !== null && $workspace->uid !== $workspaceUid)) {
                continue;
            }

            foreach ($workspace->selectableBusinesses() as $candidate) {
                $business = new Business();
                $business->id = $candidate->id;

                if ($this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                    $found[] = [$workspace->uid, $candidate->uid];
                }
            }
        }

        if (count($found) !== 1) {
            // Several Businesses in the joined account: pin only the (visible, active) account so the normal
            // Business selection runs inside it. No Business is guessed.
            if ($workspaceUid !== null && count($found) > 1) {
                $this->preference->remember($workspaceUid, null);
            }

            return false;
        }

        $this->preference->remember($found[0][0], $found[0][1]);

        return true;
    }

    /** True when the person has an authorized Business to open but no account-frame row to list. */
    public function hasAnyBusiness(int $userId): bool
    {
        foreach ($this->snapshot->forUser($userId) as $workspace) {
            if ($workspace->isActive && $workspace->selectableBusinesses() !== []) {
                return true;
            }
        }

        return false;
    }
}
