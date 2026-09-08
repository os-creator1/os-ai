<?php

namespace App\Library\ViewAs\Actions;

use App\Library\ViewAs\ViewAsManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POST customer.view-as.start — CSRF-protected entry into View-as-client
 * (contract §5.5 "Entry"). Authorization and the audit row live in
 * ViewAsManager::start(); an unauthorized or unknown pair is a 404.
 */
final class StartViewAsAction
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

        $validated = $request->validate([
            'workspace' => ['required', 'string', 'max:64'],
            'business' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $session = $this->viewAs->start($user, $validated['workspace'], $validated['business'], $validated['reason'] ?? null);

        return redirect()->route('user.home')->with([
            'status' => 'info',
            'message' => 'You are now viewing ' . $session->business?->name . ' as a client. Your own account is unchanged; use Exit client view when you are done.',
        ]);
    }
}
