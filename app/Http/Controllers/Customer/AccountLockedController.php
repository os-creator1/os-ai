<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Navigation\CustomerShellComposer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Chat F — Customer Account Access Gate: the standalone locked-account
 * screen CustomerAccountAccessGate redirects a locked customer to.
 *
 * Re-resolves the SAME decision the middleware already saw (via the same
 * CustomerAccountAccessResolver, over the same persisted state) rather than
 * carrying it through the redirect — cheap, avoids any session-serialization
 * concern, and self-corrects: a customer who reaches this page after their
 * account was restored is sent straight back to the dashboard rather than
 * shown a stale locked screen (also what keeps this page from ever
 * redirecting to itself — it only ever redirects AWAY, to the dashboard,
 * and only when there is genuinely nothing to show here).
 */
class AccountLockedController extends Controller
{
    public function __construct(
        private readonly CustomerShellComposer $shell,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly CustomerAccountAccessResolver $resolver,
    ) {
    }

    public function show(): View|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $workspace = $this->currentWorkspace($user);
        $decision = $this->resolver->resolve($workspace);

        if (! $decision->isLocked()) {
            return redirect()->route('user.home');
        }

        return view('customer.account-locked', [
            'decision' => $decision,
            'businessName' => $this->businessName($workspace),
            'planName' => $this->planName($workspace),
            'planPrice' => $this->planPrice($workspace),
            'recoveryUrl' => $decision->recoveryRouteName !== null && $workspace !== null
                ? route($decision->recoveryRouteName, [$workspace->uid])
                : null,
            'pageConfigs' => ['pageHeader' => false],
        ]);
    }

    private function currentWorkspace(User $user): ?Workspace
    {
        $frame = $this->shell->currentContext($user)->frameWorkspace();

        if ($frame === null) {
            return null;
        }

        return $this->workspaceRepository->findByUid($frame->uid);
    }

    /**
     * Only what is already truthfully known and safe to show while locked —
     * never invented. The Workspace's own name stands for the account/
     * Business name here: Core/Growth name the Workspace after the
     * customer's one Business, and this screen has no separate Business
     * context of its own to read from.
     */
    private function businessName(?Workspace $workspace): ?string
    {
        return $workspace?->name;
    }

    private function planName(?Workspace $workspace): ?string
    {
        if ($workspace === null) {
            return null;
        }

        return app(\App\Library\Entitlement\EntitlementManager::class)
            ->getWorkspaceEntitlementSummary($workspace)
            ->tierDisplayName;
    }

    /**
     * Reuses WorkspacePlanPresenter's own truthful billing-line derivation
     * (catalog price + currency, or "Complimentary", or nothing when the
     * catalog carries no price) rather than re-deriving it here.
     */
    private function planPrice(?Workspace $workspace): ?string
    {
        if ($workspace === null) {
            return null;
        }

        $presented = app(\App\Library\Entitlement\WorkspacePlanPresenter::class)->present($workspace);
        $billing = $presented['billing'][0] ?? null;

        return $billing['value'] ?? null;
    }
}
