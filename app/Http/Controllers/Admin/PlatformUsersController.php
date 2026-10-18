<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\PlatformOwner\AccountClosureState;
use App\Library\PlatformOwner\AccountDeletion\AccountClosureRefused;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountAssessor;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountClosureManager;
use App\Library\PlatformOwner\PlatformUserActions;
use App\Library\PlatformOwner\PlatformUserDirectory;
use App\Models\PlatformAccountClosure;
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
        private readonly PlatformAccountAssessor $assessor,
        private readonly PlatformAccountClosureManager $closures,
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

        $user = $this->directory->find($uid) ?? $this->goneOr404($uid);

        return view('admin.platform-users.show', [
            'user' => $user,
            'account' => $this->assessor->assess($user),
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

    /**
     * The deletion confirmation page: the real impact, computed server-side,
     * and the typed-confirmation form.
     */
    public function confirmDelete(string $uid): View|RedirectResponse
    {
        $this->authorize('delete customer');

        $user = $this->directory->find($uid) ?? $this->goneOr404($uid);

        return view('admin.platform-users.confirm-delete', [
            'user' => $user,
            'account' => $this->assessor->assess($user),
            'breadcrumbs' => [
                ['link' => url(config('app.admin_path') . '/platform-owner'), 'name' => 'Home'],
                ['link' => route('admin.platform-users.index'), 'name' => 'Users'],
                ['link' => route('admin.platform-users.show', $user->uid), 'name' => $user->email],
                ['name' => 'Delete account'],
            ],
        ]);
    }

    public function destroy(Request $request, string $uid): RedirectResponse
    {
        $this->authorize('delete customer');

        $data = $request->validate([
            'confirm_email' => ['required', 'string', 'max:191'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        try {
            $closure = $this->closures->request((int) Auth::id(), $uid, $data['confirm_email'], $data['reason'], $data['fingerprint']);
        } catch (AccountClosureRefused $e) {
            return back()->withInput($request->only('reason'))->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return $this->closureResponse($closure);
    }

    public function retryClosure(string $uid): RedirectResponse
    {
        $this->authorize('delete customer');

        $closure = PlatformAccountClosure::query()->where('user_uid', $uid)->firstOrFail();

        return $this->closureResponse($this->closures->resume($closure->uid, (int) Auth::id()));
    }

    private function closureResponse(PlatformAccountClosure $closure): RedirectResponse
    {
        return match ($closure->state) {
            AccountClosureState::Deleted => redirect()->route('admin.platform-users.index')->with([
                'status' => 'success',
                'message' => __(':e and all account-owned data were deleted.', ['e' => $closure->user_email]),
            ]),
            AccountClosureState::Closed => redirect()->route('admin.platform-users.show', $closure->user_uid)->with([
                'status' => 'warning',
                'message' => __(':e is closed and can no longer be used. Protected financial and provider records are retained, so the account was not erased.', ['e' => $closure->user_email]),
            ]),
            AccountClosureState::Closing => redirect()->route('admin.platform-users.show', $closure->user_uid)->with([
                'status' => 'warning',
                'message' => __('Pending cancellation. Access is blocked, but cleanup is not finished: :p', ['p' => implode(' ', $closure->pending_items ?? []) ?: __('a run is in progress.')]),
            ]),
        };
    }

    /**
     * A user that no longer exists: if it was deleted through the lifecycle,
     * say so (the Platform Owner is not shown a bare 404 for work that
     * succeeded); otherwise it is a genuine 404.
     */
    private function goneOr404(string $uid): never
    {
        $closure = PlatformAccountClosure::query()->where('user_uid', $uid)->first();

        if ($closure !== null && $closure->state === AccountClosureState::Deleted) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                redirect()->route('admin.platform-users.index')->with(['status' => 'warning', 'message' => __(':e was already deleted.', ['e' => $closure->user_email])])
            );
        }

        abort(404);
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
