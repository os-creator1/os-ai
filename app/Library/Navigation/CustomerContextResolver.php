<?php

namespace App\Library\Navigation;

use App\Library\ViewAs\ViewAsContext;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The ONE canonical customer context resolution (Slice 1B brief §3).
 *
 * Flow, in order:
 *  1. the authenticated user;
 *  2. the visible Workspaces (CustomerContextSnapshot, one statement);
 *  3. a Workspace: from an active View-as session, from the route's
 *     `workspaceUid`, from the remembered preference, or the only one;
 *  4. a Business inside that Workspace: from View-as, from the route's
 *     `businessUid` (the owning controller authorizes it on this very
 *     request), from the remembered preference (re-authorized here through
 *     WorkspaceManager::userCanAccessBusiness()), or the only selectable
 *     one (also re-authorized);
 *  5. cross-Workspace combinations, inactive Workspaces, inactive
 *     memberships, non-active Businesses and inaccessible Businesses are
 *     never selected;
 *  6. a DELIBERATE account-frame choice (the context switcher's account
 *     option, remembered by CustomerContextPreference::rememberAccount()) is
 *     honoured until the actor picks a Business — re-authorized every request
 *     against the same account-frame rule the account page enforces, and
 *     dropped the moment it stops holding;
 *  7. when several authorized choices remain, NOTHING is chosen — the
 *     Account frame asks for an explicit selection. No "first row" wins.
 *
 * There is no second tenancy algorithm here: the authorization decision is
 * always WorkspaceManager's. The snapshot only decides what is LISTED.
 */
final class CustomerContextResolver
{
    public function __construct(
        private readonly CustomerContextSnapshot $snapshot,
        private readonly CustomerContextPreference $preference,
        private readonly WorkspaceManager $workspaceManager,
    ) {
    }

    public function resolve(User $user, Request $request, ?ViewAsContext $viewAs = null): CustomerContext
    {
        $userId = (int) $user->id;
        $workspaces = $this->snapshot->forUser($userId);
        $preferenceCleared = false;

        $routeWorkspaceUid = $this->routeParameter($request, 'workspaceUid');
        $routeBusinessUid = $this->routeParameter($request, 'businessUid');
        $remembered = $this->preference->get();

        // 1. View-as narrows everything to the viewed Business (§5.5). The
        //    session is only honoured while the actor can still reach that
        //    Business through the canonical check.
        if ($viewAs !== null) {
            $workspace = $this->findWorkspace($workspaces, $viewAs->workspaceUid);
            $business = $workspace?->findBusinessByUid($viewAs->businessUid);

            if ($workspace !== null && $business !== null && $business->isSelectable()
                && $this->canonicallyAccessible($userId, $business)) {
                return $this->context($userId, $workspaces, $workspace, $business, ContextSource::ViewAs, $viewAs, false);
            }
        }

        // 2. Route-derived pair: the controller owning this route resolves
        //    and enforces it; the shell merely displays it and remembers it.
        if ($routeWorkspaceUid !== null && $routeBusinessUid !== null) {
            $workspace = $this->findWorkspace($workspaces, $routeWorkspaceUid);
            $business = $workspace?->findBusinessByUid($routeBusinessUid);

            if ($workspace !== null && $business !== null && $business->isSelectable()) {
                $this->preference->remember($workspace->uid, $business->uid);

                return $this->context($userId, $workspaces, $workspace, $business, ContextSource::Route, null, false);
            }

            if ($workspace !== null && $business !== null) {
                // Reachable but not active (draft / inactive): the page may
                // still render its own explanation, but the shell must not
                // pretend some OTHER Business is the current one.
                return $this->context($userId, $workspaces, $workspace, null, ContextSource::None, null, false);
            }
        }

        // 3. Workspace selection: route, then preference, then the only one.
        $selectedWorkspace = null;

        if ($routeWorkspaceUid !== null) {
            $selectedWorkspace = $this->findWorkspace($workspaces, $routeWorkspaceUid);

            if ($selectedWorkspace !== null && $remembered['workspace'] !== $selectedWorkspace->uid) {
                $this->preference->remember($selectedWorkspace->uid, null);
                $remembered = $this->preference->get();
            }
        }

        if ($selectedWorkspace === null && $remembered['workspace'] !== null) {
            $selectedWorkspace = $this->findWorkspace($workspaces, $remembered['workspace']);

            if ($selectedWorkspace === null) {
                $this->preference->forget();
                $remembered = ['workspace' => null, 'business' => null, 'accountFrame' => false];
                $preferenceCleared = true;
            }
        }

        if ($selectedWorkspace === null && count($workspaces) === 1) {
            $selectedWorkspace = $workspaces[0];
        }

        // 4. Business selection inside that Workspace: remembered preference
        //    (re-authorized), then the only selectable one (re-authorized).
        if ($selectedWorkspace !== null && $remembered['business'] !== null && $remembered['workspace'] === $selectedWorkspace->uid) {
            $business = $selectedWorkspace->findBusinessByUid($remembered['business']);

            if ($business !== null && $business->isSelectable() && $this->canonicallyAccessible($userId, $business)) {
                return $this->context($userId, $workspaces, $selectedWorkspace, $business, ContextSource::Preference, null, $preferenceCleared);
            }

            // Stale or revoked: never silently substitute another Business.
            $this->preference->forgetBusiness();
            $preferenceCleared = true;
        }

        // 4a. A DELIBERATE account-frame choice (the context switcher's account
        //     option) is honoured until the actor picks a Business, so the
        //     sole-Business rule below does not undo it on the next request.
        //     Re-authorized here like every other remembered preference: the
        //     account must still be visible (it was resolved from the snapshot
        //     above), active, and one whose own frame this actor may stand in.
        //     A narrowed membership therefore drops the intent instead of
        //     pinning the actor to a frame they no longer reach.
        if ($remembered['accountFrame']) {
            if ($selectedWorkspace !== null
                && $remembered['workspace'] === $selectedWorkspace->uid
                && $selectedWorkspace->isActive
                && $selectedWorkspace->seesAccountFrame()) {
                return $this->context($userId, $workspaces, $selectedWorkspace, null, ContextSource::AccountPreference, null, $preferenceCleared);
            }

            $this->preference->forgetBusiness();
            $preferenceCleared = true;
        }

        $selectable = $selectedWorkspace !== null
            ? $selectedWorkspace->selectableBusinesses()
            : $this->allSelectable($workspaces);

        if (count($selectable) === 1 && $this->canonicallyAccessible($userId, $selectable[0])) {
            $sole = $selectable[0];
            $workspace = $selectedWorkspace ?? $this->findWorkspace($workspaces, $sole->workspaceUid);

            return $this->context($userId, $workspaces, $workspace, $sole, ContextSource::Sole, null, $preferenceCleared);
        }

        // 5. Ambiguous or empty: Account frame, explicit selection required.
        return $this->context($userId, $workspaces, $selectedWorkspace, null, ContextSource::None, null, $preferenceCleared);
    }

