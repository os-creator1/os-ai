<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Library\PlatformOwner\PlatformUserActions;
use App\Library\PlatformOwner\PlatformUserDirectory;
use App\Models\PlatformAdminAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

/**
 * Platform Owner V1 final — Users: find a customer account, see its state and
 * Workspace memberships, and perform the legitimate support actions. Thin:
 * reads through PlatformUserDirectory, writes through PlatformUserActions.
 * No password is ever shown or set here.
 */
class PlatformUsersController extends Controller
{
    public function __construct(
        private readonly PlatformUserDirectory $directory,
        private readonly PlatformUserActions $actions,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('view customer');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'in:active,suspended,unverified'],
        ]);

        return view('admin.platform-users.index', [
            'users' => $this->directory->paginate($filters),
            'filters' => $filters,
            'breadcrumbs' => [['link' => url(config('app.admin_path') . '/platform-owner'), 'name' => 'Home'], ['name' => 'Users']],
        ]);
    }

    public function show(string $uid): View
    {
        $this->authorize('view customer');

        $user = $this->directory->find($uid) ?? abort(404);

        return view('admin.platform-users.show', [
            'user' => $user,
            'history' => PlatformAdminAction::query()->with('actor:id,first_name,last_name,email')
                ->where('subject_type', 'user')->where('subject_ref', $user->uid)
                ->orderByDesc('id')->limit(15)->get(),
            'breadcrumbs' => [
                ['link' => url(config('app.admin_path') . '/platform-owner'), 'name' => 'Home'],
                ['link' => route('admin.platform-users.index'), 'name' => 'Users'],
                ['name' => $user->email],
            ],
        ]);
    }

    public function act(Request $request, string $uid, string $action): RedirectResponse
    {
        $this->authorize('edit customer');

        $user = $this->directory->find($uid) ?? abort(404);
        $actor = (int) Auth::id();
        $reason = trim((string) $request->input('reason', ''));

        if (in_array($action, ['suspend', 'reactivate'], true) && mb_strlen($reason) < 3) {
            return back()->withErrors(['reason' => __('Say why (at least 3 characters).')]);
        }

        try {
            $message = match ($action) {
                'password-reset' => $this->run(fn () => $this->actions->sendPasswordReset($actor, $user), __('Password reset link sent to :e.', ['e' => $user->email])),
                'resend-verification' => $this->run(fn () => $this->actions->resendVerification($actor, $user), __('Verification email sent to :e.', ['e' => $user->email])),
                'suspend' => $this->run(fn () => $this->actions->suspend($actor, $user, $reason), __('Account suspended and signed out everywhere.')),
                'reactivate' => $this->run(fn () => $this->actions->reactivate($actor, $user, $reason), __('Account reactivated.')),
                'revoke-sessions' => $this->run(fn () => $this->actions->revokeSessions($actor, $user), __('Signed out of all sessions.')),
                default => abort(404),
            };
        } catch (RuntimeException $e) {
            return back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => $message]);
    }

    private function run(callable $do, string $message): string
    {
        $do();

        return $message;
    }
}
