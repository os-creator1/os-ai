<?php

namespace App\Library\Navigation\Actions;

use App\Enums\Business\BusinessStatus;
use App\Library\Navigation\CurrentLocation;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POST customer.context.location.switch — Blueprint §7's Location switcher.
 *
 * Re-authorizes on the server like SwitchBusinessAction: the Business must be
 * one the actor can access, and the chosen Location must be one of
 * CurrentLocation::options() — i.e. already granted. Anything else (a foreign
 * uid, an ungranted Location, an archived one) is the same 404, so a forged
 * value is never a way in. An empty `location` means "all my Locations".
 */
final class SwitchLocationAction
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly CurrentLocation $currentLocation,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'workspace' => ['required', 'string', 'max:64'],
            'business' => ['required', 'string', 'max:64'],
            'location' => ['nullable', 'string', 'max:64'],
        ]);

        $workspace = $this->workspaceRepository->findByUid($validated['workspace']);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $validated['business']);
        $userId = (int) Auth::id();

        if ($business === null
            || $business->status !== BusinessStatus::Active
            || ! $this->workspaceManager->userCanAccessBusiness($userId, $business)) {
            abort(404);
        }

        $location = null;

        if (($validated['location'] ?? '') !== '') {
            $location = $this->currentLocation->options($business, $userId)->firstWhere('uid', $validated['location']);

            if ($location === null) {
                abort(404);
            }
        }

        $this->currentLocation->select($business, $location);

        $previous = url()->previous();

        return str_starts_with($previous, url('/')) ? redirect()->to($previous) : redirect()->route('user.home');
    }
}