    /**
     * The canonical RFC-003 §14.1 decision, re-run for every selection the
     * shell makes on its own (preference or sole candidate). A lightweight
     * Business carrying only its id is enough: userCanAccessBusiness()
     * re-reads the persisted row itself and never trusts the caller's model.
     */
    private function canonicallyAccessible(int $userId, BusinessCandidate $candidate): bool
    {
        $business = new Business();
        $business->id = $candidate->id;

        return $this->workspaceManager->userCanAccessBusiness($userId, $business);
    }

    /**
     * @param  array<int, WorkspaceCandidate>  $workspaces
     */
    private function findWorkspace(array $workspaces, string $uid): ?WorkspaceCandidate
    {
        foreach ($workspaces as $workspace) {
            if ($workspace->uid === $uid) {
                return $workspace;
            }
        }

        return null;
    }

    /**
     * @param  array<int, WorkspaceCandidate>  $workspaces
     * @return array<int, BusinessCandidate>
     */
    private function allSelectable(array $workspaces): array
    {
        $result = [];

        foreach ($workspaces as $workspace) {
            foreach ($workspace->selectableBusinesses() as $business) {
                $result[] = $business;
            }
        }

        return $result;
    }

    private function routeParameter(Request $request, string $name): ?string
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        $value = $route->parameter($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, WorkspaceCandidate>  $workspaces
     */
    private function context(
        int $userId,
        array $workspaces,
        ?WorkspaceCandidate $workspace,
        ?BusinessCandidate $business,
        ContextSource $source,
        ?ViewAsContext $viewAs,
        bool $preferenceCleared,
    ): CustomerContext {
        return new CustomerContext(
            userId: $userId,
            frame: $business !== null ? CustomerFrame::Business : CustomerFrame::Account,
            workspaces: $workspaces,
            selectedWorkspace: $workspace,
            selectedBusiness: $business,
            source: $source,
            viewAs: $viewAs,
            preferenceCleared: $preferenceCleared,
        );
    }
}
