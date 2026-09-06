<?php

namespace App\Http\Controllers\Customer;

use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * B4 Business Automations — reduced to the bare `/automations` entry
 * chooser only (contract §15.2). Every legacy per-record and mutating
 * action that lived here (search, create, say-happy-birthday, show,
 * enable, disable, delete, batch_action, reports, sendNow, getTags) is
 * removed outright — none survives as a compatibility shim, because each
 * one resolved an Automation by uid with no tenant constraint (the
 * confirmed cross-tenant IDOR class, §12) or bypassed idempotency entirely
 * (sendNow, §13).
 *
 * The actual product surface is Business\AutomationsController at
 * customer.workspaces.businesses.automations.*. This entry follows the
 * B1 OutreachController::entry() convention verbatim: 0 accessible
 * Businesses → empty state; exactly 1 → redirect; more → chooser. It
 * never guesses or infers a primary Business.
 */
class AutomationsController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
    ) {
    }

    public function entry(): View|Factory|Application|RedirectResponse
    {
        $this->authorize('automations');

        $accessible = $this->accessibleBusinesses();

        if (count($accessible) === 0) {
            return view('customer.Automations.entry', ['accessible' => []]);
        }

        if (count($accessible) === 1) {
            [$workspace, $business] = $accessible[0];

            return redirect()->route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]);
        }

        return view('customer.Automations.entry', ['accessible' => $accessible]);
    }

    /**
     * @return array<int, array{0: \App\Models\Workspace, 1: \App\Models\Business}>
     */
    private function accessibleBusinesses(): array
    {
        $userId = (int) Auth::id();
        $accessible = [];

        foreach ($this->workspaceRepository->allForUser($userId) as $workspace) {
            foreach ($this->workspaceRepository->businessesForWorkspace($workspace) as $business) {
                if ($this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                    $accessible[] = [$workspace, $business];
                }
            }
        }

        return $accessible;
    }
}
