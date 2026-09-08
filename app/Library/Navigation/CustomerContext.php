<?php

namespace App\Library\Navigation;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\ViewAs\ViewAsContext;

/**
 * The resolved account context for one request (contract §5, §8.1;
 * Slice 1B brief §3). Immutable; built only by CustomerContextResolver.
 *
 * Vocabulary (contract §4): the word "Workspace" is Agency-only. For
 * Core/Growth the shell says "Business"; for Agency it says "Client
 * account" and may name the agency Workspace as the account frame.
 */
final class CustomerContext
{
    /**
     * @param  array<int, WorkspaceCandidate>  $workspaces
     */
    public function __construct(
        public readonly int $userId,
        public readonly CustomerFrame $frame,
        public readonly array $workspaces,
        public readonly ?WorkspaceCandidate $selectedWorkspace,
        public readonly ?BusinessCandidate $selectedBusiness,
        public readonly ContextSource $source,
        public readonly ?ViewAsContext $viewAs,
        public readonly bool $preferenceCleared,
    ) {
    }

    public function isBusinessFrame(): bool
    {
        return $this->frame === CustomerFrame::Business && $this->selectedBusiness !== null;
    }

    public function isViewingAsClient(): bool
    {
        return $this->viewAs !== null;
    }

    /**
     * Every Business the actor may enter, across every visible Workspace.
     *
     * @return array<int, BusinessCandidate>
     */
    public function selectableBusinesses(): array
    {
        $result = [];

        foreach ($this->workspaces as $workspace) {
            foreach ($workspace->selectableBusinesses() as $business) {
                $result[] = $business;
            }
        }

        return $result;
    }

    /**
     * Businesses the actor can see but not enter (draft / inactive), for
     * honest empty and locked states.
     *
     * @return array<int, BusinessCandidate>
     */
    public function accessibleButNotActiveBusinesses(): array
    {
        $result = [];

        foreach ($this->workspaces as $workspace) {
            foreach ($workspace->accessibleBusinesses() as $business) {
                if (! $business->isActive()) {
                    $result[] = $business;
                }
            }
        }

        return $result;
    }

    public function selectableBusinessCount(): int
    {
        return count($this->selectableBusinesses());
    }

    public function hasMultipleWorkspaces(): bool
    {
        return count($this->workspaces) > 1;
    }

    /**
     * The Workspace whose tier decides the vocabulary: the selected one,
     * else the only one. With several unselected Workspaces the shell
     * stays neutral (Business wording) until one is chosen.
     */
    public function frameWorkspace(): ?WorkspaceCandidate
    {
        if ($this->selectedWorkspace !== null) {
            return $this->selectedWorkspace;
        }

        return count($this->workspaces) === 1 ? $this->workspaces[0] : null;
    }

    public function isAgency(): bool
    {
        $workspace = $this->frameWorkspace();

        return $workspace !== null && $workspace->isAgency();
    }

    /**
     * Contract §5.3 — Workspace vocabulary is invisible for Core/Growth.
     */
    public function showsWorkspaceVocabulary(): bool
    {
        return $this->isAgency();
    }

    /**
     * True when the frame Workspace is on the Core or Growth tier: those
     * customers must read "account", never "Workspace", on the account
     * pages (contract §5.3, T-CTX-2). Agency and not-yet-assigned accounts
     * keep the established Workspace wording.
     */
    public function usesBusinessVocabulary(): bool
    {
        $workspace = $this->frameWorkspace();

        return $workspace !== null
            && in_array($workspace->tier, [WorkspacePlanTier::Core, WorkspacePlanTier::Growth], true);
    }

    public function businessNoun(): string
    {
        return $this->isAgency() ? 'Client account' : 'Business';
    }

    public function businessesNoun(): string
    {
        return $this->isAgency() ? 'Client accounts' : 'Businesses';
    }

    /**
     * Owner-or-active-Admin of the frame Workspace — the only actors who
     * see Team/Account settings and agency controls (contract §6, §9.3).
     */
    public function canManageWorkspace(): bool
    {
        $workspace = $this->frameWorkspace();

        return $workspace !== null && $workspace->canManage();
    }

    /**
     * Usage & billing is reachable for the Business's own customer and for
     * Workspace owner/admins; restricted staff never see billing (§9.3 #6).
     */
    public function canManageBilling(): bool
    {
        if ($this->selectedBusiness === null) {
            return false;
        }

        if ($this->selectedBusiness->customerId === $this->userId) {
            return true;
        }

        return $this->canManageWorkspace();
    }

    /**
     * Contract §5.5 entry rule: Agency owner/admin only, never while
     * already viewing.
     */
    public function canViewAsClient(): bool
    {
        return $this->isAgency() && $this->canManageWorkspace() && $this->viewAs === null;
    }

    /**
     * Contract §9.2 — the switcher renders only when the actor can reach
     * more than one Business (or must still choose one).
     */
    public function showsSwitcher(): bool
    {
        if ($this->viewAs !== null) {
            return false;
        }

        return $this->selectableBusinessCount() > 1;
    }

    public function requiresBusinessSelection(): bool
    {
        return $this->selectedBusiness === null && $this->selectableBusinessCount() > 1;
    }

    public function requiresWorkspaceSelection(): bool
    {
        return $this->selectedWorkspace === null && $this->hasMultipleWorkspaces();
    }

    /**
     * Human label for the header: the selected Business, else the agency
     * account, else an honest placeholder.
     */
    public function headerLabel(): string
    {
        if ($this->selectedBusiness !== null) {
            return $this->selectedBusiness->name;
        }

        if ($this->isAgency() && $this->frameWorkspace() !== null) {
            return $this->frameWorkspace()->name;
        }

        if ($this->requiresBusinessSelection()) {
            return 'Choose a ' . strtolower($this->businessNoun());
        }

        return 'No ' . strtolower($this->businessNoun()) . ' yet';
    }
}
