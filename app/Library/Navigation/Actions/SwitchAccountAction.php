<?php

namespace App\Library\Navigation\Actions;

use App\Library\Navigation\CustomerContextPreference;
use App\Library\Workspace\AccountFrameAccess;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POST customer.context.account.switch — the server-authorized move into an
 * account's own frame, the counterpart of SwitchBusinessAction.
 *
 * The submitted uid is re-resolved from persistence and re-authorized through
 * AccountFrameAccess, the same owner-or-active-scope-all rule the account page
 * enforces before it answers 404. A forged, unknown, inactive or foreign uid is
 * refused with 404, never 403, so the answer discloses nothing about whether
 * that account exists (contract §5.4).
 *
 * Only then is the preference written, and the destination is always the
 * account Home, which makes the outcome deterministic.
 */
final class SwitchAccountAction
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly AccountFrameAccess $accountFrame,
        private readonly CustomerContextPreference $preference,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'workspace' => ['required', 'string', 'max:64'],
        ]);

        $workspace = $this->workspaceRepository->findByUid($validated['workspace']);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        if (! $this->accountFrame->allows($workspace, (int) Auth::id())) {
            abort(404);
        }

        $this->preference->rememberAccount($workspace->uid);

        return redirect()->route('user.home');
    }
}
