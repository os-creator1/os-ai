<?php

namespace App\Http\Controllers\Customer\Business;

use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Customer\Business\ConfigureAutoRechargeRequest;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Repositories\Contracts\WorkspaceRepository;
use App\Library\Workspace\WorkspaceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * RFC-005 M3 contract §18 — auto-recharge configuration. Gated by the
 * identical charge-causing-action consent as top-up initiation (§17),
 * enforced inside UsageWalletManager::configureAutoRecharge() itself —
 * newly enabling auto-recharge authorizes a future series of off-session
 * charges, so it requires the actual payer's own action, never a platform
 * administrator's (M3 contract §15).
 */
class UsageBillingAutoRechargeController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly UsageWalletManager $walletManager,
    ) {
    }

    public function configure(ConfigureAutoRechargeRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        try {
            $this->walletManager->configureAutoRecharge(
                $business,
                (bool) $request->validated('auto_recharge_enabled'),
                $request->validated('auto_recharge_threshold_micro') !== null ? (string) $request->validated('auto_recharge_threshold_micro') : null,
                $request->validated('auto_recharge_amount_micro') !== null ? (string) $request->validated('auto_recharge_amount_micro') : null,
                $request->validated('monthly_recharge_cap_micro') !== null ? (string) $request->validated('monthly_recharge_cap_micro') : null,
                $actorUserId,
            );
        } catch (UnauthorizedUsageBillingManagementException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to configure auto-recharge for this Business.');
        } catch (UsageWalletNotFoundException) {
            return redirect()->back()->with('flash_error', 'Usage tracking has not been set up for this Business yet.');
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', 'Auto-recharge settings updated.');
    }

    private function resolveViewableBusiness(string $workspaceUid, string $businessUid, int $userId): Business
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness($userId, $business)) {
            abort(404);
        }

        return $business;
    }
}
