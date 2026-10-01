<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Entitlement\InactiveWorkspacePlanException;
use App\Exceptions\Entitlement\SuspendedWorkspacePlanException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Http\Requests\Admin\Workspace\RestoreWorkspaceAccessRequest;
use App\Library\PlatformOwner\NothingToRestoreException;
use App\Library\PlatformOwner\PlatformOwnerAccountActions;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Platform Owner / Admin V1 — the one account-lifecycle control on the
 * Workspace support page: restore access (EntitlementManager::recoverAccess()).
 *
 * Thin by design. Authority is checked three times, each independent of the
 * others: the route group's EnsureUserIsAdministrator + 'can:access backend',
 * this action's 'manage workspace plans' permission (the existing permission
 * for entitlement writes), and PlatformOwnerAccountActions' own database
 * re-read of `is_admin` by the actor id. The target Workspace is re-read by
 * id inside the service; nothing the request supplies other than the reason
 * and the confirmation reaches the write.
 */
class WorkspaceAccessController extends AdminBaseController
{
    public function __construct(
        private readonly PlatformOwnerAccountActions $actions,
    ) {
    }

    public function restore(RestoreWorkspaceAccessRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        try {
            $this->actions->restoreAccess((int) $workspace->id, (int) Auth::id(), (string) $request->validated('reason'));
        } catch (WorkspaceNotFoundException) {
            abort(404);
        } catch (NothingToRestoreException) {
            return $this->back($workspace, 'po_flash_error', 'There is nothing to restore: no Grace or Locked state is recorded, so no change was made.');
        } catch (InactiveWorkspacePlanException|SuspendedWorkspacePlanException) {
            return $this->back($workspace, 'po_flash_error', 'This plan is inactive or suspended. Change that through Plan & Entitlement; restoring access does not apply.');
        } catch (WorkspacePlanUnassignedException) {
            return $this->back($workspace, 'po_flash_error', 'This Workspace has no plan assignment, so there is nothing to restore.');
        }

        return $this->back($workspace, 'po_flash_success', 'Access restored. The action has been recorded in the audit trail.');
    }

    private function back(Workspace $workspace, string $key, string $message): RedirectResponse
    {
        return redirect()->route('admin.workspaces.show', $workspace)->with($key, $message);
    }
}
