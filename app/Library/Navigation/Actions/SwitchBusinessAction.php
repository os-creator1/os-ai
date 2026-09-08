<?php

namespace App\Library\Navigation\Actions;

use App\Enums\Business\BusinessStatus;
use App\Library\Navigation\CustomerContextPreference;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POST customer.context.business.switch — the server-authorized Business
 * switch (Slice 1B brief §5). The submitted uids are looked up inside the
 * named Workspace only, authorized through the canonical
 * WorkspaceManager::userCanAccessBusiness(), and refused with 404 when the
 * pair is forged, cross-Workspace, inactive or otherwise unreachable
 * (contract §5.4 existence-disclosure rule). The remembered preference is
 * written only after that check; the destination is always the Business
 * Home, which makes the outcome deterministic.
 */
final class SwitchBusinessAction
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly CustomerContextPreference $preference,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'workspace' => ['required', 'string', 'max:64'],
            'business' => ['required', 'string', 'max:64'],
        ]);

        $workspace = $this->workspaceRepository->findByUid($validated['workspace']);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)
            ->firstWhere('uid', $validated['business']);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
            abort(404);
        }

        if ($business->status !== BusinessStatus::Active) {
            abort(404);
        }

        $this->preference->remember($workspace->uid, $business->uid);

        return redirect()->route('user.home');
    }
}
