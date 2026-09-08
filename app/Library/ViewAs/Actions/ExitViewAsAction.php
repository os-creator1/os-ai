<?php

namespace App\Library\ViewAs\Actions;

use App\Library\ViewAs\ViewAsManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POST customer.view-as.exit — the explicit Exit control (contract §5.5
 * "Exit"). Ends the audited session and returns the actor to their own
 * Account frame. It never logs the actor out.
 */
final class ExitViewAsAction
{
    public function __construct(private readonly ViewAsManager $viewAs)
    {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(404);
        }

        $this->viewAs->exit($user);

        return redirect()->route('user.home')->with([
            'status' => 'success',
            'message' => 'You have left the client view and are back in your own account.',
        ]);
    }
}
