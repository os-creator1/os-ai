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

        $enabled = (bool) $request->validated('auto_recharge_enabled');

        try {
            $this->walletManager->configureAutoRecharge(
                $business,
                $enabled,
                $request->validated('auto_recharge_threshold_micro') !== null ? (string) $request->validated('auto_recharge_threshold_micro') : null,
                $request->validated('auto_recharge_amount_micro') !== null ? (string) $request->validated('auto_recharge_amount_micro') : null,
                $request->validated('monthly_recharge_cap_micro') !== null ? (string) $request->validated('monthly_recharge_cap_micro') : null,
                $actorUserId,
            );
        } catch (UnauthorizedUsageBillingManagementException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.not_authorized_auto_recharge'));
        } catch (UsageWalletNotFoundException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.wallet_not_set_up'));
        } catch (\InvalidArgumentException $e) {
            // Customer Experience Slice 5 (T-WALLET-4), Correction Round 1
            // §6.1 — the manager refuses a crafted or incomplete
            // configuration regardless of the request layer: a non-preset
            // amount, a missing threshold, or a missing / too-low / above-
            // maximum monthly ceiling. Its policy code becomes the same
            // customer sentence the request layer would have shown.
            $code = $e->getMessage();
            $message = \Illuminate\Support\Facades\Lang::has('locale.usage_billing.validation.' . $code)
                ? __('locale.usage_billing.validation.' . $code)
                : __('locale.usage_billing.validation.auto_recharge_preset_only');

            return redirect()->back()->withInput()->with('flash_error', $message);
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', $enabled
                ? __('locale.usage_billing.messages.auto_recharge_on')
                : __('locale.usage_billing.messages.auto_recharge_off'));
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
